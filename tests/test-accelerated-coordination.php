<?php
/** Pure interleaved-worker regressions; models WordPress request-local option caches. */
define( 'ABSPATH', __DIR__ ); define( 'DB_NAME', 'coordination_fixture' ); define( 'MINUTE_IN_SECONDS', 60 );
$db = array(); $caches = array(); $request = 'A'; $hooks = array(); $events = array(); $executed = array(); $clears = 0;
function coordination_check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function get_option( $key, $default = false ) { global $db, $caches, $request; return $caches[$request][$key] ?? ( $caches[$request][$key] = $db[$key] ?? $default ); }
function update_option( $key, $value, ...$args ) { global $db, $caches, $request; $db[$key] = $value; $caches[$request][$key] = $value; return true; }
function add_option( $key, $value, ...$args ) { if ( array_key_exists($key,$GLOBALS['db']) ) { return false; } return update_option($key,$value); }
function delete_option( $key ) { unset($GLOBALS['db'][$key],$GLOBALS['caches'][$GLOBALS['request']][$key]); }
function wp_cache_delete( $key, $group ) { unset($GLOBALS['caches'][$GLOBALS['request']][$key]); }
function wp_cache_get( ...$args ) { return false; }
function wp_cache_set( ...$args ) {}
function add_action( $hook, $callback, ...$args ) { $GLOBALS['hooks'][$hook][]=$callback; }
function do_action( $hook, ...$args ) { if ('rfs_sync_cycle_finished'===$hook) { ++$GLOBALS['clears']; } }
function do_action_ref_array( $hook, $args ) { ($GLOBALS['provider'])( $args[0] ); }
function rfs_is_sync_locked() { return false; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function wp_next_scheduled( $hook, $args ) { return isset($GLOBALS['events'][$hook][json_encode($args)]) ? 1 : false; }
function wp_schedule_single_event( $time, $hook, $args ) { $GLOBALS['events'][$hook][json_encode($args)]=$args; }
function wp_unschedule_hook( $hook ) { unset($GLOBALS['events'][$hook]); }
function as_unschedule_all_actions( ...$args ) {}
function as_get_scheduled_actions( $query, $format ) { coordination_check( 'pending' === $query['status'], 'Running retries must not suppress a new pending retry.' ); return ! empty( $GLOBALS['as_enabled'] ) && ! empty( $GLOBALS['as_pending'] ) ? array(1) : array(); }
function as_schedule_single_action( $time, $hook, $args, $group ) { if ( empty( $GLOBALS['as_enabled'] ) ) { return 0; } $GLOBALS['as_pending']=true; return ++$GLOBALS['as_created']; }
class CoordinationDB {
 public $options='wp_options'; public $available=true; public $owner=null;
 public function prepare($sql,...$args){ return vsprintf($sql,$args); }
 public function get_var($sql){
  if(str_contains($sql,'GET_LOCK')) { if(!$this->available||null!==$this->owner)return '0'; $this->owner=$GLOBALS['request']; return '1'; }
  if(str_contains($sql,'RELEASE_LOCK')) { coordination_check($this->owner===$GLOBALS['request'],'Only the owning worker releases the mutex.'); $this->owner=null; return '1'; }
  return '1';
 }
 public function esc_like($value){return $value;}
 public function get_col($sql){return array_filter(array_keys($GLOBALS['db']),static function($key){return str_starts_with($key,'rfs_accelerated_result_');});}
}
$wpdb=new CoordinationDB();
require dirname(__DIR__).'/lib/admin/accelerated-sync.php';
$seed=static function($id,$n){
 $items=array(); for($i=0;$i<$n;$i++){$items[]=array('hook'=>'fixture','args'=>array($i),'type'=>'property','label'=>'Fixture'.$i);}
 update_option('rfs_accelerated_sync_state',array('run_id'=>$id,'status'=>'running','items'=>$items,'total'=>$n,'completed'=>0,'failed'=>0,'active_items'=>array(),'queued'=>$n));
};
$seed('interleaved',2);
$provider=static function($item){
 $GLOBALS['executed'][]=$item;
 if(0===$item){ $GLOBALS['request']='B'; rfs_process_accelerated_sync_step('interleaved',1,false); $GLOBALS['request']='A'; }
};
rfs_process_accelerated_sync_step('interleaved',0,false);
coordination_check(2===$db['rfs_accelerated_sync_state']['completed']&&'complete'===$db['rfs_accelerated_sync_state']['status']&&1===$clears,'Interleaved request caches must retain both results and emit one full-run boundary.');
rfs_process_accelerated_sync_step('interleaved',0,false);
coordination_check(2===count($executed)&&1===$clears,'Duplicate work cannot execute/account twice.');
$seed('contention',1); $executed=array();
$provider=static function($item){$GLOBALS['executed'][]=$item;$GLOBALS['wpdb']->available=false;};
rfs_process_accelerated_sync_step('contention',0,false);
coordination_check(0===$db['rfs_accelerated_sync_state']['completed']&&isset($events['rfs_finalize_accelerated_sync_item']),'Terminal lock contention must retain a retryable result.');
coordination_check(is_array($db[rfs_accelerated_sync_result_key('contention',0)]??null),'Terminal result is durable before finalization.');
$wpdb->available=true;
rfs_finalize_accelerated_sync_item('contention',0);
rfs_finalize_accelerated_sync_item('contention',0);
coordination_check(1===$db['rfs_accelerated_sync_state']['completed']&&2===$clears&&1===count($executed),'Finalization retries account once without repeating provider work.');
$seed('duplicate-active',1); $executed=array();
$provider=static function($item){$GLOBALS['executed'][]=$item;rfs_process_accelerated_sync_step('duplicate-active',0,false);};
rfs_process_accelerated_sync_step('duplicate-active',0,false);
coordination_check(1===count($executed)&&3===$clears,'An active item cannot reenter the provider callback.');
$seed('cancelled',1);
$provider=static function(){rfs_request_accelerated_sync_cancel('cancelled');};
rfs_process_accelerated_sync_step('cancelled',0,false);
coordination_check('cancelled'===$db['rfs_accelerated_sync_state']['status']&&3===$clears,'Fresh cancellation must win over a stale successful worker result.');
$seed('deactivated',1);
$provider=static function(){update_option('rfs_sync_deactivated',true);};
rfs_process_accelerated_sync_step('deactivated',0,false);
coordination_check(!isset($db[rfs_accelerated_sync_result_key('deactivated',0)])&&3===$clears,'Finishing workers must not retain or requeue results after deactivation.');
delete_option('rfs_sync_deactivated');
class ActionScheduler {
 public static function store(){return new self();}
 public function fetch_action($id){return new class {public function get_hook(){return 'rfs_accelerated_sync_step';}public function get_args(){return array('fatal-fixture',0);}};}
}
$seed('fatal-fixture',1);
rfs_accelerated_sync_action_failed(1);
coordination_check(1===$db['rfs_accelerated_sync_state']['failed']&&'complete'===$db['rfs_accelerated_sync_state']['status']&&4===$clears,'Failed/fatal Action Scheduler notifications must finalize the item and full-run boundary.');
$as_enabled=true; $as_pending=false; $as_created=0;
rfs_retry_accelerated_sync_action('rfs_finalize_accelerated_sync_item',array('retry-fixture',0));
rfs_retry_accelerated_sync_action('rfs_finalize_accelerated_sync_item',array('retry-fixture',0));
coordination_check(1===$as_created,'Only one pending finalization retry should be queued.');
$as_pending=false; // Existing callback is now running, not pending.
rfs_retry_accelerated_sync_action('rfs_finalize_accelerated_sync_item',array('retry-fixture',0));
coordination_check(2===$as_created,'A busy running finalizer must queue its replacement.');
echo "Accelerated coordination passed: fresh interleaved state, durable contention retry, duplicate suppression, cancellation and deactivation.\n";
