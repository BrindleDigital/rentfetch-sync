<?php
/**
 * Run with: php -d zend.assertions=1 -d assert.exception=1 tests/test-yardi-floorplan-204.php
 */

define( 'ABSPATH', __DIR__ );

$rfs_test_deleted = array();
$rfs_test_unit_fetches = 0;

function rfs_get_yardi_bearer_token() {
	return 'token';
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_remote_post( $url, $args ) {
	return array(
		'response' => array( 'code' => 204 ),
		'body'     => '',
	);
}

function is_wp_error( $response ) {
	return false;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function rentfetch_clean_json_string( $value ) {
	return $value;
}

function get_posts( $args ) {
	return 'floorplans' === $args['post_type'] ? array( (object) array( 'ID' => 11 ) ) : array( (object) array( 'ID' => 22 ) );
}

function wp_delete_post( $post_id, $force_delete ) {
	global $rfs_test_deleted;
	$rfs_test_deleted[] = $post_id;
}

function rfs_maybe_create_property( $args ) {
	return $args;
}

function rfs_yardi_v2_get_property_data( $args ) {
	return array();
}

function rfs_yardi_v2_update_property_meta( $args, $data ) {}

function rfs_yardi_v2_get_property_images( $args ) {
	return array();
}

function rfs_yardi_v2_update_property_images( $args, $data ) {}

function rfs_yardi_v2_get_property_lease_fees( $args, $data ) {
	return array();
}

function rfs_yardi_v2_update_property_lease_fees( $args, $data ) {}

function rfs_yardi_v2_update_property_amenities( $args, $data ) {}

function rfs_yardi_v2_get_unit_data( $args ) {
	global $rfs_test_unit_fetches;
	++$rfs_test_unit_fetches;
	return array();
}

require_once dirname( __DIR__ ) . '/lib/api/yardi/v2/get-api-floorplan-data-v2.php';
require_once dirname( __DIR__ ) . '/lib/api/yardi/v2/update-wp-floorplan-yardi-v2.php';
require_once dirname( __DIR__ ) . '/lib/api/yardi/api-functions-yardi.php';

$args = array(
	'property_id' => 'property-1',
	'credentials' => array(
		'yardi' => array(
			'apikey'       => 'key',
			'company_code' => 'company',
			'vendor'       => 'vendor',
		),
	),
);

$response = rfs_yardi_v2_get_floorplan_data( $args );

assert( true === $response['success'] );
assert( true === $response['delete_safe'] );
assert( array() === $response['floorplans'] );
assert( 204 === $response['status_code'] );

rfs_yardi_v2_delete_orphan_floorplans( $args, $response );
rfs_yardi_v2_delete_orphan_units( $args, $response );

assert( array( 11, 22 ) === $rfs_test_deleted );

$rfs_test_deleted = array();
rfs_do_yardi_sync( $args );

assert( array( 11, 22 ) === $rfs_test_deleted );
assert( 0 === $rfs_test_unit_fetches );
