<?php
/** Cove WP-CLI eval-file --skip-plugins --skip-themes. Restores options; HTTP and scheduling are intercepted. */
$root = dirname( __DIR__ );
if ( ! defined( 'RENTFETCH_API_URL' ) ) { define( 'RENTFETCH_API_URL', 'https://api-fixture.invalid/wp-json/rentfetchapi/v1/data' ); }
require_once dirname( $root ) . '/rentfetch/lib/common/json-utils.php';
$GLOBALS['rfs_lock_unscheduled'] = array();
function as_unschedule_all_actions( $hook ) { $GLOBALS['rfs_lock_unscheduled'][] = $hook; }
function as_enqueue_async_action( ...$args ) {
	if ( empty( $GLOBALS['rfs_lock_allow_enqueue'] ) ) { throw new RuntimeException( 'Unexpected scheduled sync.' ); }
	$GLOBALS['rfs_lock_queued'][] = $args;
	return count( $GLOBALS['rfs_lock_queued'] );
}
require_once $root . '/lib/api/fetch-rentfetch-api-info.php';
require_once $root . '/lib/api/get-credentials.php';
require_once $root . '/lib/start-property-sync.php';
require_once $root . '/lib/admin/accelerated-sync.php';
require_once $root . '/lib/admin/sync-lock.php';
require_once $root . '/lib/api/rentmanager/api-functions-rentmanager.php';
require_once $root . '/lib/api/rentmanager/get-properties-from-setting.php';
require_once $root . '/lib/api/yardi/v2/delete-properties-no-longer-in-box-v2.php';
require_once $root . '/lib/api/entrata/delete-properties-no-longer-in-box-entrata.php';
require_once $root . '/lib/api/engrain/update-wp-engrain.php';
function rfs_lock_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$keys = array( 'rfs_api_sync_locked', 'rentfetch_options_data_sync', 'rentfetch_options_enabled_integrations', 'rentfetch_api_info_last_success', 'rentfetch_api_info_refresh_lock', rfs_get_accelerated_sync_state_key() );
$original = array();
$missing = 'missing-' . wp_generate_uuid4();
foreach ( $keys as $key ) { $original[$key] = get_option( $key, $missing ); }
$transients = array();
foreach ( array( 'rentfetch_api_info', 'rentfetch_api_info_error', 'rentfetchsync_properties_limit' ) as $key ) {
	$transients[$key] = array( get_transient( $key ), (int) get_option( '_transient_timeout_' . $key ) );
}
$http_requests = array();
$http_response = array();
$mock_http = function( $pre, $args, $url ) use ( &$http_requests, &$http_response ) {
	$http_requests[] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
	return $http_response;
};
$respond = function( $status, $body ) use ( &$http_response ) {
	$http_response = array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => 'Fixture' ), 'cookies' => array() );
};
$locked = array( 'code' => 'rentfetch_sync_locked', 'message' => 'Syncing Locked', 'data' => array( 'status' => 403, 'sync_locked' => true, 'sync_paused' => true ) );
$cached = array( 'monitoring' => array(), 'entrata' => array( 'api_key' => 'fixture-old-secret' ) );
$run_id = 'sync-lock-' . wp_generate_uuid4();
add_filter( 'pre_http_request', $mock_http, PHP_INT_MAX, 3 );
try {
	rfs_update_accelerated_sync_state( array( 'run_id' => $run_id, 'status' => 'running' ) );
	rfs_lock_check( str_ends_with( rfs_get_rentfetch_api_url( 'rentmanager/units' ), '/rentmanager/units' ) && str_starts_with( rfs_get_rentfetch_api_url( 'rentmanager/units' ), preg_replace( '#/data/?$#', '', RENTFETCH_API_URL ) ), 'Proxy requests must respect the configured central API URL.' );
	delete_option( 'rfs_api_sync_locked' );
	update_option( 'rentfetch_options_data_sync', 'updatesync' );
	update_option( 'rentfetch_options_enabled_integrations', array( 'rentmanager' ) );
	set_transient( 'rentfetch_api_info', $cached, 1200 );
	update_option( 'rentfetch_api_info_last_success', $cached );
	rfs_lock_check( $cached === rfs_get_info_from_rentfetch_api(), 'The valid pre-lock cache should be available.' );
	$respond( 403, array( 'code' => 'provider_error' ) );
	rfs_rentfetch_api_post( 'data' );
	rfs_lock_check( ! rfs_is_sync_locked(), 'Ordinary 403 errors must not lock syncing.' );
	// Every proxy can deliver the lock, including when bootstrap data was cached.
	foreach ( array( 'properties', 'properties-all', 'unit-types', 'units' ) as $route ) {
		delete_option( 'rfs_api_sync_locked' );
		update_option( 'rentfetch_options_data_sync', 'updatesync' );
		$respond( 403, $locked );
		rfs_rentfetch_api_post( 'rentmanager/' . $route, array( 'company_code' => 'fixture' ) );
		rfs_lock_check( rfs_is_sync_locked() && 'nosync' === get_option( 'rentfetch_options_data_sync' ), 'All proxy responses must apply the lock: ' . $route );
		$last = end( $http_requests );
		rfs_lock_check( isset( $last['body']['site_url'] ) && ! isset( $last['body']['partner_token'] ), 'Proxy requests must identify the website without shared credentials.' );
	}
	delete_option( 'rfs_api_sync_locked' );
	update_option( 'rentfetch_options_data_sync', 'updatesync' );
	update_option( 'rentfetch_api_info_last_success', $cached );
	rfs_update_accelerated_sync_state( array( 'run_id' => $run_id, 'status' => 'running' ) );
	rfs_clear_rentfetch_api_info_cache();
	$respond( 403, $locked );
	$result = rfs_get_info_from_rentfetch_api();
	rfs_lock_check( ! is_array( $result ) && rfs_is_sync_locked(), 'Bootstrap denial must lock before validation and fallback reuse.' );
	rfs_lock_check( false === get_transient( 'rentfetch_api_info' ) && false === get_option( 'rentfetch_api_info_last_success' ), 'Both credential caches must be removed.' );
	rfs_lock_check( 'cancelled' === rfs_get_accelerated_sync_state()['status'], 'Active accelerated sync must be cancelled.' );
	foreach ( array( 'rfs_do_sync', 'rfs_yardi_do_delete_orphans', 'rfs_entrata_do_delete_orphans', 'rfs_engrain_do_delete_orphans', 'rfs_accelerated_sync_step' ) as $hook ) {
		rfs_lock_check( in_array( $hook, $GLOBALS['rfs_lock_unscheduled'], true ), 'Pending work must be cancelled: ' . $hook );
	}
	update_option( 'rentfetch_options_data_sync', 'updatesync' );
	rfs_lock_check( 'nosync' === get_option( 'rentfetch_options_data_sync' ), 'Local settings cannot resume a locked site.' );
	$before = count( $http_requests );
	set_transient( 'rentfetch_api_info', $cached, 1200 );
	update_option( 'rentfetch_api_info_last_success', $cached );
	rfs_lock_check( ! is_array( rfs_get_info_from_rentfetch_api() ) && false === rfs_get_valid_rentfetch_api_info_last_success( array() ) && false === rfs_get_valid_rentfetch_api_info_transient( array() ), 'Known locks must block stale and in-request caches.' );
	rfs_lock_check( array() === rfs_get_credentials(), 'Locked sites must not resolve runtime credentials.' );
	rfs_lock_check( null === rfs_get_yardi_bearer_token() && null === rfs_get_entrata_api_key() && null === rfs_get_engrain_api_key(), 'Every credential getter must reject last-good credentials while locked.' );
	ob_start(); rfs_render_sync_lock_notice(); $notice = ob_get_clean();
	rfs_lock_check( false !== has_action( 'all_admin_notices', 'rfs_render_sync_lock_notice' ) && str_contains( $notice, 'rfs-sync-locked-notice' ) && str_contains( $notice, 'exceeded your sync quota with Rent Fetch Sync' ) && str_contains( $notice, 'mailto:support@brindledigital.com' ), 'The quota notice and contact must appear throughout admin, including for users without settings access.' );
	rfs_refresh_info_from_rentfetch_api();
	rfs_perform_syncs();
	rfs_schedule_property_sync_action( array(), 60, 0 );
	rfs_trigger_specific_api_sync( array( 'integration' => 'rentmanager', 'property_id' => 'fixture' ) );
	rfs_do_rentmanager_sync( array() );
	rfs_yardi_delete_orphans_properties_floorplans_units( array() );
	rfs_entrata_delete_orphans_properties_floorplans_units( array() );
	rfs_engrain_delete_unconfigured_assets( array() );
	rfs_process_accelerated_sync_step( $run_id );
	$args = array( 'credentials' => array( 'rentmanager' => array( 'companycode' => 'fixture' ) ), 'property_id' => 'fixture', 'rentmanager_property_id' => 1 );
	rfs_rentmanager_get_property_data( $args );
	rfs_rentmanager_get_unit_types_data( $args );
	rfs_rentmanager_get_units_data( $args );
	rfs_get_rentmanager_properties_from_setting();
	rfs_lock_check( $before === count( $http_requests ), 'A locked client must stop all automatic API calls.' );
	$before_manual = count( $http_requests );
	rfs_lock_check( is_wp_error( rfs_start_accelerated_sync() ) && rfs_is_sync_locked(), 'An explicit full sync must respect a confirmed API lock.' );
	$last = end( $http_requests );
	rfs_lock_check( count( $http_requests ) === $before_manual + 1 && str_ends_with( $last['url'], '/sync-access' ), 'A locked manual start may only make a credential-free access check.' );
	$http_response = new WP_Error( 'timeout', 'Fixture timeout' );
	rfs_lock_check( is_wp_error( rfs_start_accelerated_sync() ) && rfs_is_sync_locked() && 'nosync' === get_option( 'rentfetch_options_data_sync' ), 'An unavailable API must retain the lock and refuse a full sync.' );
	foreach ( array( array( 403, array( 'code' => 'provider_error' ) ), array( 200, array() ), array( 200, array( 'sync_locked' => 'false' ) ), array( 403, $locked ) ) as $case ) {
		$respond( $case[0], $case[1] );
		rfs_lock_check( is_wp_error( rfs_start_accelerated_sync() ) && rfs_is_sync_locked(), 'A manual full sync requires an explicit boolean API unlock.' );
	}
	$manifest_fixture = function() {
		return array( array( 'hook' => 'rfs_do_sync', 'args' => array( array( 'integration' => 'fixture', 'property_id' => 'fixture' ) ), 'type' => 'property', 'label' => 'Unlock recovery fixture' ) );
	};
	$skip_immediate = function() { return false; };
	add_filter( 'rfs_accelerated_sync_manifest', $manifest_fixture );
	add_filter( 'rfs_accelerated_sync_process_first_item_immediately', $skip_immediate );
	$GLOBALS['rfs_lock_allow_enqueue'] = true;
	$GLOBALS['rfs_lock_queued'] = array();
	$respond( 200, array( 'sync_locked' => false ) );
	try {
		$state = rfs_start_accelerated_sync();
		rfs_lock_check( is_array( $state ) && 'running' === $state['status'] && 1 === count( $GLOBALS['rfs_lock_queued'] ), 'The same full-sync start must queue work immediately after the API confirms an unlock.' );
		$payload = rfs_get_accelerated_sync_status_payload();
		rfs_lock_check( ! rfs_is_sync_locked() && 'updatesync' === get_option( 'rentfetch_options_data_sync' ) && false === $payload['sync_locked'] && true === $payload['sync_enabled'], 'Unlock recovery must resume syncing and return the updated lock state to the browser.' );
	} finally {
		$GLOBALS['rfs_lock_allow_enqueue'] = false;
		remove_filter( 'rfs_accelerated_sync_manifest', $manifest_fixture );
		remove_filter( 'rfs_accelerated_sync_process_first_item_immediately', $skip_immediate );
		rfs_update_accelerated_sync_state( array( 'run_id' => $run_id, 'status' => 'cancelled' ) );
	}
	// A lock first learned inside the immediate worker must outrank its cancellation.
	delete_option( 'rfs_api_sync_locked' );
	$locked_manifest = function() {
		return array( array( 'hook' => 'rfs_fixture_discover_lock', 'args' => array( 'fixture' ), 'type' => 'property', 'label' => 'First lock discovery' ) );
	};
	$discover_lock = function() { rfs_rentfetch_api_post( 'data' ); };
	add_filter( 'rfs_accelerated_sync_manifest', $locked_manifest );
	add_action( 'rfs_fixture_discover_lock', $discover_lock );
	$respond( 403, $locked );
	try {
		$state = rfs_start_accelerated_sync();
		rfs_lock_check( 'cancelled' === $state['status'] && rfs_is_sync_locked(), 'The first denied worker must cancel the run and persist its lock.' );
		foreach ( array( false, true ) as $include_finished ) {
			$payload = rfs_get_accelerated_sync_status_payload( $include_finished );
			rfs_lock_check( 'locked' === $payload['status'] && rfs_sync_locked_message() === $payload['message'] && ! $payload['running'], 'The first lock must show the quota message instead of cancellation, including ordinary status polls.' );
		}
		delete_option( rfs_get_accelerated_sync_cancel_key( $state['run_id'] ) );
	} finally {
		remove_filter( 'rfs_accelerated_sync_manifest', $locked_manifest );
		remove_action( 'rfs_fixture_discover_lock', $discover_lock );
	}
	// Exercise the Ajax endpoint with mocked HTTP, including its permission and nonce boundaries.
	$original_user = get_current_user_id();
	$original_request = $_REQUEST;
	$ajax_die = function() { return function() { throw new RuntimeException( 'rfs-test-ajax-exit' ); }; };
	$call_ajax = function( $nonce ) {
		$_REQUEST['nonce'] = $nonce;
		ob_start();
		try { rfs_handle_check_sync_access(); }
		catch ( RuntimeException $error ) { if ( 'rfs-test-ajax-exit' !== $error->getMessage() ) { ob_end_clean(); throw $error; } }
		return json_decode( ob_get_clean(), true );
	};
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $ajax_die );
	try {
		wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
		ob_start(); rfs_render_sync_lock_notice(); $admin_notice = ob_get_clean();
		rfs_lock_check( str_contains( $admin_notice, 'rfs-check-sync-access' ) && ! str_contains( $admin_notice, 'class="button"' ) && ! str_contains( $admin_notice, 'admin-post.php' ), 'Check sync access must be an Ajax link rather than a redirect button.' );
		$before_ajax = count( $http_requests );
		$call_ajax( 'invalid' );
		rfs_lock_check( count( $http_requests ) === $before_ajax && rfs_is_sync_locked(), 'An invalid Ajax nonce must not contact the API or clear the lock.' );
		wp_set_current_user( 0 );
		$response = $call_ajax( wp_create_nonce( 'rfs_check_sync_access' ) );
		rfs_lock_check( false === $response['success'] && count( $http_requests ) === $before_ajax, 'Users without settings access must not trigger access checks.' );
		wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
		$nonce = wp_create_nonce( 'rfs_check_sync_access' );
		$response = $call_ajax( $nonce );
		rfs_lock_check( false === $response['success'] && 'Syncing is still locked.' === $response['data']['message'] && rfs_is_sync_locked(), 'A denied Ajax recheck must return an inline lock result.' );
		$http_response = new WP_Error( 'timeout', 'Fixture timeout' );
		$response = $call_ajax( $nonce );
		rfs_lock_check( false === $response['success'] && rfs_is_sync_locked(), 'A failed Ajax request must retain the lock.' );
		$respond( 200, array( 'sync_locked' => false ) );
		$response = $call_ajax( $nonce );
		rfs_lock_check( true === $response['success'] && false === $response['data']['sync_locked'] && 'idle' === $response['data']['status'] && ! rfs_is_sync_locked() && 'nosync' === get_option( 'rentfetch_options_data_sync' ), 'A confirmed Ajax unlock must succeed without redirecting or resuming sync.' );
	} finally {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $ajax_die );
		$_REQUEST = $original_request;
		wp_set_current_user( $original_user );
	}
	// The standalone access-check action still preserves an intentional local pause.
	rfs_apply_sync_lock();
	$respond( 200, array( 'sync_locked' => false ) );
	rfs_lock_check( true === rfs_check_sync_access() && ! rfs_is_sync_locked(), 'An explicit access recheck must consume a confirmed API unlock.' );
	rfs_lock_check( 'nosync' === get_option( 'rentfetch_options_data_sync' ), 'Unlocking must leave syncing paused.' );
	$last = end( $http_requests );
	rfs_lock_check( str_ends_with( $last['url'], '/sync-access' ) && array( 'site_url' ) === array_keys( $last['body'] ), 'The access check must send identity only, without credentials.' );
	update_option( 'rentfetch_options_data_sync', 'updatesync' );
	rfs_lock_check( 'updatesync' === get_option( 'rentfetch_options_data_sync' ), 'An unlocked client must be able to resume explicitly.' );
	$respond( 200, array( 'monitoring' => array() ) );
	rfs_lock_check( is_array( rfs_get_info_from_rentfetch_api() ), 'Credential bootstrap must work again after unlock.' );
	// A lock arriving during credential persistence must remove values saved after the first purge.
	rfs_clear_rentfetch_api_info_cache();
	$respond( 200, array( 'monitoring' => array(), 'entrata' => array( 'api_key' => 'late-response-secret' ) ) );
	$lock_during_cache_write = function( $value ) {
		rfs_apply_sync_lock();
		// Model the stale negative option cache held by another PHP request.
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$notoptions = is_array( $notoptions ) ? $notoptions : array();
		$notoptions['rfs_api_sync_locked'] = true;
		wp_cache_set( 'notoptions', $notoptions, 'options' );
		return $value;
	};
	add_filter( 'pre_set_transient_rentfetch_api_info', $lock_during_cache_write );
	try {
		$result = rfs_refresh_info_from_rentfetch_api();
		rfs_lock_check( rfs_is_sync_locked() && ! is_array( $result ) && false === get_transient( 'rentfetch_api_info' ) && false === get_option( 'rentfetch_api_info_last_success' ), 'An in-flight response must not restore credentials after a lock is detected.' );
	} finally {
		remove_filter( 'pre_set_transient_rentfetch_api_info', $lock_during_cache_write );
	}
	delete_option( 'rfs_api_sync_locked' );
	// Yardi resolves its bearer token during runtime validation, after local credentials are built.
	update_option( 'rentfetch_options_enabled_integrations', array( 'yardi' ) );
	$fixture_credential = function() { return 'fixture'; };
	add_filter( 'pre_option_rentfetch_options_yardi_integration_creds_yardi_api_key', $fixture_credential );
	add_filter( 'pre_option_rentfetch_options_yardi_integration_creds_yardi_company_code', $fixture_credential );
	try {
		rfs_clear_rentfetch_api_info_cache();
		$respond( 403, $locked );
		rfs_trigger_specific_api_sync( array( 'integration' => 'yardi', 'property_id' => 'fixture' ) );
		rfs_lock_check( rfs_is_sync_locked(), 'A lock learned during runtime validation must stop the current job without reusing credentials.' );
	} finally {
		remove_filter( 'pre_option_rentfetch_options_yardi_integration_creds_yardi_api_key', $fixture_credential );
		remove_filter( 'pre_option_rentfetch_options_yardi_integration_creds_yardi_company_code', $fixture_credential );
	}

} finally {
	remove_filter( 'pre_http_request', $mock_http, PHP_INT_MAX );
	remove_filter( 'pre_option_rentfetch_options_data_sync', 'rfs_enforce_locked_sync_setting' );
	remove_filter( 'pre_update_option_rentfetch_options_data_sync', 'rfs_enforce_locked_sync_setting' );
	foreach ( $original as $key => $value ) { if ( $missing === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
	foreach ( $transients as $key => $value ) { if ( false === $value[0] ) { delete_transient( $key ); } else { set_transient( $key, $value[0], max( 1, $value[1] - time() ) ); } }
	delete_option( rfs_get_accelerated_sync_cancel_key( $run_id ) );
}
echo "Client sync-lock checks passed (bootstrap, four proxies, cache fallbacks, pause precedence, queued/manual/accelerated work, failures and explicit unlock; all HTTP mocked).\n";
