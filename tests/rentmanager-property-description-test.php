<?php
/** Standalone Sync checks; no HTTP or database. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error { function get_error_message() { return 'Network error'; } }
function is_wp_error( $r ) { return $r instanceof WP_Error; }
function rfs_is_sync_locked() { return ! empty( $GLOBALS['locked'] ); }
function rfs_rentfetch_api_post( $route, $params, $timeout ) { $GLOBALS['requests'][] = array( $route, $params, $timeout ); return $GLOBALS['response']; }
function wp_remote_retrieve_response_code( $r ) { return $r['status']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function current_time( $format ) { return '2026-11-15 12:00:00'; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][$id][$key] = is_array( $value ) ? array_map( 'wp_unslash', $value ) : wp_unslash( $value ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function wp_kses_post( $v ) { return strip_tags( $v, '<p><strong><br>' ); }
function rfs_mark_sync_succeeded( $id, $endpoint ) { $GLOBALS['state'] = 'success'; }
function rfs_mark_sync_failed( $id, $endpoint ) { $GLOBALS['state'] = 'failed'; }
function rfs_mark_sync_partial( $id, $endpoint ) { $GLOBALS['state'] = 'partial'; }
function check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function respond( $status, $data ) { $GLOBALS['response'] = array( 'status' => $status, 'body' => json_encode( $data ) ); }
require dirname( __DIR__ ) . '/lib/api/rentmanager/property-description-rentmanager.php';
$args = array( 'wordpress_property_post_id' => 383043, 'rentmanager_property_id' => 11, 'credentials' => array( 'rentmanager' => array( 'companycode' => 'HIGH' ) ) );
$GLOBALS['meta'][383043] = array( 'description' => 'Existing', 'api_response' => array( 'properties_api' => array( 'preserve' => true ) ) );
respond( 200, array( 'PropertyID' => 11, 'Description' => '<p>Park \\ lakes</p><iframe>unsafe</iframe>' ) );
rfs_rentmanager_sync_property_description( $args );
check( '<p>Park \\ lakes</p>unsafe' === $GLOBALS['meta'][383043]['description'] && 'success' === $GLOBALS['state'], 'Import sanitized copy without losing backslashes.' );
check( json_decode( $GLOBALS['meta'][383043]['api_response']['property_description_api']['api_response'], true )['Description'] === '<p>Park \\ lakes</p><iframe>unsafe</iframe>', 'Diagnostic JSON survives WordPress unslashing.' );
check( isset( $GLOBALS['meta'][383043]['api_response']['properties_api'], $GLOBALS['meta'][383043]['api_response']['property_description_api'] ), 'Keep existing diagnostics alongside description diagnostics.' );
check( array( 'rentmanager/property-description', array( 'company_code' => 'HIGH', 'property_id' => 11 ), 90 ) === end( $GLOBALS['requests'] ), 'Use numeric property ID and allow proxy time to finish.' );
respond( 200, array( 'PropertyID' => 11, 'Description' => '' ) ); rfs_rentmanager_sync_property_description( $args );
check( '' === $GLOBALS['meta'][383043]['description'] && 'success' === $GLOBALS['state'], 'A successful source blank clears the saved description.' );
$GLOBALS['meta'][383043]['description'] = 'Preserve me';
foreach ( array( array( 'PropertyID' => 2, 'Description' => 'Wrong property' ), array( 'PropertyID' => true, 'Description' => 'Invalid' ), array( 'PropertyID' => 11 ), array( 'PropertyID' => 11, 'Description' => array() ), null ) as $bad ) {
	respond( 200, $bad ); rfs_rentmanager_sync_property_description( $args );
	check( 'Preserve me' === $GLOBALS['meta'][383043]['description'] && 'failed' === $GLOBALS['state'], 'Malformed or mismatched responses preserve copy.' );
}
foreach ( array( 403, 429, 500, 502 ) as $status ) {
	respond( $status, array( 'code' => 'provider_error' ) ); rfs_rentmanager_sync_property_description( $args );
	check( 'Preserve me' === $GLOBALS['meta'][383043]['description'] && 'failed' === $GLOBALS['state'], 'HTTP errors preserve copy.' );
}
foreach ( array( 'rest_no_route', 'rentmanager_property_not_listed' ) as $code ) {
	respond( 404, array( 'code' => $code ) ); rfs_rentmanager_sync_property_description( $args );
	check( 'Preserve me' === $GLOBALS['meta'][383043]['description'] && 'partial' === $GLOBALS['state'], 'Older APIs and absent listings remain non-destructive.' );
}
$GLOBALS['response'] = new WP_Error(); rfs_rentmanager_sync_property_description( $args );
check( 'Preserve me' === $GLOBALS['meta'][383043]['description'] && 'failed' === $GLOBALS['state'], 'Transport errors preserve copy.' );
$GLOBALS['locked'] = true; $n = count( $GLOBALS['requests'] ); rfs_rentmanager_sync_property_description( $args );
check( count( $GLOBALS['requests'] ) === $n, 'Locked sync makes no request.' );
echo "Rent Manager property description sync checks passed.\n";
