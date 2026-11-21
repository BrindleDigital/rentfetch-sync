<?php
/** Real Core/Sync bootstrap with a separate WordPress API process and fixture providers. */
if ( ! str_contains( ABSPATH, '/rentfetch-followup-test-' ) ) {
	throw new RuntimeException( 'Disposable site required.' );
}
$case = json_decode( getenv( 'RF_MIXED_CASE' ), true );
$requests = array();
$checks = 0;
$check = static function( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $admins[0] );
add_filter( 'pre_http_request', static function( $pre, $args, $url ) use ( &$requests ) {
	$path = wp_parse_url( $url, PHP_URL_PATH );
	$requests[] = $path;
	if ( str_contains( $path, '/wp-json/rentfetchapi/v1/' ) ) {
		putenv( 'RF_MIXED_API_REQUEST=' . wp_json_encode( array( 'path' => substr( $path, strlen( '/wp-json' ) ), 'body' => $args['body'] ?? '{}' ) ) );
		$process = proc_open( array( 'wp', '--path=' . ABSPATH, '--skip-plugins', '--skip-themes', 'eval-file', __DIR__ . '/mixed-version-api.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start API worker.' ); }
		$out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) || ! preg_match( '/^MIXED_API_RESULT=(.+)$/m', $out, $matches ) ) {
			throw new RuntimeException( 'API worker failed: ' . $out . $err );
		}
		$data = json_decode( $matches[1], true );
		return array( 'response' => array( 'code' => $data['status'] ), 'headers' => array(), 'body' => wp_json_encode( $data['data'] ) );
	}
	if ( 'basic.rentcafeapi.com' === wp_parse_url( $url, PHP_URL_HOST ) ) {
		if ( '/property/getmarketingdetails' === $path ) {
			$data = array( 'properties' => array( array( 'propertyId' => 123, 'name' => 'Mixed-version Yardi', 'address' => '1 Fixture Street', 'city' => 'Fixture', 'state' => 'WA', 'zipCode' => '00000' ) ) );
		} elseif ( '/images/getpropertyimages' === $path ) {
			$data = array( 'images' => array() );
		} elseif ( str_contains( $path, '/leasefeedetails/' ) ) {
			$data = array();
		} else {
			return array( 'response' => array( 'code' => 204 ), 'headers' => array(), 'body' => '' );
		}
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $data ) );
	}
	return new WP_Error( 'mixed_http_denied', 'Unexpected outbound HTTP blocked.' );
}, PHP_INT_MAX, 3 );

$check( defined( 'RENTFETCH_VERSION' ) && function_exists( 'rfs_sync_single_property' ), 'Plugin bootstrap failed.' );
$bootstrap = rfs_refresh_info_from_rentfetch_api();
$check( is_array( $bootstrap ), 'Old/new client/API bootstrap failed: ' . ( is_string( $bootstrap ) ? $bootstrap : gettype( $bootstrap ) ) );
rfs_sync_single_property( 'MIXED_YARDI', 'yardi' );
$yardi = get_posts( array( 'post_type' => 'properties', 'fields' => 'ids', 'meta_key' => 'property_id', 'meta_value' => 'MIXED_YARDI' ) );
$check( 1 === count( $yardi ) && 'Mixed-version Yardi' === get_the_title( $yardi[0] ), 'Established integration stopped syncing.' );

$start = count( $requests );
$exception = null;
try { rfs_sync_single_property( '184AST', 'rentegi' ); } catch ( RuntimeException $error ) { $exception = $error; }
$works = 'new' === $case['core'] && 'new' === $case['sync'] && 'new' === $case['api'];
$blocked = 'old' === $case['core'] && 'new' === $case['sync'];
if ( $works ) {
	$check( null === $exception, 'RentEGI failed with all dependencies present.' );
	$check( 'Astoria' === get_the_title( (int) get_option( 'mixed_saved_properties' ) ), 'RentEGI property was not updated.' );
	$check( null === get_post( (int) get_option( 'mixed_saved_units' ) ), 'Complete successful inventory did not clean up missing unit.' );
} else {
	foreach ( array( 'properties', 'floorplans', 'units' ) as $type ) {
		$post = get_post( (int) get_option( 'mixed_saved_' . $type ) );
		$check( $post && 'Saved RentEGI fixture' === $post->post_title && 'Preserve this content' === $post->post_content, 'Unsupported combination changed saved inventory: ' . $type );
	}
	if ( 'new' === $case['sync'] && 'new' === $case['core'] ) {
		$check( $exception instanceof RuntimeException, 'Missing API route must report a controlled sync error.' );
	}
}
if ( $blocked ) {
	$check( $start === count( $requests ), 'Blocked RentEGI job attempted provider/API access.' );
	$error = rfs_get_manual_sync_target( (int) get_option( 'mixed_saved_properties' ) );
	$check( is_wp_error( $error ) && 'rfs_rentegi_core_update_required' === $error->get_error_code(), 'Manual sync lacks an update-required response.' );
	ob_start(); rfs_rentegi_core_update_notice(); $notice = ob_get_clean();
	$check( str_contains( $notice, 'Update Rent Fetch' ), 'Missing update notice.' );
	update_option( 'rentfetch_options_rentegi_property_codes', '' );
	rfs_rentegi_delete_unconfigured_properties();
	$check( (bool) get_post( (int) get_option( 'mixed_saved_properties' ) ), 'Blocked cleanup deleted existing inventory.' );
	update_option( 'rentfetch_options_rentegi_property_codes', '184AST' );
	try { rfs_do_rentegi_sync( array( 'property_id' => '184AST' ) ); $check( false, 'Direct RentEGI sync bypassed the guard.' ); } catch ( RuntimeException $error ) {
		$check( str_contains( $error->getMessage(), 'Update Rent Fetch' ), 'Wrong direct-call compatibility error.' );
	}
}
if ( 'new' === $case['sync'] ) {
	$manifest = rfs_get_accelerated_sync_manifest();
	$rentegi = array_filter( $manifest, static function( $item ) { return 'rentegi' === ( $item['integration'] ?? '' ); } );
	$check( $blocked ? empty( $rentegi ) : ! empty( $rentegi ), 'Accelerated manifest did not respect Core compatibility.' );
	update_option( 'rentfetch_options_data_sync', 'updatesync' );
	rfs_perform_syncs();
	$scheduled = as_has_scheduled_action( 'rfs_do_sync', array( array( 'integration' => 'yardi', 'property_id' => 'MIXED_YARDI' ) ), 'rentfetch' );
	$check( false !== $scheduled, 'Guard prevented ordinary provider scheduling.' );
	$scheduled = as_has_scheduled_action( 'rfs_do_sync', array( array( 'integration' => 'rentegi', 'property_id' => '184AST' ) ), 'rentfetch' );
	$check( $blocked ? false === $scheduled : false !== $scheduled, 'Recurring RentEGI work did not respect the guard.' );
	$expected = rfs_get_sync_cache_expected_items();
	$check( ! $blocked || ! isset( $expected['rfs_rentegi_do_delete_orphans'] ), 'Cache completion waits on blocked RentEGI work.' );
	update_option( 'rentfetch_options_data_sync', 'nosync' );
}
ob_start(); rentfetch_settings_sync(); $settings = ob_get_clean();
if ( $blocked ) {
	$dom = new DOMDocument(); @$dom->loadHTML( $settings ); $xpath = new DOMXPath( $dom );
	$check( 1 === $xpath->query( '//input[@id="rentfetch_options_enabled_integrations_rentegi" and @disabled]' )->length, 'Blocked settings checkbox is not disabled.' );
	$check( 1 === $xpath->query( '//input[@type="hidden" and @name="rentfetch_options_enabled_integrations[]" and @value="rentegi"]' )->length, 'Saving unrelated settings would discard enabled state.' );
}
rentfetch_set_floorplans();
rentfetch_get_monitoring_property_rollups();
echo 'MIXED_CLIENT_RESULT=' . wp_json_encode( array( 'case' => $case, 'checks' => $checks, 'rentegi' => $works ? 'synced' : ( $blocked ? 'core_guard' : ( $exception ? 'api_unavailable_preserved' : 'old_sync_ignored' ) ), 'yardi' => 'synced', 'requests' => count( $requests ) ) ) . PHP_EOL;
