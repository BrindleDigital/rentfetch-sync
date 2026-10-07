<?php
/** Standalone: php tests/test-rentmanager-server-token.php. No HTTP or database writes. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['rm_options'] = array(
	'rentfetch_options_enabled_integrations' => array( 'rentmanager' ),
	'rentfetch_options_rentmanager_integration_creds_rentmanager_companycode' => 'example',
);
$GLOBALS['rm_transients'] = array();
$GLOBALS['rm_http_body'] = array( 'monitoring' => array() );
class RentManagerSyncRequestCaptured extends RuntimeException {}
class WP_Query { public $found_posts = 0; }
function add_action() {}
function add_filter() {}
function get_option( $name, $default = false ) { return $GLOBALS['rm_options'][$name] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['rm_options'][$name] = $value; }
function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['rm_options'] ) ) { return false; }
	$GLOBALS['rm_options'][$name] = $value;
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['rm_options'][$name] ); }
function get_transient( $name ) { return $GLOBALS['rm_transients'][$name] ?? false; }
function set_transient( $name, $value, $ttl ) { $GLOBALS['rm_transients'][$name] = $value; }
function delete_transient( $name ) { unset( $GLOBALS['rm_transients'][$name] ); }
function wp_using_ext_object_cache() { return true; }
function get_site_url() { return 'https://sync-test.invalid'; }
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
function get_bloginfo( $name ) { return 'version' === $name ? '7.0' : 'Token test'; }
function current_time( $format ) { return '2026-10-07 12:00:00'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function rentfetch_clean_json_string( $value ) { return $value; }
function is_wp_error( $response ) { return false; }
function wp_remote_retrieve_response_code( $response ) { return 200; }
function wp_remote_retrieve_body( $response ) { return json_encode( $GLOBALS['rm_http_body'] ); }
function wp_remote_post( $url, $args ) {
	$GLOBALS['rm_request'] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
	if ( false !== strpos( $url, '/rentmanager/' ) ) { throw new RentManagerSyncRequestCaptured(); }
	return array();
}
function rm_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

require_once dirname( __DIR__ ) . '/lib/api/fetch-rentfetch-api-info.php';
require_once dirname( __DIR__ ) . '/lib/api/get-credentials.php';
require_once dirname( __DIR__ ) . '/lib/start-property-sync.php';
require_once dirname( __DIR__ ) . '/lib/api/rentmanager/api-functions-rentmanager.php';
require_once dirname( __DIR__ ) . '/lib/api/rentmanager/get-properties-from-setting.php';

$credentials = rfs_get_credentials();
rm_check( array( 'rentmanager' => array( 'companycode' => 'example' ) ) === $credentials, 'Runtime credentials must contain the company code without a partner token.' );
rm_check( isset( $GLOBALS['rm_request']['body']['apis_used']['rentmanager'] ), 'Rent Manager-only sites must still register with the central API.' );
rm_check( '' === rfs_get_runtime_sync_credentials_error( 'rentmanager', $credentials ), 'A partner token must not be required for syncing.' );
rm_check( '' !== rfs_get_runtime_sync_credentials_error( 'rentmanager', array() ), 'A company code must still be required.' );
rm_check( '' === rfs_validate_rentfetch_api_info_response( array(), array( 'rentmanager' ) ), 'Bootstrap must accept Rent Manager without client credentials.' );
rm_check( '' !== rfs_validate_rentfetch_api_info_response( array(), array( 'engrain' ) ), 'Other integration validation must remain enforced.' );

foreach ( array( false, 'old-api-token' ) as $legacy_token ) {
	$GLOBALS['rm_http_body'] = array( 'monitoring' => array(), 'engrain' => array( 'api_key' => 'keep-other-key' ) );
	if ( false !== $legacy_token ) { $GLOBALS['rm_http_body']['rentmanager'] = array( 'partner_token' => $legacy_token ); }
	$response = rfs_refresh_info_from_rentfetch_api();
	rm_check( is_array( $response ) && ! isset( $response['rentmanager']['partner_token'] ), 'Bootstrap must discard tokens from old API responses.' );
	foreach ( array( $GLOBALS['rm_transients']['rentfetch_api_info'], $GLOBALS['rm_options']['rentfetch_api_info_last_success'] ) as $cached ) {
		rm_check( ! isset( $cached['rentmanager']['partner_token'] ) && 'keep-other-key' === $cached['engrain']['api_key'], 'Both bootstrap caches must exclude the token and retain other integrations.' );
	}
}

$old_payload = array( 'rentmanager' => array( 'partner_token' => 'legacy-cached-token' ), 'engrain' => array( 'api_key' => 'keep-other-key' ) );
$GLOBALS['rm_transients']['rentfetch_api_info'] = $old_payload;
$GLOBALS['rm_options']['rentfetch_api_info_last_success'] = $old_payload;
rfs_remove_cached_rentmanager_partner_token();
rm_check( false === get_transient( 'rentfetch_api_info' ), 'The legacy transient must be removed.' );
rm_check( ! isset( $GLOBALS['rm_options']['rentfetch_api_info_last_success']['rentmanager']['partner_token'] ), 'The persistent fallback must lose the legacy token.' );
rm_check( 'keep-other-key' === $GLOBALS['rm_options']['rentfetch_api_info_last_success']['engrain']['api_key'], 'Other fallback credentials must remain intact.' );
$fallback = rfs_get_valid_rentfetch_api_info_last_success( array( 'rentmanager', 'engrain' ) );
rm_check( is_array( $fallback ), 'The sanitized fallback must remain valid during an API outage.' );

$args = array( 'credentials' => $credentials, 'property_id' => 'MAIN', 'rentmanager_property_id' => 123 );
foreach ( array( 'properties' => 'rfs_rentmanager_get_property_data', 'unit-types' => 'rfs_rentmanager_get_unit_types_data', 'units' => 'rfs_rentmanager_get_units_data', 'properties-all' => 'rfs_get_rentmanager_properties_from_setting' ) as $route => $handler ) {
	$GLOBALS['rm_request'] = null;
	try { $handler( $args ); } catch ( RentManagerSyncRequestCaptured $exception ) {}
	$request = $GLOBALS['rm_request'];
	rm_check( is_array( $request ) && str_ends_with( $request['url'], '/rentmanager/' . $route ), 'Every proxy request must proceed without a partner token: ' . $route );
	rm_check( 'example' === $request['body']['company_code'] && ! array_key_exists( 'partner_token', $request['body'] ), 'Proxy request bodies must omit the partner token: ' . $route );
	if ( 'units' === $route ) { rm_check( true === $request['body']['lease_availability'], 'Lease availability requests must remain enabled.' ); }
}
echo "Rent Manager client checks passed (four requests, runtime/bootstrap validation, old responses and cache cleanup; no HTTP).\n";
