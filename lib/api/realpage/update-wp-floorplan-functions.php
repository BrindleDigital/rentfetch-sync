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
