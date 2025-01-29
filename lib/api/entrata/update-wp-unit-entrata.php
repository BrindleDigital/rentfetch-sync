<?php
/**
 * Functions to update unit data in WordPress from the Yardi API (v2)
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

function rfs_entrata_update_unit_meta( $args, $unit_data ) {

	// bail if we don't have the WordPress post ID.
	if ( ! isset( $args['wordpress_unit_post_id'] ) || ! $args['wordpress_unit_post_id'] ) {
		return;
	}
	
	$unit_id = $unit_data['@attributes']['PropertyUnitId'];
	
	// bail if we don't have the data to update this, updating the meta to give the error.
	if ( ! $unit_data['@attributes']['PropertyUnitId'] ) {

		$unit_data_string = wp_json_encode( $unit_data );

		$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );

		if ( ! is_array( $api_response ) ) {
			$api_response = array();
		}

		$api_response['getUnitsAvailabilityAndPricing'] = array(
			'updated'      => current_time( 'mysql' ),
			'api_response' => $unit_data_string,
		);

		$success = update_post_meta( $args['wordpress_unit_post_id'], 'api_response', $api_response );

		return;
	}

	// * Update the title
	$post_info = array(
		'ID'         => (int) $args['wordpress_unit_post_id'],
		'post_title' => esc_html( $unit_data['@attributes']['UnitNumber'] ),
		'post_name'  => sanitize_title( $unit_data['@attributes']['UnitNumber'] ), // update the permalink to match the new title.
	);

	wp_update_post( $post_info );

	$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );

	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	$api_response['getUnitsAvailabilityAndPricing'] = array(
		'updated'      => current_time( 'mysql' ),
		'api_response' => wp_json_encode( $unit_data ),
	);
	
	// process square feed, removing ' SquareFeet' from the string
	$square_feet = $unit_data['@attributes']['Area'];
	$square_feet = (int) str_replace( ' SquareFeet', '', $square_feet );
	
	// process the rent numbers, removing the $ and , characters
	$min_rent = $unit_data['Rent']['@attributes']['MinRent'];
	$min_rent = (int) str_replace( array( '$', ',' ), '', $min_rent );
	
	$max_rent = $unit_data['Rent']['@attributes']['MaxRent'];
	$max_rent = (int) str_replace( array( '$', ',' ), '', $max_rent );
	
	// process the deposit number, removing the $ and , characters
	$deposit = $unit_data['Deposit']['@attributes']['MinDeposit'];
	$deposit = (int) str_replace( array( '$', ',' ), '', $deposit );
	
	$meta = array(
		'unit_id' => $unit_data['@attributes']['PropertyUnitId'],
		'floorplan_id' => $unit_data['@attributes']['FloorplanId'],
		'property_id' => $unit_data['@attributes']['PropertyId'],
		// 'apply_online_url' => $unit_data['@attributes']['applyOnlineURL'],
		'availability_date' => $unit_data['@attributes']['AvailableOn'],
		// 'baths' => $unit_data['@attributes']['baths'],
		// 'beds' => $unit_data['@attributes']['beds'],
		'deposit' => $deposit,
		'minimum_rent' => $min_rent,
		'maximum_rent' => $max_rent,
		'sqrft' => $square_feet,
		// 'amenities' => $unit_data['@attributes']['amenities'],
		// 'specials' => $unit_data['@attributes']['specials'],
		'unit_source' => 'entrata',
		'api_response' => $api_response,
	);
	
	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_unit_post_id'], $key, $value );
	}
}