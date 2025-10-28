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

	// Save the actual API response payload for debugging/inspection.
	$api_response['floorplans_api'] = array(
		'updated'      => current_time( 'mysql' ),
		'api_response' => wp_json_encode( $floorplan_data ),
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
		'api_error'                => '',
		'api_response'             => $api_response,
	);

	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], $key, $value );
	}
}

/**
 * Update the available_units number for all floorplans of a property based on the units data (in the WP database).
 *
 * @param   array  $args                The arguments passed to the function.
 * @param   array  $floorplans_data_v2  The floorplans data from the Yardi API.
 *
 * @return  void
 */
function rfs_yardi_v2_update_floorplan_units_available_number( $args, $floorplans_data_v2 ) {

	//* NOTE: we're going to update these using floorplans data from the API, and our own unit meta, because Yardi sometimes sends empty responses in the units API.

	$property_id = $args['property_id'];
	
	// get all of the floorplan IDs from yardi into an array
	$floorplan_yardi_ids = array();
	
	foreach ( $floorplans_data_v2 as $floorplan ) {
		if ( isset( $floorplan['floorplanId'] ) ) {
			$floorplan_yardi_ids[] = $floorplan['floorplanId'];
		}
	}
	
	// let's do a query for the floorplans for this property that have a floorplan_source of 'yardi' and have 'property_id' value according to $args['property_id']
	$floorplan_posts = get_posts( array(
		'post_type'      => 'floorplans',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'     => 'property_id',
				'value'   => $property_id,
				'compare' => 'LIKE',
			),
			array(
				'key'     => 'floorplan_source',
				'value'   => 'yardi',
				'compare' => '=',
			),
		),
	) ); 
	
	// now, loop through the posts, and for each of these floorplans posts, we're going to query for units that have the 'unit_source' of 'yardi' along with the property_id and floorplan_id (this ID is from meta) corresponding to this floorplan and property.
	foreach ( $floorplan_posts as $floorplan_post ) {

		$floorplan_id = $floorplan_post->ID;
		$floorplan_yardi_id = get_post_meta( $floorplan_id, 'floorplan_id', true );

		$unit_posts = get_posts( array(
			'post_type'      => 'units',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => 'property_id',
					'value'   => $property_id,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'floorplan_id',
					'value'   => $floorplan_yardi_id,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'unit_source',
					'value'   => 'yardi',
					'compare' => '=',
				),
				array(
					'key'     => 'availability_date',
					'value'   => '',
					'compare' => '!=',
				),
			),
		) );

		// we need to update the available_units meta for this floorplan post to match the number of units we found.
		$available_units = count( $unit_posts );
		
		// bail if there aren't any (like on a manual entry site).
		if ( 0 != $available_units ) {
			update_post_meta( $floorplan_id, 'available_units', $available_units );
		}
	}
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
		
		// don't update the api_response, as this gives the implication that this is still updating, when in fact it's no longer in the API.
		$api_response = get_post_meta( $floorplan_post->ID, 'api_response', true );
		
		if ( !is_array( $api_response ) ) {
			$api_response = [];
		}
		
		// remove the old api response for apartmentavailability_api (this was used in v1	)
		unset( $api_response['apartmentavailability_api'] );
		
		$success = update_post_meta( $floorplan_post->ID, 'api_response', $api_response );
		
	}
	
}

/**
 * Delete orphan floorplans that no longer exist in the Yardi API data.
 *
 * @param array $args The arguments passed to the function.
 * @param array $floorplans_data_v2 The floorplans data from the Yardi API.
 */
function rfs_yardi_v2_delete_orphan_floorplans( $args, $floorplans_data_v2 ) {
	$property_id = $args['property_id'];
	
	if ( empty( $property_id ) ) {
		return;
	}
	
	$floorplan_ids = array();
	foreach ( $floorplans_data_v2 as $floorplan ) {
		if ( isset( $floorplan['floorplanId'] ) ) {
			$floorplan_ids[] = $floorplan['floorplanId'];
		}
	}
	
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
	
	foreach ( $floorplan_posts as $floorplan_post ) {
		wp_delete_post( $floorplan_post->ID, true );
	}
}

/**
 * Delete orphan units that are associated with floorplans no longer in the Yardi API data.
 *
 * @param array $args The arguments passed to the function.
 * @param array $floorplans_data_v2 The floorplans data from the Yardi API.
 */
function rfs_yardi_v2_delete_orphan_units( $args, $floorplans_data_v2 ) {
	$property_id = $args['property_id'];
	
	if ( empty( $property_id ) ) {
		return;
	}
	
	$floorplan_ids = array();
	foreach ( $floorplans_data_v2 as $floorplan ) {
		if ( isset( $floorplan['floorplanId'] ) ) {
			$floorplan_ids[] = $floorplan['floorplanId'];
		}
	}
	
	$unit_posts = get_posts( array(
		'post_type'      => 'units',
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
				'key'     => 'unit_source',
				'value'   => 'yardi',
				'compare' => '=',
			),
		),
	) );
	
	foreach ( $unit_posts as $unit_post ) {
		wp_delete_post( $unit_post->ID, true );
	}
}