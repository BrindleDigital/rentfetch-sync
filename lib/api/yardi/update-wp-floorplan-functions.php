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
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], 'updated', current_time('mysql') );
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], 'api_error', $floorplan_data_string );
		
		return;
	}

	$property_id = $args['property_id'];
	$floorplan_id = $args['floorplan_id'];
	$integration = $args['integration'];
		
	//* Update the title
	$post_info = array(
		'ID' => $args['wordpress_floorplan_post_id'],
		'post_title' => $floorplan_data['FloorplanName'],
		'post_name' => sanitize_title( $floorplan_data['FloorplanName'] ), // update the permalink to match the new title
	);
	
	wp_update_post( $post_info );
	
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
		'property_show_specials' => esc_html( $floorplan_data['PropertyShowsSpecials'] ),
		'unit_type_mapping' => esc_html( $floorplan_data['UnitTypeMapping'] ),
		'updated' => current_time('mysql'), 
		'api_error' => 'Updated successfully', 
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
	if ( !$availability_data )
		return;
		
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
			
}

/**
 * Remove any orphaned floorplans from WordPress
 *
 */
function rfs_delete_orphan_yardi_floorplans( $floorplans, $property ) {
        
    //* get a list of floorplans that show up in the API
    $floorplan_ids_from_api = array();
    foreach( $floorplans as $floorplan ) {
        $floorplan_ids_from_api[] = $floorplan[ 'FloorplanId' ];
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
                    'key'   => 'voyager_property_code',
                    'value' => $property,
                ),
            ),
        ),
    );
        
    $floorplans_in_wordpress = get_posts( $floorplan_query_args );
        
    // //* Testing
    // echo 'From API: <br/>';
    // var_dump( $floorplan_ids_from_api );
    
    // echo 'From WordPress: <br/>';
    // foreach( $floorplans_in_wordpress as $floorplan_in_wordpress ) {
    //     $floorplan_id_in_wordpress = get_post_meta( $floorplan_in_wordpress->ID, 'floorplan_id', true );
    //     echo $floorplan_id_in_wordpress . '<br/>';
    // }
    
    //* loop through each of those in WordPress and delete any that aren't in the API
    foreach ( $floorplans_in_wordpress as $floorplan_in_wordpress ) {
        $floorplan_id_in_wordpress = get_post_meta( $floorplan_in_wordpress->ID, 'floorplan_id', true );
        $floorplan_ids_in_wordpress[] = $floorplan_id_in_wordpress;
        
        if ( !in_array( $floorplan_id_in_wordpress, $floorplan_ids_from_api ) ) {
            
            $success_floorplan_no_date = update_post_meta( $floorplan_in_wordpress->ID, 'availability_date', null );
            $success_floorplan_no_units = update_post_meta( $floorplan_in_wordpress->ID, 'available_units', '0' );
            
            rentfetch_log( "Removed availability information from WordPress post ID $floorplan_in_wordpress->ID (property No. $floorplan_in_wordpress->property_id, floorplan No. $floorplan_in_wordpress->floorplan_id), as this floorplan no longer appears in the Yardi API." );
        }
    }
        
}