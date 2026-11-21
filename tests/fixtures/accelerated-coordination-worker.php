<?php
/** Real WordPress coordinator fixture; disposable Cove follow-up site only. */
$test = json_decode( getenv( 'RFS_COORDINATION_TEST' ), true );
if ( ! is_array( $test ) || ! str_contains( ABSPATH, '/rentfetch-followup-test-' ) ) { throw new RuntimeException('Disposable follow-up site required.'); }
$base=dirname(__DIR__,2);
require_once $base.'/lib/api/sync-access.php';
require_once $base.'/lib/admin/accelerated-sync.php';
add_filter('pre_http_request',static function(){ return new WP_Error('fixture_http_denied','No HTTP allowed.'); },PHP_INT_MAX);
register_post_type('rfs_coord_fixture',array('public'=>false));
add_action('rfs_coordination_fixture',static function($index) use($test){
 $id=wp_insert_post(array('post_type'=>'rfs_coord_fixture','post_title'=>$test['run_id'].':'.$index,'post_status'=>'publish'),true);
 if(is_wp_error($id)){throw new RuntimeException('Fixture record save failed.');}
 file_put_contents($test['barrier'].'/ready-'.$index,'ready');
 $until=microtime(true)+30;
 while(!file_exists($test['barrier'].'/release')&&microtime(true)<$until){usleep(20000);}
 if(!file_exists($test['barrier'].'/release')){throw new RuntimeException('Fixture barrier timed out.');}
});
add_action('rfs_sync_cycle_finished',static function(){update_option('rfs_coordination_events',(int)get_option('rfs_coordination_events',0)+1,false);});
switch($test['mode']){
 case 'setup':
  delete_option('rfs_sync_deactivated');
  $items=array();for($i=0;$i<5;$i++){$items[]=array('hook'=>'rfs_coordination_fixture','args'=>array($i),'type'=>'property','label'=>'Fixture'.$i);}
  rfs_update_accelerated_sync_state(array('run_id'=>$test['run_id'],'status'=>'running','items'=>$items,'total'=>5,'queued'=>5,'completed'=>0,'failed'=>0,'active_items'=>array()));
  update_option('rfs_coordination_events',0,false);break;
 case 'worker':rfs_process_accelerated_sync_step($test['run_id'],(int)$test['index'],false);break;
 case 'cancel':rfs_cancel_accelerated_sync();break;
 case 'check':
  $state=rfs_get_accelerated_sync_state();
  $posts=get_posts(array('post_type'=>'rfs_coord_fixture','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','s'=>$test['run_id']));
  echo 'COORDINATION_RESULT='.wp_json_encode(array('completed'=>$state['completed'],'failed'=>$state['failed'],'status'=>$state['status'],'events'=>(int)get_option('rfs_coordination_events',0),'records'=>count($posts)))."\n";break;
}
