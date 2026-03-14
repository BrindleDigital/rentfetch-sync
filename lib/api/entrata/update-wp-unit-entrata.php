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

	$unit_attributes = ( isset( $unit_data['@attributes'] ) && is_array( $unit_data['@attributes'] ) )
		? $unit_data['@attributes']
		: array();

	// If unit_data is a string (cleaned JSON from decode failure), save it directly
	if ( is_string( $unit_data ) ) {
		$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );
		
		if ( ! is_array( $api_response ) ) {
			$api_response = array();
		}
	
		$api_response['getUnitsAvailabilityAndPricing'] = array(
			'updated'      => current_time( 'mysql' ),
			'api_response' => $unit_data,
		);
		
		$success = update_post_meta( $args['wordpress_unit_post_id'], 'api_response', $api_response );
		rfs_mark_sync_failed( $args['wordpress_unit_post_id'], 'getUnitsAvailabilityAndPricing' );
		return;
	}
	
	// This malformed payload path is hard to reproduce on demand, so keep it
	// defensive and fail cleanly instead of relying on nested array access.
	if ( empty( $unit_attributes['PropertyUnitId'] ) ) {

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
		rfs_mark_sync_failed( $args['wordpress_unit_post_id'], 'getUnitsAvailabilityAndPricing' );

		return;
	}

	// * Update the title
	$post_info = array(
		'ID'         => (int) $args['wordpress_unit_post_id'],
		'post_title' => esc_html( $unit_attributes['UnitNumber'] ?? '' ),
		'post_name'  => sanitize_title( $unit_attributes['UnitNumber'] ?? '' ), // update the permalink to match the new title.
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
	
	// process square feet, removing ' SquareFeet' from the string
	$square_feet = (int) str_replace( ' SquareFeet', '', (string) ( $unit_attributes['Area'] ?? '' ) );

	// process the rent numbers, removing the $ and , characters
	$min_rent = (int) str_replace( array( '$', ',' ), '', (string) ( $unit_data['Rent']['@attributes']['MinRent'] ?? '' ) );
	$max_rent = (int) str_replace( array( '$', ',' ), '', (string) ( $unit_data['Rent']['@attributes']['MaxRent'] ?? '' ) );

	// process the deposit number, removing the $ and , characters
	$deposit = (int) str_replace( array( '$', ',' ), '', (string) ( $unit_data['Deposit']['@attributes']['MinDeposit'] ?? '' ) );
	
	// Get the UnitAvailability URL and UnitSpaceID from the property MITS data
	$property_availability_domain = '';

	if ( isset( $property_mits_data['response']['result']['PhysicalProperty']['Property'][0] ) ) {
		
		$property_data = $property_mits_data['response']['result']['PhysicalProperty']['Property'][0];
		// Get the UnitAvailabilty URL from the property MITS data
		
		if ( isset( $property_data['Information']['PropertyAvailabilityURL'] ) ) {
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
	
	
	
	// We do not have a reliable fixture for this incomplete MITS case, so keep it
	// explicit: without the availability URL domain we should fail, not save a bad link.
	if ( '' === $property_availability_domain ) {
		$api_response['getUnitsAvailabilityAndPricing']['sync_error'] = 'Missing PropertyAvailabilityURL in property MITS data.';
		update_post_meta( $args['wordpress_unit_post_id'], 'api_response', $api_response );
		rfs_mark_sync_failed( $args['wordpress_unit_post_id'], 'getUnitsAvailabilityAndPricing' );
		return;
	}

	// Build the apply online URL
	// https://www.propertySubdomain.com/Apartments/module/application_authentication/popup/false/kill_session/1/property[id]/%propertyID/property_floorplan[id]/floorplanID/unit_space[id]/unitSpaceID/show_in_popup/false/from_check_availability/1/?lease_start_date=02/27/2025
	
	$apply_online_url = sprintf(
		'%s/Apartments/module/application_authentication/popup/false/kill_session/1/property[id]/' . '%s/property_floorplan[id]/%s/unit_space[id]/%s/show_in_popup/false/from_check_availability/1/?lease_start_date=%s',
		$property_availability_domain,
		$args['property_id'],
		$args['floorplan_id'],
		$args['unit_id'],
		$unit_attributes['AvailableOn'] ?? ''
	);
	
	$meta = array(
		'unit_id' => sanitize_text_field( $unit_attributes['PropertyUnitId'] ?? '' ),
		'floorplan_id' => sanitize_text_field( $unit_attributes['FloorplanId'] ?? '' ),
		'property_id' => sanitize_text_field( $unit_attributes['PropertyId'] ?? '' ),
		'apply_online_url' => esc_url_raw( $apply_online_url ),
		'availability_date' => sanitize_text_field( $unit_attributes['AvailableOn'] ?? '' ),
		'building_name' => sanitize_text_field( $unit_attributes['BuildingName'] ?? '' ),
		'floor_number' => sanitize_text_field( $unit_attributes['FloorNumber'] ?? '' ),
		// 'baths' => $unit_data['@attributes']['baths'],
		// 'beds' => $unit_data['@attributes']['beds'],
		'deposit' => $deposit,
		'minimum_rent' => $min_rent,
		'maximum_rent' => $max_rent,
		'sqrft' => $square_feet,
		// 'amenities' => $unit_data['@attributes']['amenities'],
		// 'specials' => $unit_data['@attributes']['specials'],
		'unit_source' => 'entrata',
		'updated' => current_time( 'mysql' ),
		'api_response' => $api_response,
	);
	
	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_unit_post_id'], $key, $value );
	}

	rfs_mark_sync_succeeded( $args['wordpress_unit_post_id'], 'getUnitsAvailabilityAndPricing' );
}

function rfs_entrata_remove_all_units_from_property_if_none_available( $args, $units_from_api ) {
	
	// If we have units from the API, don't remove anything
	if ( ! empty( $units_from_api ) && is_array( $units_from_api ) ) {
		return;
	}
	
	// Get all units for this property with entrata source
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
			),
		)
	);
	
	// Delete all units for this property
	foreach ( $units_to_delete as $unit ) {
		wp_delete_post( $unit->ID, true );
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
