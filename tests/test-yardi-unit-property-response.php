<?php
/**
 * Run with: php -d zend.assertions=1 -d assert.exception=1 tests/test-yardi-unit-property-response.php
 */

define( 'ABSPATH', __DIR__ );

$rfs_test_meta = array();

function get_post_meta( $post_id, $key, $single ) {
	global $rfs_test_meta;
	return $rfs_test_meta[ $post_id ][ $key ] ?? '';
}

function update_post_meta( $post_id, $key, $value ) {
	global $rfs_test_meta;
	$rfs_test_meta[ $post_id ][ $key ] = $value;
}

function current_time( $type ) {
	return '2026-08-02 12:00:00';
}

function sanitize_text_field( $value ) {
	return (string) $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

require_once dirname( __DIR__ ) . '/lib/api/yardi/v2/get-api-unit-data-v2.php';

$args = array( 'wordpress_property_post_id' => 123 );
$rfs_test_meta[123]['api_response']['properties_api'] = array( 'status_code' => 200 );

rfs_yardi_v2_update_property_units_api_response(
	$args,
	array(
		'reason'       => 'blank_response',
		'delete_safe'  => true,
		'status_code'  => 204,
		'raw_response' => '',
	)
);

assert( 204 === $rfs_test_meta[123]['api_response']['apartmentavailability_api']['status_code'] );
assert( 'blank_response' === $rfs_test_meta[123]['api_response']['apartmentavailability_api']['reason'] );

rfs_yardi_v2_update_property_units_api_response( $args );

assert( ! isset( $rfs_test_meta[123]['api_response']['apartmentavailability_api'] ) );
assert( 200 === $rfs_test_meta[123]['api_response']['properties_api']['status_code'] );
