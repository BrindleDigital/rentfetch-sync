<?php
/** Worker fixture for test-property-sync-locks.py; fresh disposable Cove site only. */
$test = json_decode( getenv( 'RFS_LOCK_TEST' ), true );
if ( ! is_array( $test ) || ! str_contains( ABSPATH, '/rentfetch-lock-test-' ) && ! str_contains( ABSPATH, '/rentfetch-followup-test-' ) ) {
	throw new RuntimeException( 'Run only in the disposable lock test site.' );
}
$GLOBALS['test'] = $test;
delete_option( 'rfs_sync_deactivated' );
if ( 'cleanup' === $test['mode'] ) {
	add_action( 'pre_get_posts', static function( $query ) {
		$meta = $query->get( 'meta_query' );
		$meta = is_array( $meta ) ? $meta : array();
		$meta[] = array( 'key' => 'property_id', 'value' => 'LOCK_', 'compare' => 'LIKE' );
		$query->set( 'meta_query', $meta );
	} );
}

update_option( 'rentfetch_options_enabled_integrations', array( 'yardi','entrata','engrain','rentegi','rentmanager' ) );
foreach ( array( 'yardi_integration_creds_yardi_property_code','entrata_integration_creds_entrata_property_ids','engrain_integration_creds_engrain_asset_ids','rentegi_property_codes' ) as $key ) {
	update_option( 'rentfetch_options_' . $key, 'cleanup' === $test['mode'] ? 'KEEP' : $test['property_id'] );
}
$base = dirname( __DIR__, 2 );
require_once dirname( $base ) . '/rentfetch/rentfetch.php';
foreach ( array( 'properties', 'floorplans', 'units' ) as $type ) { register_post_type( $type, array( 'public' => true ) ); }
add_filter( 'pre_http_request', static function() { return new WP_Error( 'test_network_disabled', 'No HTTP allowed in lock worker.' ); }, PHP_INT_MAX );
function rfs_is_sync_locked( $refresh = false ) { return false; }
function rfs_verify_sync_access_before_sync() { return true; }
function rfs_get_credentials() {
	if ( 'credentials-fail' === ( $GLOBALS['test']['behavior'] ?? '' ) ) { throw new RuntimeException( 'Fixture credential failure.' ); }
	return array( 'yardi' => array( 'apikey' => 'fixture', 'company_code' => 'fixture', 'vendor' => 'fixture' ), 'entrata' => array( 'subdomain' => 'fixture' ), 'engrain' => array( 'api_key' => 'fixture' ), 'rentmanager' => array( 'companycode' => 'fixture' ) );
}
function rfs_get_yardi_bearer_token() { return 'fixture'; }
function rfs_get_entrata_api_key() { return 'fixture'; }
function rfs_rentegi_get_property_codes() { return array(); }
function rfs_get_enabled_rentmanager_properties() { return array( array( 'ShortName' => 'cleanup' === $GLOBALS['test']['mode'] ? 'KEEP' : $GLOBALS['test']['property_id'] ) ); }
function rfs_lock_test_provider( $args ) {
	$GLOBALS['entered'] = true;
	rfs_assert_property_sync_lock( $args['integration'], $args['property_id'] );
	$args = rfs_maybe_create_property( $args );
	if ( 'lost' === ( $GLOBALS['test']['behavior'] ?? '' ) ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $args['property_sync_lock'] ) );
	}
	$args['floorplan_id'] = 'PLAN';
	$args = rfs_maybe_create_floorplan( $args );
	$args['unit_id'] = 'UNIT';
	rfs_maybe_create_unit( $args );
	if ( 'reentry' === ( $GLOBALS['test']['behavior'] ?? '' ) ) {
		try { rfs_trigger_specific_api_sync( $args ); } catch ( RuntimeException $error ) { $GLOBALS['reentry_rejected'] = true; }
		if ( empty( $GLOBALS['reentry_rejected'] ) ) { throw new RuntimeException( 'Reentrant dispatcher was allowed.' ); }
	}
	if ( ! empty( $GLOBALS['test']['ready'] ) ) {
		file_put_contents( $GLOBALS['test']['ready'], 'ready' );
		$deadline = microtime( true ) + 20;
		while ( ! file_exists( $GLOBALS['test']['release'] ) && microtime( true ) < $deadline ) { usleep( 20000 ); }
		if ( ! file_exists( $GLOBALS['test']['release'] ) ) { throw new RuntimeException( 'Fixture release timed out.' ); }
	}
	if ( 'fail' === ( $GLOBALS['test']['behavior'] ?? '' ) ) { throw new RuntimeException( 'Fixture provider failure.' ); }
}
function rfs_do_yardi_sync( $args ) { rfs_lock_test_provider( $args ); }
function rfs_do_rentmanager_sync( $args ) { rfs_lock_test_provider( $args ); }
function rfs_do_entrata_sync( $args ) { rfs_lock_test_provider( $args ); }
function rfs_do_engrain_sync( $args ) { rfs_lock_test_provider( $args ); }
function rfs_do_rentegi_sync( $args ) { rfs_lock_test_provider( $args ); }
require $base . '/lib/api/property-sync-lock.php';
require $base . '/lib/start-property-sync.php';
require $base . '/lib/admin/accelerated-sync.php';
foreach ( array( 'property', 'floorplan', 'unit' ) as $type ) { require $base . '/lib/api/maybe-create-' . $type . '.php'; }
$args = array( 'integration' => $test['integration'], 'property_id' => $test['property_id'] );
$failed = false;
try {
	if ( 'manual' === $test['mode'] ) {
		rfs_sync_single_property( $args['property_id'], $args['integration'] );
	} elseif ( 'accelerated' === $test['mode'] ) {
		$run = wp_generate_uuid4();
		rfs_update_accelerated_sync_state( array( 'run_id' => $run, 'status' => 'running', 'total' => 1, 'completed' => 0, 'failed' => 0, 'items' => array( array( 'type' => 'property', 'hook' => 'rfs_do_sync', 'args' => array( $args ) ) ) ) );
		rfs_process_accelerated_sync_step( $run, 0, false );
		$failed = 1 === rfs_get_accelerated_sync_state()['failed'];
	} elseif ( 'cleanup' === $test['mode'] ) {
		switch ( $test['integration'] ) {
			case 'yardi':
				require $base . '/lib/api/yardi/v2/delete-properties-no-longer-in-box-v2.php';
				rfs_yardi_delete_orphans_properties_floorplans_units( array( 'KEEP' ) );
				break;
			case 'entrata':
				require $base . '/lib/api/entrata/delete-properties-no-longer-in-box-entrata.php';
				rfs_entrata_delete_orphans_properties_floorplans_units( array( 'KEEP' ) );
				break;
			case 'engrain':
				require $base . '/lib/api/engrain/update-wp-engrain.php';
				rfs_engrain_delete_unconfigured_assets( array( 'KEEP' ) );
				break;
			case 'rentmanager':
				update_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames', array( array( 'ShortName' => 'KEEP' ) ) );
				require $base . '/lib/api/rentmanager/remove-posts-when-property-no-longer-should-sync.php';
				rfs_rentmanager_remove_records_that_shouldnt_be_synced_property_deleted();
				break;
			case 'rentegi':
				require $base . '/lib/api/rentegi/delete-properties-no-longer-in-box-rentegi.php';
				rfs_rentegi_delete_unconfigured_properties();
				break;
		}
	} else {
		do_action( 'rfs_do_sync', $args );
	}
} catch ( RuntimeException $exception ) {
	$failed = true;
}
$counts = array();
foreach ( array( 'properties' => 'property', 'floorplans' => 'floorplan', 'units' => 'unit' ) as $type => $singular ) {
	$counts[ $type ] = count( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => 'property_id', 'value' => $test['property_id'] ), array( 'key' => $singular . '_source', 'value' => $test['integration'] ) ) ) ) );
}
echo 'RFS_LOCK_RESULT=' . wp_json_encode( array( 'entered' => ! empty( $GLOBALS['entered'] ), 'failed' => $failed, 'counts' => $counts, 'reentry_rejected' => ! empty( $GLOBALS['reentry_rejected'] ) ) ) . "\n";
