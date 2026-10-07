<?php
/** Standalone: php tests/test-sync-pause-bootstrap.php. Captures requests without HTTP or database writes. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$GLOBALS['rfs_pause_setting'] = 'nosync';
$GLOBALS['rfs_pause_request'] = null;
class RfsPauseRequestCaptured extends RuntimeException {}
class WP_Query { public $found_posts = 0; }
function get_option( $name, $default = false ) {
    if ( 'rentfetch_options_data_sync' === $name ) { return $GLOBALS['rfs_pause_setting']; }
    if ( 'rentfetch_options_enabled_integrations' === $name ) { return array(); }
    return $default;
}
function get_site_url() { return 'https://sync-test.invalid'; }
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
function get_bloginfo( $name ) { return 'version' === $name ? '7.0' : 'Pause test'; }
function current_time( $format ) { return '2026-10-06 12:00:00'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_remote_post( $url, $args ) {
    $GLOBALS['rfs_pause_request'] = json_decode( $args['body'], true );
    if ( 'application/json' !== $args['headers']['Content-Type'] ) { throw new RuntimeException( 'Expected JSON.' ); }
    throw new RfsPauseRequestCaptured();
}
require_once dirname( __DIR__ ) . '/lib/api/fetch-rentfetch-api-info.php';
foreach ( array( array( 'nosync', true ), array( 'updatesync', false ), array( false, true ) ) as $case ) {
    $GLOBALS['rfs_pause_setting'] = $case[0];
    $GLOBALS['rfs_pause_request'] = null;
    try { rfs_refresh_info_from_rentfetch_api(); } catch ( RfsPauseRequestCaptured $exception ) {}
    $request = $GLOBALS['rfs_pause_request'];
    if ( ! is_array( $request ) || $request['sync_paused'] !== $case[1] || 'sync-test.invalid' !== $request['site_url'] || ! isset( $request['apis_used']['manual'] ) ) {
        throw new RuntimeException( 'The JSON bootstrap must preserve its existing fields and report a boolean pause setting.' );
    }
}
echo "Sync bootstrap pause/resume tests passed (no HTTP or database).\n";
