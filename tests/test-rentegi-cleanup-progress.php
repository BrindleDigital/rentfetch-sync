<?php
/** Standalone mocked regressions: php tests/test-rentegi-cleanup-progress.php. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
// These isolated fixtures model a Core build with RentEGI support.
function rentfetch_get_floorplan_unit_query_args() {}
function rentfetch_property_syncing_fields_rentegi() {}
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DB_NAME', 'fixture' );
$options = array(
	'rfs_sync_action_args_version' => '3',
	'rentfetch_options_enabled_integrations' => array( 'rentegi' ),
	'rentfetch_options_data_sync' => 'updatesync',
);
$codes = array( 'KEEP' );
$locked = false;
$posts = array();
$transients = array();
$scheduled = array();
$unscheduled = array();
$locks = array();
function cleanup_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function add_action( ...$args ) {}
function wp_cache_delete( ...$args ) {}
function wp_cache_get( ...$args ) { return false; }
function wp_cache_set( ...$args ) {}
function wp_next_scheduled( ...$args ) { return false; }
function wp_schedule_single_event( ...$args ) {}
function wp_unschedule_hook( $hook ) { $GLOBALS['unscheduled'][] = $hook; }
function rfs_authorize_manual_sync_request() { return array( 'property_id' => 'KEEP', 'integration' => 'rentegi' ); }
class CleanupFixtureDB {
 public $options='wp_options';
 public function prepare($sql,...$args) { return vsprintf($sql,$args); }
 public function get_var($sql) { return '1'; }
 public function get_col($sql) { return array(); }
 public function esc_like($value) { return $value; }
}
$wpdb = new CleanupFixtureDB();
function do_action( ...$args ) {}
function apply_filters( $name, $value, ...$args ) { return $value; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, ...$args ) { $GLOBALS['options'][ $key ] = $value; return true; }
function add_option( $key, $value, ...$args ) {
	if ( isset( $GLOBALS['options'][ $key ] ) ) { return false; }
	$GLOBALS['options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); return true; }
function rfs_is_sync_locked( $refresh = false ) { return $GLOBALS['locked']; }
function rfs_rentegi_get_property_codes() { return $GLOBALS['codes']; }
function rfs_acquire_property_sync_lock( $integration, $code ) {
	cleanup_check( ! isset( $GLOBALS['locks'][ $code ] ), 'Cleanup must not overlap a property sync.' );
	$GLOBALS['locks'][ $code ] = true;
	return $code;
}
function rfs_release_property_sync_lock( $code ) { unset( $GLOBALS['locks'][ $code ] ); }
function rfs_assert_property_sync_lock( $integration, $code ) { cleanup_check( isset( $GLOBALS['locks'][ $code ] ), 'Deletion requires the active property lock.' ); }
function get_post_meta( $post_id, $key, $single = false ) { return $GLOBALS['posts'][ $post_id ]['meta'][ $key ] ?? ''; }
function get_post_type( $post_id ) { return $GLOBALS['posts'][ $post_id ]['type'] ?? false; }
function get_posts( $args ) {
	cleanup_check( 'any' === $args['post_status'] && 'ids' === $args['fields'], 'Cleanup must include drafts and request IDs.' );
	$ids = array();
	foreach ( $GLOBALS['posts'] as $id => $post ) {
		if ( $post['type'] !== $args['post_type'] ) { continue; }
		$matches = true;
		foreach ( $args['meta_query'] as $query ) {
			$value = $post['meta'][ $query['key'] ] ?? null;
			$compare = $query['compare'] ?? '=';
			$matches = $matches && ( 'NOT IN' === $compare ? ! in_array( $value, $query['value'], true ) : ( 'EXISTS' === $compare ? null !== $value : $value === $query['value'] ) );
		}
		if ( $matches ) { $ids[] = $id; }
	}
	return $ids;
}
function wp_delete_post( $post_id, $force = false ) {
	cleanup_check( true === $force, 'Configured-property cleanup must match existing permanent deletion semantics.' );
	cleanup_check( ! empty( $GLOBALS['locks'] ), 'Deletion must hold the property lock.' );
	unset( $GLOBALS['posts'][ $post_id ] );
	return (object) array( 'ID' => $post_id );
}
function is_admin() { return true; }
function as_has_scheduled_action( ...$args ) { return false; }
function as_schedule_recurring_action( $time, $interval, $hook, $args, $group ) {
	$GLOBALS['scheduled'][] = compact( 'time', 'interval', 'hook', 'args', 'group' );
}
function as_unschedule_all_actions( $hook ) { $GLOBALS['unscheduled'][] = $hook; }
function do_action_ref_array( $hook, $args ) {
	cleanup_check( 'rfs_rentegi_do_delete_orphans' === $hook && array() === $args, 'Accelerated cleanup must support an argument-free callback.' );
	rfs_rentegi_delete_unconfigured_properties();
}
function check_ajax_referer( ...$args ) {}
function is_wp_error( $value ) { return false; }
function sanitize_text_field( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }
class CleanupJsonResponse extends RuntimeException {
	public $payload;
	public function __construct( $payload ) { $this->payload = $payload; }
}
function wp_send_json_success( $data ) { throw new CleanupJsonResponse( $data ); }
function wp_send_json_error( $data, $status ) { throw new RuntimeException( 'Unexpected JSON error: ' . $status ); }
require dirname( __DIR__ ) . '/lib/api/rentegi/delete-properties-no-longer-in-box-rentegi.php';
require dirname( __DIR__ ) . '/lib/start-property-sync.php';
require dirname( __DIR__ ) . '/lib/admin/accelerated-sync.php';

foreach ( array( 'properties' => 'property_source', 'floorplans' => 'floorplan_source', 'units' => 'unit_source' ) as $type => $source_key ) {
	foreach ( array( array( 'KEEP', 'rentegi' ), array( 'REMOVE', 'rentegi' ), array( 'REMOVE', 'yardi' ) ) as $fixture ) {
		$posts[] = array( 'type' => $type, 'status' => 'draft', 'meta' => array( $source_key => $fixture[1], 'property_id' => $fixture[0] ) );
	}
}
$before = $posts;
$locked = true;
rfs_rentegi_delete_unconfigured_properties();
cleanup_check( $posts === $before, 'Remote sync lock must prevent cleanup.' );
$locked = false;
rfs_rentegi_delete_unconfigured_properties();
cleanup_check( 6 === count( $posts ) && empty( $locks ), 'Only the removed RentEGI property and its children must be deleted, then unlocked.' );

rfs_perform_syncs();
cleanup_check( 2 === count( $scheduled ) && 'rfs_rentegi_do_delete_orphans' === $scheduled[0]['hook'] && array() === $scheduled[0]['args'] && 'KEEP' === $scheduled[1]['args'][0]['property_id'], 'Recurring sync must schedule cleanup and configured properties.' );
$manifest = rfs_get_accelerated_sync_manifest();
cleanup_check( 2 === count( $manifest ) && 'cleanup' === $manifest[0]['type'] && 'KEEP' === $manifest[1]['property_id'], 'Accelerated sync must include RentEGI cleanup.' );

// A cleanup queued before settings changed must use the current, now-empty list.
$codes = array();
$options['rfs_accelerated_sync_state'] = array(
	'run_id' => 'cleanup-fixture', 'status' => 'running', 'items' => array( $manifest[0] ),
	'total' => 1, 'queued' => 1, 'completed' => 0, 'failed' => 0, 'active_items' => array(),
);
rfs_process_accelerated_sync_step( 'cleanup-fixture', 0, false );
cleanup_check( 3 === count( $posts ) && 'complete' === $options['rfs_accelerated_sync_state']['status'], 'An intentionally empty setting must remove remaining RentEGI content through the queue, preserving other sources.' );
$scheduled = array();
rfs_perform_syncs();
cleanup_check( 1 === count( $scheduled ) && 'rfs_rentegi_do_delete_orphans' === $scheduled[0]['hook'], 'The empty property list must still schedule cleanup.' );
$options['rentfetch_options_data_sync'] = 'nosync';
rfs_perform_syncs();
cleanup_check( in_array( 'rfs_rentegi_do_delete_orphans', $unscheduled, true ), 'Pausing must unschedule RentEGI cleanup.' );
$unscheduled = array();
$options['rfs_sync_action_args_version'] = '2';
rfs_maybe_migrate_sync_action_args();
cleanup_check( in_array( 'rfs_rentegi_do_delete_orphans', $unscheduled, true ), 'Argument migration must unschedule RentEGI cleanup.' );

$_POST = array( 'property_id' => 'KEEP', 'integration' => 'rentegi' );
$progress_key = 'rfs_sync_progress_' . md5( 'rentegi_KEEP' );
foreach ( array( array( 'step' => 1, 'total' => 2 ), array( 'step' => 2, 'total' => 2 ) ) as $progress ) {
	$transients[ $progress_key ] = $progress;
	try { rfs_get_sync_progress_ajax_handler(); } catch ( CleanupJsonResponse $response ) {
		cleanup_check( $progress === $response->payload, 'The response must still include the last progress update.' );
	}
	cleanup_check( ( $progress['step'] < $progress['total'] ) === isset( $transients[ $progress_key ] ), 'Completed progress must be removed before JSON exits; unfinished progress must remain.' );
}
$options['rfs_sync_action_args_version']='3';
$options['rfs_accelerated_sync_state']=array('run_id'=>'old-coordinator','status'=>'running','active_items'=>array());
rfs_maybe_migrate_sync_action_args();
cleanup_check('cancelled'===$options['rfs_accelerated_sync_state']['status'],'Schema migration must retire older in-progress coordinator state.');
echo "RentEGI cleanup/progress regressions passed (mocked content, schedules and JSON exits).\n";
