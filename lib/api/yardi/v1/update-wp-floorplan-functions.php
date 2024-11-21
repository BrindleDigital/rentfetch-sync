<?php

/**
 * Update an individual Yardi floorplan
 *
 * @param  array  $floorplan_data      the floorplan data
 * @param  array  $args                the arguments passed to the function
 */
function rfs_yardi_update_floorplan_meta( $args, $floorplan_data ) {
	
	// bail if we don't have the wordpress post ID
	if ( !isset( $args['wordpress_floorplan_post_id'] ) || !$args['wordpress_floorplan_post_id'] )
		return;
			
	// bail if we don't have the data to update this, updating the meta to give the error
	if ( !$floorplan_data['FloorplanId'] ) {
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
		'ID' => (int) $args['wordpress_floorplan_post_id'],
		'post_title' => esc_html( $floorplan_data['FloorplanName'] ),
		'post_name' => sanitize_title( $floorplan_data['FloorplanName'] ), // update the permalink to match the new title
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
		'availability_url' => esc_url( $floorplan_data['AvailabilityURL'] ),
		'available_units' => esc_html( $floorplan_data['AvailableUnitsCount'] ),
		'baths' => floatval( $floorplan_data['Baths'] ),
		'beds' => floatval( $floorplan_data['Beds'] ),
		'has_specials' => esc_html( $floorplan_data['FloorplanHasSpecials'] ),
		'floorplan_image_alt_text' => esc_html( $floorplan_data['FloorplanImageAltText'] ),
		'floorplan_image_name' => esc_html( $floorplan_data['FloorplanImageName'] ),
		'floorplan_image_url' => esc_html( $floorplan_data['FloorplanImageURL'] ),
		'maximum_deposit' => esc_html( $floorplan_data['MaximumDeposit'] ),
		'maximum_rent' => esc_html( $floorplan_data['MaximumRent'] ),
		'maximum_sqft' => esc_html( $floorplan_data['MaximumSQFT'] ),
		'minimum_deposit' => esc_html( $floorplan_data['MinimumDeposit'] ),
		'minimum_rent' => esc_html( $floorplan_data['MinimumRent'] ),
		'minimum_sqft' => esc_html( $floorplan_data['MinimumSQFT'] ),
		// 'property_show_specials' => esc_html( $floorplan_data['PropertyShowsSpecials'] ),
		// 'unit_type_mapping' => esc_html( $floorplan_data['UnitTypeMapping'] ),
		'updated' => current_time('mysql'), 
		'api_error' => 'Updated successfully',
		'api_response' => $api_response,
	];
	
	foreach ( $meta as $key => $value ) { 
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], $key, $value );
	}
	
	
	
}

/**
 * Update the floorplan availability
 *
 */
function rfs_yardi_update_floorplan_availability( $args, $availability_data ) {
	
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
 * Remove any orphaned floorplans from WordPress
 *
 */
function rfs_remove_availability_orphan_yardi_floorplans( $floorplans, $property ) {
	
	// rentfetch_console_log( 'Floorplans:');
	// rentfetch_console_log( $floorplans );
	
	// rentfetch_console_log( 'Property:' );
	// rentfetch_console_log( $property['PropertyData']['PropertyCode'] );
		
	//* get a list of floorplans that show up in the API
	$floorplan_ids_from_api = array();
	foreach( $floorplans as $floorplan ) {
		$floorplan_ids_from_api[] = $floorplan[ 'FloorplanId' ];
	}
	
	if ( isset( $property['PropertyData']['PropertyCode'] ) ) {
		$property_id = $property['PropertyData']['PropertyCode'];
	} else {
		// if we don't actually have a property ID, bail (we can't do anything without it)
		return;
	}
		
	//* get a list of floorplans currently in WordPress
	$floorplan_query_args = array(
		'post_type' => 'floorplans',
		'posts_per_page' => -1,
		'post_status' => 'publish',
		'meta_query' => array(
			array(
				'relation' => 'AND',
				array(
					'key' => 'floorplan_source',
					'value' => 'yardi',
				),
				array(
					'key'   => 'property_id',
					'value' => $property_id,
				),
			),
		),
	);
		
	$floorplans_in_wordpress = get_posts( $floorplan_query_args );
		
	// //* Testing
	// rentfetch_console_log( 'From API:' );
	// rentfetch_console_log( $floorplan_ids_from_api );
	
	// rentfetch_console_log( 'From WordPress:' );
	// foreach( $floorplans_in_wordpress as $floorplan_in_wordpress ) {
	//     $floorplan_id_in_wordpress = get_post_meta( $floorplan_in_wordpress->ID, 'floorplan_id', true );
	//     rentfetch_console_log( $floorplan_id_in_wordpress );
	// }
	
	//* loop through each of those in WordPress and delete any that aren't in the API
	foreach ( $floorplans_in_wordpress as $floorplan_in_wordpress ) {
		$floorplan_id_in_wordpress = get_post_meta( $floorplan_in_wordpress->ID, 'floorplan_id', true );
		$floorplan_ids_in_wordpress[] = $floorplan_id_in_wordpress;
		
		if ( !in_array( $floorplan_id_in_wordpress, $floorplan_ids_from_api ) ) {
			
			$success_floorplan_no_date = update_post_meta( $floorplan_in_wordpress->ID, 'availability_date', null );
			$success_floorplan_no_units = update_post_meta( $floorplan_in_wordpress->ID, 'available_units', '0' );
						
		}
	}
		
}

/**
 * Remove any orphaned properties, floorplans, and units from WordPress (which aren't in the settings)
 */
add_action( 'rfs_yardi_do_delete_orphans', 'rfs_yardi_delete_orphans', 10, 1 );
function rfs_yardi_delete_orphans( $yardi_properties_in_settings_box ) {
		
	if ( !is_array( $yardi_properties_in_settings_box ) )
		return;
	
	//* Properties
	$property_deletion_query_args = array(
		'post_type' => 'properties',
		'posts_per_page' => -1,
		'post_status' => 'publish',
		'meta_query' => array(
			array(
				'relation' => 'AND',
				array(
					'key' => 'property_source',
					'value' => 'yardi',
				),
				array(
					'key'   => 'property_id',
					'value' => $yardi_properties_in_settings_box,
					'compare' => 'NOT IN',
				),
			),
		),
	);
	
	// delete all properties found
	$properties_in_wordpress_not_in_settings = get_posts( $property_deletion_query_args );
	
	foreach ( $properties_in_wordpress_not_in_settings as $post_in_wordpress ) {
		wp_delete_post( $post_in_wordpress->ID, true );
	}
	
	//* Floorplans
	$floorplan_deletion_query_args = array(
		'post_type' => 'floorplans',
		'posts_per_page' => -1,
		'post_status' => 'publish',
		'meta_query' => array(
			array(
				'relation' => 'AND',
				array(
					'key' => 'floorplan_source',
					'value' => 'yardi',
				),
				array(
					'key'   => 'property_id',
					'value' => $yardi_properties_in_settings_box,
					'compare' => 'NOT IN',
				),
			),
		),
	);
	
	// delete all floorplans found
	$floorplans_in_wordpress_not_in_settings = get_posts( $floorplan_deletion_query_args );
	
	foreach ( $floorplans_in_wordpress_not_in_settings as $post_in_wordpress ) {
		wp_delete_post( $post_in_wordpress->ID, true );
	}
	
	//* Units
	$unit_deletion_query_args = array(
		'post_type' => 'units',
		'posts_per_page' => -1,
		'post_status' => 'publish',
		'meta_query' => array(
			array(
				'relation' => 'AND',
				array(
					'key' => 'unit_source',
					'value' => 'yardi',
				),
				array(
					'key'   => 'property_id',
					'value' => $yardi_properties_in_settings_box,
					'compare' => 'NOT IN',
				),
			),
		),
	);
	
	// delete all units found
	$units_in_wordpress_not_in_settings = get_posts( $unit_deletion_query_args );
	
	foreach ( $units_in_wordpress_not_in_settings as $post_in_wordpress ) {
		wp_delete_post( $post_in_wordpress->ID, true );
	}
	
}