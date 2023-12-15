<?php

/**
 * Update an individual Realpage floorplan
 *
 * @param  array  $floorplan_data      the floorplan data
 * @param  array  $args                the arguments passed to the function
 */
function rfs_realpage_update_floorplan_meta( $args, $floorplan_data ) {
	
	// bail if we don't have the wordpress post ID
	if ( !isset( $args['wordpress_floorplan_post_id'] ) || !$args['wordpress_floorplan_post_id'] )
		return;
			
	// bail if we don't have the data to update this, updating the meta to give the error
	if ( !$floorplan_data['FloorPlanID'] ) {
		$floorplan_data_string = json_encode( $floorplan_data );
		// $success = update_post_meta( $args['wordpress_floorplan_post_id'], 'updated', current_time('mysql') );
		// $success = update_post_meta( $args['wordpress_floorplan_post_id'], 'api_error', $floorplan_data_string );
		
		$api_response = get_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', true );
		
		if ( !is_array( $api_response ) )
			$api_response = [];
	
		$api_response['floorplans_api'] = [
			'updated' => current_time('mysql'),
			'api_response' => $floorplan_data_string,
		];
				
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', $api_response );
				
		return;
	}

	$property_id = $args['property_id'];
	$floorplan_id = $args['floorplan_id'];
	$integration = $args['integration'];
		
	//* Update the title
	$post_info = array(
		'ID' => $args['wordpress_floorplan_post_id'],
		'post_title' => $floorplan_data['FloorPlanNameMarketing'],
		'post_name' => sanitize_title( $floorplan_data['FloorPlanNameMarketing'] ), // update the permalink to match the new title
	);
	
	wp_update_post( $post_info );
	
	$api_response = get_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', true );
	
	if ( !is_array( $api_response ) )
		$api_response = [];
	
	$api_response['floorplans_api'] = [
		'updated' => current_time('mysql'),
		'api_response' => 'Updated successfully',
	];
		
	//* Update the meta
	$meta = [
		'baths' => floatval($floorplan_data['Bathrooms']),
		'beds' => floatval($floorplan_data['Bedrooms']),
		'minimum_rent' => floatval( $floorplan_data['RentMin'] ),
		'maximum_rent' => floatval( $floorplan_data['RentMax'] ),
		'maximum_sqft' => floatval( $floorplan_data['GrossSquareFootage'] ),
		'minimum_sqft' => floatval( $floorplan_data['RentableSquareFootage'] ),
		'updated' => current_time('mysql'),
		'api_error' => 'Updated successfully',
		'api_response' => $api_response,
	];
	
	foreach ( $meta as $key => $value ) { 
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], $key, $value );
	}
}

function rfs_realpage_update_unit_meta( $args, $unit ) {
		
	// bail if we don't have the wordpress post ID
	if ( !isset( $args['wordpress_unit_post_id'] ) || !$args['wordpress_unit_post_id'] )
		return;
			
	// bail if we don't have the data to update this, updating the meta to give the error
	if ( !$args['unit_id'] ) {
		$unit_data_string = json_encode( $unit );
		$success = update_post_meta( $args['wordpress_unit_post_id'], 'updated', current_time('mysql') );
		$success = update_post_meta( $args['wordpress_unit_post_id'], 'api_error', $unit_data_string );
		
		$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );
		
		if ( !is_array( $api_response ) )
			$api_response = [];
				
		$api_response['units_api'] = [
			'updated' => current_time('mysql'),
			'api_response' => $unit_data_string,
		];
		
		$success = update_post_meta( $args['wordpress_unit_post_id'], 'api_response', $api_response );

		
		return;
	}
	
	if ( !isset( $args['property_id'] ) || !$args['property_id'] )
		return;
	
	if ( !isset( $args['floorplan_id'] ) || !$args['floorplan_id'] )
		return;
	
	if ( !isset( $args['unit_id'] ) || !$args['unit_id'] )
		return;

	$unit_id = $args['unit_id'];
	$floorplan_id = $args['floorplan_id'];
	$property_id = $args['property_id'];
	$integration = $args['integration'];
	
	console_log( $unit );
	
	//* Update the title
	$post_info = array(
		'ID' => $args['wordpress_unit_post_id'],
		'post_title' => $unit_id,
		'post_name' => sanitize_title( $unit_id ), // update the permalink to match the new title
	);
	
	wp_update_post( $post_info );
	
	
	$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );
	
	if ( !is_array( $api_response ) )
		$api_response = [];
	
	$api_response['units_api'] = [
		'updated' => current_time('mysql'),
		'api_response' => 'Updated successfully',
	];
	
	$meta = [
		'availability_date' => esc_html( $unit['Availability']['AvailableDate'] ),
		'available' => esc_html( $unit['Availability']['AvailableBit'] ),
		'updated' => current_time('mysql'),
		'beds' => floatval( $unit['UnitDetails']['Bedrooms'] ),
		'baths' => floatval( $unit['UnitDetails']['Bathrooms'] ),
		'maximum_rent' => floatval( $unit['BaseRentAmount'] ),
		'minimum_rent' => floatval( $unit['BaseRentAmount'] ),
		'deposit' => floatval( $unit['DepositAmount'] ),
		'sqrft' => floatval( $unit['UnitDetails']['RentSqFtCount'] ),
		'api_response' => $api_response,
	];
	
	foreach ( $meta as $key => $value ) { 
		$success = update_post_meta( $args['wordpress_unit_post_id'], $key, $value );
	}
}

function rfs_realpage_update_floorplan_availability_from_units( $args, $units_data ) {
	
	// we need to loop through the array and get (for each floorplan) the number of available units and the availability date
	// then we need to update the floorplan post with that information
	
	// set the date and units to be an array
	$floorplan_data = [];
	
	foreach( $units_data as $unit ) {
		
		if ( $unit['Availability']['AvailableBit'] == 'true' ) {
			$floorplan_id = $unit['FloorPlan']['FloorPlanID'];
			
			// check if $floorplan_data[ $floorplan_id ]['available_units'][] is already set. If it is, just add 1. If not, set it to 1
			if ( !isset( $floorplan_data[ $floorplan_id ]['available_units'] ) ) {
				$floorplan_data[ $floorplan_id ]['available_units'] = 1;
			} else {
				$floorplan_data[ $floorplan_id ]['available_units']++;
			}
			
			// check if $floorplan_data[ $floorplan_id ]['availability_date'][] is already set. If it is, compare the dates and set it to the value of the one that's earlier. if it's not set, just set it to the date.
			if ( !isset( $floorplan_data[ $floorplan_id ]['availability_date'] ) ) {
				$floorplan_data[ $floorplan_id ]['availability_date'] = $unit['Availability']['AvailableDate'];
			} else {
				$floorplan_data[ $floorplan_id ]['availability_date'] = min( $floorplan_data[ $floorplan_id ]['availability_date'], $unit['Availability']['AvailableDate'] );
			}		
			
			// if $floorplan_data[ $floorplan_id ]['availability_date'] is in the past, set it to today instead
			if ( strtotime( $floorplan_data[ $floorplan_id ]['availability_date'] ) < strtotime( 'today' ) ) {
				$floorplan_data[ $floorplan_id ]['availability_date'] = date( 'Y-m-d' );
			}
		}
	}
	
	console_log( $floorplan_data );
	
	// let's first set the availability and date of all of the floorplans for this property to have null availability and date
	$floorplans_args = array(
		'post_type' => 'floorplans',
		'posts_per_page' => -1,
		'meta_query' => array(
			'relation' => 'AND',
			array(
				'key' => 'integration',
				'value' => 'realpage',
			),
			array(
				'key' => 'property_id',
				'value' => $args['property_id'],
			),
		),
	);
	
	$floorplans_query = new WP_Query( $floorplans_args );
		
	if( $floorplans_query->have_posts() ) {
		
		while( $floorplans_query->have_posts() ): $floorplans_query->the_post();
		
			// get the floorplan ID
			$floorplan_id = get_post_meta( get_the_ID(), 'floorplan_id', true );
			
			// look at the array of floorplan data and see if there's a match
			if ( isset( $floorplan_data[ $floorplan_id ] ) ) {
				
				// if there is, update the post meta with the availability and date
				$success = update_post_meta( get_the_ID(), 'available_units', $floorplan_data[ $floorplan_id ]['available_units'] );
				$success = update_post_meta( get_the_ID(), 'availability_date', $floorplan_data[ $floorplan_id ]['availability_date'] );
				
			} else {
				
				// if there isn't, set the availability and date to null
				$success = update_post_meta( get_the_ID(), 'available_units', 0 );
				$success = update_post_meta( get_the_ID(), 'availability_date', null );
				
			}
						
		endwhile;
		
		wp_reset_postdata();
	
	}
	
	
	
	
	
}