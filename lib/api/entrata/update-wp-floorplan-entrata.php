<?php
/**
 * Functions to update floorplan data in WordPress from the Entrata API
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Update an individual Yardi floorplan
 *
 * @param  array $args                the arguments passed to the function.
 * @param  array $floorplan_data      the floorplan data.
 */
function rfs_entrata_update_floorplan_meta( $args, $floorplan_data, $units ) {

	// bail if we don't have the WordPress post ID.
	if ( ! isset( $args['wordpress_floorplan_post_id'] ) || ! $args['wordpress_floorplan_post_id'] ) {
		return;
	}

	// If floorplan_data is a string (cleaned JSON from decode failure), save it directly
	if ( is_string( $floorplan_data ) ) {
		$api_response = get_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', true );
		
		if ( ! is_array( $api_response ) ) {
			$api_response = array();
		}
	
		$api_response['floorplans_api'] = array(
			'updated'      => current_time( 'mysql' ),
			'api_response' => $floorplan_data,
		);
		
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', $api_response );
		return;
	}

	// bail if we don't have the data to update this, updating the meta to give the error.
	if ( ! $floorplan_data['Identification']['IDValue'] ) {

		$floorplan_data_string = wp_json_encode( $floorplan_data );

		$api_response = get_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', true );

		if ( ! is_array( $api_response ) ) {
			$api_response = array();
		}

		$api_response['floorplans_api'] = array(
			'updated'      => current_time( 'mysql' ),
			'api_response' => $floorplan_data_string,
		);

		$success = update_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', $api_response );

		return;
	}

	// * Update the title
	$post_info = array(
		'ID'         => (int) $args['wordpress_floorplan_post_id'],
		'post_title' => esc_html( $floorplan_data['Name'] ),
		'post_name'  => sanitize_title( $floorplan_data['Name'] ), // update the permalink to match the new title.
	);

	wp_update_post( $post_info );

	$api_response = get_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', true );

	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	$floorplan_data_string = wp_json_encode( $floorplan_data );

	$api_response['floorplans_api'] = array(
		'updated'      => current_time( 'mysql' ),
		'api_response' => $floorplan_data_string,
	);
	
	// Process the beds and baths
	$bedrooms = 0;
	$bathrooms = 0;
	
	if ( isset( $floorplan_data['Room'] ) ) {
		
		$rooms = $floorplan_data['Room'];
	
		foreach ($rooms as $room) {
			if ($room['@attributes']['RoomType'] === 'Bedroom') {
				$bedrooms = $room['Count'];
			} elseif ($room['@attributes']['RoomType'] === 'Bathroom') {
				$bathrooms = $room['Count'];
			}
		}
	}
	
	// Get the availability date
	$availability_dates = array();
	$floorplan_entrata_id = $args['floorplan_id'];

	foreach( $units as $unit ) {
		if ( isset( $unit['@attributes']['FloorplanId'] ) && (int) $unit['@attributes']['FloorplanId'] === (int) $floorplan_entrata_id ) {
			if ( isset( $unit['@attributes']['AvailableOn'] ) ) {
				$availability_dates[] = date('Ymd', strtotime($unit['@attributes']['AvailableOn']));
			}
		}
	}
	
	// get today's date in Ymd format
	$today = date('Ymd');
	
	// find the earliest date in the array
	$available_date = null;
	if ( ! empty( $availability_dates ) ) {
		// discard null values, empty values, and other non-date values. Don't use an anon function for this.
		$availability_dates = array_filter( $availability_dates, function( $date ) {
			return ! empty( $date ) && preg_match( '/^\d{8}$/', $date );
		} );
		
		$available_date = min( $availability_dates );
	}
	
	// if the earliest date is in the past, set it to today
	if ( $available_date && $available_date < $today ) {
		$available_date = $today;
	}

	
	// Process the images
	$images = array();
	
	if ( isset( $floorplan_data['File'] ) && is_array( $floorplan_data['File'] ) ) {
		foreach ( $floorplan_data['File'] as $image ) {
			$images[] = esc_url_raw( $image['Src'] );
		}
	}
	
	// Make the array into comma-separated values
	$images = implode( ',', $images );
	

	// * Update the meta
	$meta = array(
		// 'availability_url'         => esc_url_raw( $floorplan_data['availabilityURL'] ?? '' ),
		'availability_date'        => $available_date ? sanitize_text_field( $available_date ) : null,
		'available_units'          => absint( $floorplan_data['UnitsAvailable'] ?? 0 ),
		'baths'                    => floatval( $bathrooms ?? 0 ),
		'beds'                     => floatval( $bedrooms ?? 0 ),
		'floorplan_description'    => sanitize_text_field( $floorplan_data['Comment'] ?? null ),
		// 'has_specials'             => sanitize_text_field( $floorplan_data['floorplanHasSpecials'] ?? '' ),
		// 'floorplan_image_alt_text' => sanitize_text_field( $floorplan_data['floorplanImageAltText'] ?? '' ),
		// 'floorplan_image_name'     => sanitize_text_field( $floorplan_data['floorplanImageName'] ?? '' ),
		'floorplan_image_url'      => $images,
		'maximum_rent'             => floatval( $floorplan_data['MarketRent']['@attributes']['Max'] ?? 0 ),
		'minimum_rent'             => floatval( $floorplan_data['MarketRent']['@attributes']['Min'] ?? 0 ),
		'maximum_sqft'             => absint( $floorplan_data['SquareFeet']['@attributes']['Max'] ?? 0 ),
		'minimum_sqft'             => absint( $floorplan_data['SquareFeet']['@attributes']['Min'] ?? 0 ),
		// 'maximum_deposit'          => floatval( $floorplan_data['maximumDeposit'] ?? 0 ),
		// 'minimum_deposit'          => floatval( $floorplan_data['minimumDeposit'] ?? 0 ),
		'updated'                  => current_time( 'mysql' ),
		'api_error'                => wp_json_encode( $floorplan_data ),
		'api_response'             => $api_response,
	);

	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], $key, $value );
	}
}
