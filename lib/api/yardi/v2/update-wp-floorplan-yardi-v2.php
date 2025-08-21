<?php
/**
 * Functions to update floorplan data in WordPress from the Yardi API (v2)
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
function rfs_yardi_v2_update_floorplan_meta( $args, $floorplan_data, $unit_data_v2 ) {

	// bail if we don't have the WordPress post ID.
	if ( ! isset( $args['wordpress_floorplan_post_id'] ) || ! $args['wordpress_floorplan_post_id'] ) {
		return;
	}

	// bail if we don't have the data to update this, updating the meta to give the error.
	if ( ! $floorplan_data['floorplanId'] ) {

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
		'post_title' => esc_html( $floorplan_data['floorplanName'] ),
		'post_name'  => sanitize_title( $floorplan_data['floorplanName'] ), // update the permalink to match the new title.
	);

	wp_update_post( $post_info );

	$api_response = get_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', true );

	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	$api_response['floorplans_api'] = array(
		'updated'      => current_time( 'mysql' ),
		'api_response' => 'Updated successfully',
	);
	
	// remove $api_response['apartmentavailability_api'], which was used in v1 but not here (this is still showing on some sites as of 20250820)
	unset( $api_response['apartmentavailability_api'] );
	
	$yardi_floorplan_id = $floorplan_data['floorplanId'];
	
	// let's loop through the $unit_data_v2, and find the units that are associated with this floorplan.
	$availability_dates = array();
	foreach( $unit_data_v2 as $unit ) {
		
		if ( isset( $unit['floorplanId'] ) && $unit['floorplanId'] == $yardi_floorplan_id ) {
			
			if ( isset( $unit['availableDate'] ) && !empty( $unit['availableDate'] ) ) {
				$date = sanitize_text_field( $unit['availableDate'] );
				$availability_dates[] = date('Ymd', strtotime($date));
			}
		}
	}
	
	// let's set $availability_date to the soonest date in the $availability_dates array.
	$soonest_date = null;
	
	if ( !empty( $availability_dates ) ) {
		
		// sort the dates.
		sort( $availability_dates );
		
		// get the soonest date.
		$soonest_date = $availability_dates[0];
		
	} else {
		
		$soonest_date = null;
		
	}
	
	
	

	// * Update the meta
	$meta = array(
		'availability_url'         => esc_url_raw( $floorplan_data['availabilityURL'] ?? '' ),
		'availability_date'        => $soonest_date,
		'available_units'          => absint( $floorplan_data['availableUnitsCount'] ?? 0 ),
		'baths'                    => floatval( $floorplan_data['baths'] ?? 0 ),
		'beds'                     => floatval( $floorplan_data['beds'] ?? 0 ),
		'has_specials'             => sanitize_text_field( $floorplan_data['floorplanHasSpecials'] ?? '' ),
		'floorplan_image_alt_text' => sanitize_text_field( $floorplan_data['floorplanImageAltText'] ?? '' ),
		'floorplan_image_name'     => sanitize_text_field( $floorplan_data['floorplanImageName'] ?? '' ),
		'floorplan_image_url'      => esc_url_raw( $floorplan_data['floorplanImageURL'] ?? '' ),
		'maximum_deposit'          => floatval( $floorplan_data['maximumDeposit'] ?? 0 ),
		'maximum_rent'             => floatval( $floorplan_data['maximumRent'] ?? 0 ),
		'maximum_sqft'             => absint( $floorplan_data['maximumSQFT'] ?? 0 ),
		'minimum_deposit'          => floatval( $floorplan_data['minimumDeposit'] ?? 0 ),
		'minimum_rent'             => floatval( $floorplan_data['minimumRent'] ?? 0 ),
		'minimum_sqft'             => absint( $floorplan_data['minimumSQFT'] ?? 0 ),
		'updated'                  => current_time( 'mysql' ),
		'api_error'                => sanitize_text_field( 'Updated successfully' ),
		'api_response'             => $api_response,
	);

	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], $key, $value );
	}
}

/**
 * Update the floorplan availability for this floorplan.
 * 
 * TODO this doesn't seem to be running at present, not sure if still needed.
 *
 * @param   array  $args               The arguments passed to the function.
 * @param   array  $availability_data  The availability data.
 *
 * @return  array                      The updated availability data.
 */
function rfs_yardi_v2_update_floorplan_availability( $args, $availability_data ) {
	
	//! BAIL FOR NOW
	
	// remove $api_response['apartmentavailability_api']
	$api_response = get_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', true );
	if ( is_array( $api_response ) && isset( $api_response['apartmentavailability_api'] ) ) {
		unset( $api_response['apartmentavailability_api'] );
		update_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', $api_response );
	}

	return;
	
	// bail if we don't have the wordpress post ID
	if ( !isset( $args['wordpress_floorplan_post_id'] ) || !$args['wordpress_floorplan_post_id'] )
		return;
					
	// bail if we don't have the availability data to update this
	if ( !$availability_data ) {
		
		$api_response = get_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', true );
		
		if ( !is_array( $api_response ) )
			$api_response = [];
				
		$api_response['apartmentavailability_api'] = [
			'updated' => current_time('mysql'),
			'api_response' => 'Availability data not found',
		];
		
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', $api_response );
		
		return;
		
	}
		
	$available_dates = array();
	$soonest_date = null;
	$today = date('Ymd');
	
	foreach( $availability_data as $data ) {
		
		if ( isset( $data->AvailableDate ) ) {
			
			$date = $data->AvailableDate;
			$date = date('Ymd', strtotime($date));
			$available_dates[] = $date;
		}            
	}
		
	sort( $available_dates );
		
	if ( isset( $available_dates[0] ) )
		$soonest_date = $available_dates[0];   
		
	// if the soonest date is before today, just set the available date to today
	if ( $soonest_date == null ) {
		$available_date = null;
	}
	elseif ( $today > $soonest_date ) {
		$available_date = $today;
	} else {
		$available_date = $soonest_date;
	}
	
	//* Update the meta
	$success = update_post_meta( $args['wordpress_floorplan_post_id'], 'availability_date', $available_date );
	
	$api_response = get_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', true );
	
	if ( !is_array( $api_response ) )
		$api_response = [];
	
	$api_response['apartmentavailability_api'] = [
		'updated' => current_time('mysql'),
		'api_response' => 'Updated successfully',
	];
	
	$success = update_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', $api_response );
			
}

/**
 * Zero the availability and avail date for orphan floorplans that no longer exist in the Yardi API data.
 *
 * @param   array  $args                The arguments passed to the function.
 * @param   array  $floorplans_data_v2  The floorplans data from the Yardi API.
 *
 * @return  void
 */
function rfs_yardi_v2_remove_availability_orphan_floorplans( $args, $floorplans_data_v2 ) {
	// get the property_id from the args
	$property_id = $args['property_id'];
	
	// if we don't have a property ID, bail
	if ( empty( $property_id ) ) {
		return;
	}
	
	// get the floorplan IDs from the API
	$floorplan_ids = array();
	foreach ( $floorplans_data_v2 as $floorplan ) {
		if ( isset( $floorplan['floorplanId'] ) ) {
			$floorplan_ids[] = $floorplan['floorplanId'];
		}
	}
	
	
	// get the floorplan posts for this property
	$floorplan_posts = get_posts( array(
		'post_type'      => 'floorplans',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'     => 'property_id',
				'value'   => $property_id,
				'compare' => '=',
			),
			array(
				'key'     => 'floorplan_id',
				'value'   => $floorplan_ids,
				'compare' => 'NOT IN',
			),
			array(
				'key'     => 'floorplan_source',
				'value'   => 'yardi',
				'compare' => '=',
			),
		),
	) );
	
	// loop through the floorplan posts and remove the availability_date and the available_units meta
	foreach ( $floorplan_posts as $floorplan_post ) {
		$success = delete_post_meta( $floorplan_post->ID, 'availability_date' );
		$success = delete_post_meta( $floorplan_post->ID, 'available_units' );
		$success = delete_post_meta( $floorplan_post->ID, 'minimum_rent' );
		$success = delete_post_meta( $floorplan_post->ID, 'maximum_rent' );
		$success = delete_post_meta( $floorplan_post->ID, 'minimum_deposit' );
		$success = delete_post_meta( $floorplan_post->ID, 'maximum_deposit' );
		
		// update the api_response meta to show that the availability data was removed
		$api_response = get_post_meta( $floorplan_post->ID, 'api_response', true );
		
		if ( !is_array( $api_response ) )
			$api_response = [];
		
		$api_response['apartmentavailability_api'] = [
			'updated' => current_time('mysql'),
			'api_response' => 'Removed successfully (no longer in API)',
		];
		
		$success = update_post_meta( $floorplan_post->ID, 'api_response', $api_response );
		
	}
	
}