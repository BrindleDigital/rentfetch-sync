<?php
/**
 * Functions to update unit data in WordPress from the Yardi API (v2)
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

function rfs_yardi_v2_update_unit_meta( $args, $unit_data ) {

	// bail if we don't have the WordPress post ID.
	if ( ! isset( $args['wordpress_unit_post_id'] ) || ! $args['wordpress_unit_post_id'] ) {
		return;
	}
	
	$apartmentID = $unit_data['apartmentId'];
	
	// bail if we don't have the data to update this, updating the meta to give the error.
	if ( ! $unit_data['apartmentId'] ) {

		$unit_data_string = wp_json_encode( $unit_data );

		$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );

		if ( ! is_array( $api_response ) ) {
			$api_response = array();
		}

		$api_response['units_api'] = array(
			'updated'      => current_time( 'mysql' ),
			'api_response' => $unit_data_string,
		);

		$success = update_post_meta( $args['wordpress_unit_post_id'], 'api_response', $api_response );

		return;
	}

	// * Update the title
	$post_info = array(
		'ID'         => (int) $args['wordpress_unit_post_id'],
		'post_title' => esc_html( $unit_data['apartmentName'] ),
		'post_name'  => sanitize_title( $unit_data['apartmentName'] ), // update the permalink to match the new title.
	);

	wp_update_post( $post_info );

	$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );

	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	$api_response['units_api'] = array(
		'updated'      => current_time( 'mysql' ),
		'api_response' => wp_json_encode( $unit_data ),
	);
	
	$meta = array(
		'unit_id' => $unit_data['apartmentId'],
		'floorplan_id' => $unit_data['floorplanId'],
		'property_id' => $args['property_id'],
		'apply_online_url' => $unit_data['applyOnlineURL'],
		'availability_date' => $unit_data['availableDate'],
		'baths' => $unit_data['baths'],
		'beds' => $unit_data['beds'],
		'deposit' => $unit_data['deposit'],
		'minimum_rent' => $unit_data['minimumRent'],
		'maximum_rent' => $unit_data['maximumRent'],
		'sqrft' => $unit_data['sqft'],
		'amenities' => $unit_data['amenities'],
		'specials' => $unit_data['specials'],
		'unit_source' => 'yardi',
		'api_response' => $api_response,
	);
	
	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_unit_post_id'], $key, $value );
	}
}