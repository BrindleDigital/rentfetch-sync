<?php
/** Disposable Cove site only: real roles, records, nonce and AJAX handlers; no provider HTTP. */
if ( ! defined( 'RFS_DISPOSABLE_SITE' ) || ! RFS_DISPOSABLE_SITE || ! preg_match( '/rentfetch-.*test-/', ABSPATH ) ) {
	throw new RuntimeException( 'Run only in the explicitly marked disposable Cove test site.' );
}
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
require_once dirname( __DIR__, 2 ) . '/rentfetch/rentfetch.php';
require_once dirname( __DIR__ ) . '/rentfetch-sync.php';
rfs_boot_plugin();
rentfetch_register_properties_cpt(); rentfetch_register_floorplans_cpt(); rentfetch_register_units_cpt();
require_once ABSPATH . 'wp-admin/includes/user.php';
function role_check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
class ManualSyncTestExit extends RuntimeException {}
$die_handler = static function() { return static function() { throw new ManualSyncTestExit(); }; };
add_filter( 'wp_die_ajax_handler', $die_handler, PHP_INT_MAX );
$status = 200;
$status_handler = static function( $header, $code ) use ( &$status ) { $status = $code; return $header; };
add_filter( 'status_header', $status_handler, PHP_INT_MAX, 2 );
$http_calls = 0;
$http_guard = static function() use ( &$http_calls ) { ++$http_calls; return new WP_Error( 'fixture_no_http', 'HTTP is forbidden in permission tests.' ); };
add_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );
$code = 'ROLE_TEST_' . wp_generate_password( 8, false );
$enabled = static function() { return array( 'rentegi' ); };
$codes = static function() use ( $code ) { return $code; };
add_filter( 'pre_option_rentfetch_options_enabled_integrations', $enabled );
add_filter( 'pre_option_rentfetch_options_rentegi_property_codes', $codes );
$original_user = get_current_user_id();
$admin = get_users( array( 'role'=>'administrator', 'number'=>1, 'fields'=>'ID' ) )[0];
wp_set_current_user( $admin );
$posts = array(); $users = array(); $provider_calls = 0;
remove_all_actions( 'rfs_do_sync' );
add_action( 'rfs_do_sync', static function( $args ) use ( &$provider_calls, $code ) {
	role_check( 'rentegi' === $args['integration'] && $code === $args['property_id'], 'Sync must use resolved server-side target.' );
	++$provider_calls;
} );
$invoke = static function( $handler, $params ) use ( &$status ) {
	$status = 200; $_POST = $params + array( '_ajax_nonce' => wp_create_nonce( 'rfs_ajax_nonce' ) ); $_REQUEST = $_POST;
	ob_start();
	try { $handler(); } catch ( ManualSyncTestExit $exit ) {} finally { $json = ob_get_clean(); }
	$data = json_decode( $json, true );
	role_check( is_array( $data ), 'Handler must return JSON.' );
	return array( $status, $data );
};
$make_post = static function( $type, $meta ) use ( &$posts, $admin ) {
	$id = wp_insert_post( array( 'post_type'=>$type, 'post_title'=>'Permission fixture', 'post_status'=>'publish', 'post_author'=>$admin, 'meta_input'=>$meta ), true );
	role_check( ! is_wp_error( $id ), 'Create fixture record.' ); $posts[]=$id; return $id;
};
$progress_key = 'rfs_sync_progress_' . md5( 'rentegi_' . $code );
try {
	$parent = $make_post( 'properties', array( 'property_id'=>$code, 'property_source'=>'rentegi' ) );
	$plan = $make_post( 'floorplans', array( 'property_id'=>$code, 'floorplan_id'=>'PLAN', 'floorplan_source'=>'rentegi' ) );
	$unit = $make_post( 'units', array( 'property_id'=>$code, 'floorplan_id'=>'PLAN', 'unit_id'=>'UNIT', 'unit_source'=>'rentegi' ) );
	set_transient( $progress_key, array( 'step'=>1, 'total'=>2 ), 60 );
	foreach ( array( 'subscriber', 'author', 'editor', 'administrator' ) as $role ) {
		$user = wp_insert_user( array( 'user_login'=>strtolower( $code . '_' . $role ), 'user_pass'=>wp_generate_password(), 'role'=>$role ) );
		role_check( ! is_wp_error( $user ), 'Create fixture role.' ); $users[]=$user; wp_set_current_user( $user );
		$allowed = in_array( $role, array( 'editor', 'administrator' ), true );
		foreach ( array( $parent, $plan, $unit ) as $id ) {
			$args = array( 'post_id'=>$id, 'property_id'=>$code, 'integration'=>'rentegi' );
			$before = $provider_calls;
			list( $response_status ) = $invoke( 'rfs_sync_single_property_ajax_handler', $args );
			role_check( ( $allowed ? 200 : 403 ) === $response_status && $provider_calls - $before === ( $allowed ? 1 : 0 ), 'Manual sync role matrix failed for ' . $role . ': ' . wp_json_encode( array( $response_status, $provider_calls - $before ) ) );
			list( $response_status ) = $invoke( 'rfs_get_sync_progress_ajax_handler', $args );
			role_check( ( $allowed ? 200 : 403 ) === $response_status, 'Progress role matrix failed for ' . $role );
		}
	}
	wp_set_current_user( $users[2] );
	$before = $provider_calls;
	foreach ( array(
		array( 'post_id'=>$unit, 'property_id'=>'FORGED', 'integration'=>'rentegi' ),
		array( 'post_id'=>$unit, 'property_id'=>$code, 'integration'=>'yardi' ),
		array( 'property_id'=>$code, 'integration'=>'rentegi' ),
		array( 'post_id'=>0, 'property_id'=>$code, 'integration'=>'rentegi' ),
	) as $args ) {
		foreach ( array( 'rfs_sync_single_property_ajax_handler', 'rfs_get_sync_progress_ajax_handler' ) as $handler ) {
			list( $response_status ) = $invoke( $handler, $args ); role_check( 400 === $response_status, 'Forged/invalid target must be rejected.' );
		}
	}
	$deny_parent = static function( $caps, $cap, $user_id, $args ) use ( $parent ) { return 'edit_post' === $cap && (int)($args[0]??0) === $parent ? array( 'do_not_allow' ) : $caps; };
	add_filter( 'map_meta_cap', $deny_parent, PHP_INT_MAX, 4 );
	list( $response_status ) = $invoke( 'rfs_sync_single_property_ajax_handler', array( 'post_id'=>$unit, 'property_id'=>$code, 'integration'=>'rentegi' ) );
	role_check( 403 === $response_status, 'Parent permission must be enforced for a child button.' );
	remove_filter( 'map_meta_cap', $deny_parent, PHP_INT_MAX );
	wp_set_current_user( $admin );
	$duplicate = $make_post( 'properties', array( 'property_id'=>$code, 'property_source'=>'rentegi' ) );
	list( $response_status ) = $invoke( 'rfs_get_sync_progress_ajax_handler', array( 'post_id'=>$unit, 'property_id'=>$code, 'integration'=>'rentegi' ) );
	role_check( 400 === $response_status && $before === $provider_calls, 'Ambiguous/forged targets must not invoke a provider.' );
	role_check( 0 === $http_calls, 'No permission test may reach HTTP.' );
	echo "Real WordPress manual-sync/progress role matrix passed: Editors/Admins allowed; Authors/Subscribers, forged targets, inaccessible parents and ambiguous parents denied before provider work.\n";
} finally {
	wp_set_current_user( $admin );
	delete_transient( $progress_key );
	foreach ( array_reverse( $posts ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( $users as $id ) { wp_delete_user( $id ); }
	remove_filter( 'wp_die_ajax_handler', $die_handler, PHP_INT_MAX );
	remove_filter( 'status_header', $status_handler, PHP_INT_MAX );
	remove_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );
	remove_filter( 'pre_option_rentfetch_options_enabled_integrations', $enabled );
	remove_filter( 'pre_option_rentfetch_options_rentegi_property_codes', $codes );
	wp_set_current_user( $original_user );
}
