<?php
/**
 * Functions to update unit data in WordPress from the Yardi API (v2)
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

function rfs_entrata_update_unit_meta( $args, $unit_data, $property_mits_data ) {

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
	
	// Get the UnitAvailability URL and UnitSpaceID from the property MITS data
	if ( isset( $property_mits_data['response']['result']['PhysicalProperty']['Property'][0] ) ) {
		
		$property_data = $property_mits_data['response']['result']['PhysicalProperty']['Property'][0];
		// Get the UnitAvailabilty URL from the property MITS data
		
		if (isset( $property_data['Information']['PropertyAvailabilityURL'] ) ) {
			$property_availability_url = $property_data['Information']['PropertyAvailabilityURL'];
			
			// from this url, get the URL, removing everything after and including the first / after the TLD
			$property_availability_domain = preg_replace( '/^(https?:\/\/[^\/]+).*$/', '$1', $property_availability_url );
		}
		
		// Get the UnitSpaceID from the property MITS data
		// if ( isset( $property_data['ILS_Unit'] ) && is_array( $property_data['ILS_Unit'] )  ) {
		// 	$ils_unit_array = $property_data['ILS_Unit'];
			
		// 	$ils_unit_array_processed = array();
			
		// 	// TODO
		// 	foreach ( $ils_unit_array as $ils_unit ) {
				
		// 		// UnitSpaceID is the key, IDValue is the value
		// 		$ils_unit_array_processed[ $ils_unit['Identification']['IDValue'] ] = $ils_unit['@attributes']['UnitSpaceId'];
		// 	}
		// }
	}
	
	
	
	// Build the apply online URL
	// https://www.propertySubdomain.com/Apartments/module/application_authentication/popup/false/kill_session/1/property[id]/%propertyID/property_floorplan[id]/floorplanID/unit_space[id]/unitSpaceID/show_in_popup/false/from_check_availability/1/?lease_start_date=02/27/2025
	
	$apply_online_url = sprintf(
		'%s/Apartments/module/application_authentication/popup/false/kill_session/1/property[id]/' . '%s/property_floorplan[id]/%s/unit_space[id]/%s/show_in_popup/false/from_check_availability/1/?lease_start_date=%s',
		$property_availability_domain,
		$args['property_id'],
		$args['floorplan_id'],
		$args['unit_id'],
		$unit_data['@attributes']['AvailableOn']
	);
	
	$building_name = $unit_data['@attributes']['BuildingName'];
	
	$meta = array(
		'unit_id' => $unit_data['@attributes']['PropertyUnitId'],
		'floorplan_id' => $unit_data['@attributes']['FloorplanId'],
		'property_id' => $unit_data['@attributes']['PropertyId'],
		'apply_online_url' => $apply_online_url,
		'availability_date' => $unit_data['@attributes']['AvailableOn'],
		'building_name' => $unit_data['@attributes']['BuildingName'],
		'floor_number' => $unit_data['@attributes']['FloorNumber'],
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

function rfs_entrata_remove_units_no_longer_available( $args, $units_from_api ) {
	
	// bail if we don't have units from the API.=
	if ( !$units_from_api || !is_array( $units_from_api ) ) {
		return;
	}
	
	$unit_ids_to_keep = array_keys( $units_from_api );
	
	
	// get all the units for this property
	$units_to_delete = get_posts(
		array(
			'post_type'      => 'units',
			'posts_per_page' => -1,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => 'property_id',
					'value' => $args['property_id'],
				),
				array(
					'key'   => 'unit_source',
					'value' => 'entrata',
				),
				array(
					'key'     => 'unit_id',
					'value'   => $unit_ids_to_keep,
					'compare' => 'NOT IN',
				),
			),
		)
	);
	
	// delete all of the units in the $units_to_delete array
	foreach ( $units_to_delete as $unit ) {
		wp_delete_post( $unit->ID, true );
	}
}