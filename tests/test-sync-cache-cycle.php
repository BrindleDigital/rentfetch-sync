<?php
/** Standalone lifecycle regression; all scheduling, providers and caches are mocked. */
define( 'ABSPATH', __DIR__ );
// These isolated fixtures model a Core build with RentEGI support.
function rentfetch_get_floorplan_unit_query_args() {}
function rentfetch_property_syncing_fields_rentegi() {}
define( 'DB_NAME', 'fixture' );
define( 'MINUTE_IN_SECONDS', 60 );
$options = array( 'rentfetch_options_enabled_integrations' => array( 'rentegi' ) );
$hooks = array();
$clears = 0;
$retry = array();
$codes = array( 'A', 'B' );
$manual_failure = false;
function cache_check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function do_action( $hook, ...$args ) { foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as $callback ) { $callback( ...$args ); } }
function do_action_ref_array( $hook, $args ) { do_action( $hook, ...$args ); }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, ...$args ) { $GLOBALS['options'][ $key ] = $value; return true; }
function add_option( $key, $value, ...$args ) {
	if ( isset( $GLOBALS['options'][ $key ] ) ) { return false; }
	$GLOBALS['options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); }
function wp_cache_delete( ...$args ) {}
function wp_cache_get( ...$args ) { return false; }
function wp_cache_set( ...$args ) {}
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_next_scheduled( $hook, $args ) { return $GLOBALS['retry'][ $args[0] ] ?? false; }
function wp_schedule_single_event( $time, $hook, $args ) { $GLOBALS['retry'][ $args[0] ] = $time; }
function rentfetch_clear_search_cache_transients() { ++$GLOBALS['clears']; }
function rfs_rentegi_get_property_codes() { return $GLOBALS['codes']; }
function rfs_is_sync_locked() { return false; }
class CacheFixtureDB {
	public $options = 'wp_options';
	public $lock_available = true;
	public function prepare( $sql, ...$args ) { return vsprintf( $sql, $args ); }
	public function get_var( $sql ) { return str_contains( $sql, 'GET_LOCK' ) && ! $this->lock_available ? 0 : 1; }
}
$wpdb = new CacheFixtureDB();
class CacheFixtureSchedule {
	private $recurring;
	public function __construct( $recurring ) { $this->recurring = $recurring; }
	public function is_recurring() { return $this->recurring; }
}
class CacheFixtureAction {
	private $hook;
	private $args;
	private $recurring;
	public function __construct( $hook, $args = array(), $recurring = true ) { $this->hook = $hook; $this->args = $args; $this->recurring = $recurring; }
	public function get_group() { return 'rentfetch'; }
	public function get_schedule() { return new CacheFixtureSchedule( $this->recurring ); }
	public function get_hook() { return $this->hook; }
	public function get_args() { return $this->args; }
}
class ActionScheduler {
	public static $actions = array();
	public static function store() { return new self(); }
	public function fetch_action( $id ) { return self::$actions[ $id ]; }
}
require dirname( __DIR__ ) . '/lib/initialization/sync-cache.php';
require dirname( __DIR__ ) . '/lib/admin/accelerated-sync.php';
require dirname( __DIR__ ) . '/lib/start-property-sync.php';
// Avoid the real dispatcher: this test verifies boundaries, not provider behavior.
$hooks['rfs_do_sync'] = array( static function() {
	if ( $GLOBALS['manual_failure'] ) { throw new RuntimeException( 'Mock partial write failure' ); }
} );
foreach ( array( 1 => 'A', 2 => 'A', 3 => 'B', 5 => 'A', 6 => 'B', 9 => 'A', 10 => 'B', 12 => 'B' ) as $id => $code ) {
	ActionScheduler::$actions[ $id ] = new CacheFixtureAction( 'rfs_do_sync', array( array( 'integration' => 'rentegi', 'property_id' => $code ) ) );
}
foreach ( array( 4, 7, 11 ) as $id ) { ActionScheduler::$actions[ $id ] = new CacheFixtureAction( 'rfs_rentegi_do_delete_orphans' ); }
do_action( 'action_scheduler_after_execute', 1 );
do_action( 'action_scheduler_after_execute', 2 );
do_action( 'action_scheduler_failed_execution', 3 );
cache_check( 0 === $clears, 'Individual properties and repeats must not clear caches while cleanup is outstanding.' );
do_action( 'action_scheduler_after_execute', 4 );
cache_check( 1 === $clears, 'All property attempts plus cleanup must clear once, including a failed property.' );
do_action( 'action_scheduler_after_execute', 4 );
do_action( 'action_scheduler_after_execute', 1 );
cache_check( 1 === $clears, 'Duplicate or out-of-order notifications cannot clear again or seed a new pass.' );
do_action( 'action_scheduler_after_execute', 5 );
do_action( 'action_scheduler_unexpected_shutdown', 6 );
do_action( 'action_scheduler_after_execute', 7 );
cache_check( 2 === $clears, 'The next complete pass clears once, including fatal job failures.' );
ActionScheduler::$actions[8] = new CacheFixtureAction( 'rfs_do_sync', array( array( 'integration' => 'rentegi', 'property_id' => 'A' ) ), false );
do_action( 'action_scheduler_after_execute', 8 );
cache_check( 2 === $clears, 'One-off jobs must not enter recurring-cycle accounting.' );
$wpdb->lock_available = false;
do_action( 'action_scheduler_after_execute', 9 );
cache_check( isset( $retry[9] ) && 2 === $clears, 'Lock contention must defer the notification without clearing.' );
$wpdb->lock_available = true;
do_action( 'rfs_record_sync_cache_completion', 9 );
do_action( 'action_scheduler_after_execute', 10 );
do_action( 'action_scheduler_after_execute', 11 );
cache_check( 3 === $clears, 'Deferred notification still completes its pass exactly once.' );
$codes = array( 'B' );
do_action( 'action_scheduler_after_execute', 12 );
ActionScheduler::$actions[13] = new CacheFixtureAction( 'rfs_rentegi_do_delete_orphans' );
do_action( 'action_scheduler_after_execute', 13 );
cache_check( 4 === $clears, 'Removed configuration must not leave a cycle waiting for an unscheduled property.' );
rfs_sync_single_property( 'B', 'rentegi' );
cache_check( 5 === $clears, 'Manual sync may clear immediately.' );
$manual_failure = true;
try { rfs_sync_single_property( 'B', 'rentegi' ); } catch ( RuntimeException $exception ) {}
cache_check( 6 === $clears, 'Manual partial writes must invalidate even on failure.' );
$manual_failure = false;
$options['rfs_accelerated_sync_state'] = array(
	'run_id' => 'fixture', 'status' => 'running', 'total' => 2, 'completed' => 0, 'failed' => 0,
	'active_items' => array(), 'items' => array(
		array( 'hook' => 'rfs_do_sync', 'args' => array( array( 'integration' => 'rentegi', 'property_id' => 'A' ) ), 'type' => 'property' ),
		array( 'hook' => 'rfs_do_sync', 'args' => array( array( 'integration' => 'rentegi', 'property_id' => 'B' ) ), 'type' => 'property' ),
	),
);
rfs_process_accelerated_sync_step( 'fixture', 0, false );
cache_check( 6 === $clears, 'Accelerated property completion cannot clear caches.' );
rfs_process_accelerated_sync_step( 'fixture', 1, false );
cache_check( 7 === $clears, 'The complete accelerated run clears once.' );
rfs_process_accelerated_sync_step( 'fixture', 1, false );
cache_check( 7 === $clears, 'Finished accelerated workers cannot invalidate twice.' );
echo "Sync cache lifecycle passed: full recurring rounds, failures, duplicates, lock retry, settings changes, manual and accelerated boundaries.\n";
