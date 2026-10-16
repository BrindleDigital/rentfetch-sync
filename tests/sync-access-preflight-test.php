<?php
/** WP-CLI eval-file --skip-plugins --skip-themes. Restores options; all HTTP and providers are mocked. */
$root = dirname( __DIR__ );
require_once dirname( $root ) . '/rentfetch/lib/common/json-utils.php';
require_once $root . '/lib/api/fetch-rentfetch-api-info.php';
require_once $root . '/lib/api/get-credentials.php';
require_once $root . '/lib/start-property-sync.php';
function as_unschedule_all_actions( $hook ) {}
function rfs_do_yardi_sync( $args ) { $GLOBALS['preflight_provider_calls'][] = 'yardi'; }
function rfs_do_entrata_sync( $args ) { $GLOBALS['preflight_provider_calls'][] = 'entrata'; }
function rfs_do_engrain_sync( $args ) { $GLOBALS['preflight_provider_calls'][] = 'engrain'; }
function rfs_do_rentmanager_sync( $args ) { $GLOBALS['preflight_provider_calls'][] = 'rentmanager'; }
function preflight_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$settings = array(
	'rentfetch_options_yardi_integration_creds_yardi_api_key' => 'fixture-client-key',
	'rentfetch_options_yardi_integration_creds_yardi_company_code' => 'fixture-company',
	'rentfetch_options_entrata_integration_creds_entrata_subdomain' => 'fixture',
	'rentfetch_options_rentmanager_integration_creds_rentmanager_companycode' => 'fixture',
);
$keys = array_merge( array_keys( $settings ), array( 'rfs_api_sync_locked', 'rentfetch_options_data_sync', 'rentfetch_options_enabled_integrations', 'rentfetch_api_info_last_success', 'rentfetch_api_info_refresh_lock' ) );
$missing = 'missing-' . wp_generate_uuid4();
$original = array();
foreach ( $keys as $key ) { $original[$key] = get_option( $key, $missing ); }
$transients = array();
foreach ( array( 'rentfetch_api_info', 'rentfetch_api_info_error' ) as $key ) {
	$transients[$key] = array( get_transient( $key ), (int) get_option( '_transient_timeout_' . $key ) );
}
$payload = rtrim( strtr( base64_encode( wp_json_encode( array( 'exp' => time() + DAY_IN_SECONDS ) ) ), '+/', '-_' ), '=' );
$credentials = array( 'yardi' => array( 'access_token' => 'fixture.' . $payload . '.fixture' ), 'entrata' => array( 'api_key' => 'fixture-key' ), 'engrain' => array( 'api_key' => 'fixture-key' ) );
$response = function( $status, $data ) { return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $status, 'message' => 'Fixture' ), 'cookies' => array() ); };
$lock = $response( 403, array( 'code' => 'rentfetch_sync_locked', 'data' => array( 'sync_locked' => true ) ) );
$allowed = $response( 200, array( 'sync_locked' => false ) );
$access_response = $allowed;
$bootstrap_response = $response( 200, $credentials );
$requests = array();
$mock_http = function( $pre, $args, $url ) use ( &$access_response, &$bootstrap_response, &$requests ) {
	$requests[] = basename( $url );
	preflight_check( isset( json_decode( $args['body'], true )['site_url'] ), 'Every access or bootstrap request must identify the site.' );
	if ( str_ends_with( $url, '/sync-access' ) ) { return $access_response; }
	if ( str_ends_with( $url, '/data' ) ) { return $bootstrap_response; }
	throw new RuntimeException( 'Unexpected outbound request.' );
};
$seed = function( $integration ) use ( $credentials, &$requests ) {
	delete_option( 'rfs_api_sync_locked' );
	delete_option( 'rentfetch_api_info_refresh_lock' );
	update_option( 'rentfetch_options_data_sync', 'updatesync' );
	update_option( 'rentfetch_options_enabled_integrations', array( $integration ) );
	rfs_clear_rentfetch_api_info_cache();
	set_transient( 'rentfetch_api_info', $credentials, 1200 );
	update_option( 'rentfetch_api_info_last_success', $credentials, false );
	preflight_check( is_array( rfs_get_info_from_rentfetch_api() ), 'The test must begin with valid persistent and in-request credentials.' );
	$requests = array();
	$GLOBALS['preflight_provider_calls'] = array();
};
add_filter( 'pre_http_request', $mock_http, PHP_INT_MAX, 3 );
try {
	foreach ( $settings as $key => $value ) { update_option( $key, $value ); }
	foreach ( array( 'yardi', 'entrata', 'engrain' ) as $integration ) {
		$args = array( 'integration' => $integration, 'property_id' => 'fixture' );
		$seed( $integration );
		$access_response = $lock;
		rfs_trigger_specific_api_sync( $args );
		preflight_check( array( 'sync-access' ) === $requests && array() === $GLOBALS['preflight_provider_calls'], 'The first locked sync must stop before the provider despite cached credentials: ' . $integration );
		preflight_check( rfs_is_sync_locked() && 'nosync' === get_option( 'rentfetch_options_data_sync' ) && false === get_transient( 'rentfetch_api_info' ) && false === get_option( 'rentfetch_api_info_last_success' ), 'Lock discovery must pause and purge both caches: ' . $integration );
		rfs_trigger_specific_api_sync( $args );
		preflight_check( 1 === count( $requests ), 'Known locks must stop subsequent automatic checks.' );
		$seed( $integration );
		$access_response = $allowed;
		rfs_trigger_specific_api_sync( $args );
		rfs_trigger_specific_api_sync( $args );
		preflight_check( array( 'sync-access', 'sync-access' ) === $requests && array( $integration, $integration ) === $GLOBALS['preflight_provider_calls'], 'Each sync needs its own fresh check, even within one PHP request: ' . $integration );
		preflight_check( 'updatesync' === get_option( 'rentfetch_options_data_sync' ), 'A successful automatic check must not pause syncing.' );
		foreach ( array( new WP_Error( 'timeout', 'Fixture timeout' ), $response( 500, array() ), $response( 403, array( 'code' => 'unrecognized' ) ), $response( 404, array( 'code' => 'unrecognized' ) ), $response( 200, array() ), $response( 200, array( 'sync_locked' => 'false' ) ) ) as $failure ) {
			$seed( $integration );
			$access_response = $failure;
			$threw = false;
			try { rfs_trigger_specific_api_sync( $args ); } catch ( RuntimeException $error ) { $threw = true; }
			preflight_check( $threw && array() === $GLOBALS['preflight_provider_calls'] && array( 'sync-access' ) === $requests, 'Unconfirmed access must not use cached credentials or fall back to bootstrap: ' . $integration );
			preflight_check( ! rfs_is_sync_locked() && 'updatesync' === get_option( 'rentfetch_options_data_sync' ), 'Temporary failures must not permanently lock or pause the site.' );
		}
		foreach ( array( $response( 404, array( 'code' => 'rest_no_route' ) ), $response( 403, array( 'code' => 'rentfetch_site_unregistered' ) ) ) as $fallback ) {
			foreach ( array( 'allowed', 'locked', 'unavailable' ) as $bootstrap_case ) {
				$seed( $integration );
				$access_response = $fallback;
				$bootstrap_response = 'allowed' === $bootstrap_case ? $response( 200, $credentials ) : ( 'locked' === $bootstrap_case ? $lock : new WP_Error( 'timeout', 'Fixture timeout' ) );
				$threw = false;
				try { rfs_trigger_specific_api_sync( $args ); } catch ( RuntimeException $error ) { $threw = true; }
				preflight_check( array( 'sync-access', 'data' ) === $requests, 'Older APIs and unregistered sites must use a fresh bootstrap, never a cached fallback.' );
				preflight_check( ( 'allowed' === $bootstrap_case ? array( $integration ) : array() ) === $GLOBALS['preflight_provider_calls'], 'Only a successful fresh bootstrap may authorize the provider: ' . $bootstrap_case );
				preflight_check( ( 'unavailable' === $bootstrap_case ) === $threw && ( 'locked' === $bootstrap_case ) === rfs_is_sync_locked(), 'Bootstrap failures must preserve correct lock versus retry behavior.' );
			}
		}
	}
	$seed( 'rentmanager' );
	rfs_trigger_specific_api_sync( array( 'integration' => 'rentmanager', 'property_id' => 'fixture' ) );
	preflight_check( array() === $requests && array( 'rentmanager' ) === $GLOBALS['preflight_provider_calls'], 'Rent Manager must not add an access request; its proxies already enforce locks.' );
} finally {
	remove_filter( 'pre_http_request', $mock_http, PHP_INT_MAX );
	foreach ( $original as $key => $value ) { if ( $missing === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
	foreach ( $transients as $key => $value ) { if ( false === $value[0] ) { delete_transient( $key ); } else { set_transient( $key, $value[0], max( 1, $value[1] - time() ) ); } }
}
echo "Fresh access checks passed for all direct-provider syncs, cached credentials, repeated jobs, outages, legacy APIs, and new registration; Rent Manager adds no check. All HTTP mocked.\n";
