<?php 

/**
 * Update the propety metadata
 *
 * @param   [array]  $args          includes everything needed to update the property
 * @param   [array]  $property_data  includes the data to update
 *
 */
function rfs_yardi_update_property_meta( $args, $property_data ) {
	
	// bail if we don't have the wordpress post ID
	if ( !isset( $args['wordpress_property_post_id'] ) || !$args['wordpress_property_post_id'] )
		return;
	
	// bail if we don't have the data to update this, updating the meta to give the error
	if ( !isset( $property_data['PropertyData'] ) || !$property_data['PropertyData'] ) {
		
		$property_data_string = json_encode( $property_data );
		// $success = update_post_meta( $args['wordpress_property_post_id'], 'updated', current_time('mysql') );
		// $success = update_post_meta( $args['wordpress_property_post_id'], 'api_error', $property_data_string );
		
		$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
		
		if ( !is_array( $api_response ) )
			$api_response = [];
	
		$api_response['properties_api'] = [
			'updated' => current_time('mysql'),
			'api_response' => $property_data_string,
		];
		
		$success = update_post_meta( $args['wordpress_property_post_id'], 'api_response', $api_response );
		
		return;
	}

	$data = $property_data['PropertyData'];
	$property_id = $args['property_id'];
	$integration = $args['integration'];
	
	// bail if we don't have the property ID
	if ( !isset( $data['PropertyId'] ) || !$data['PropertyId'] )
		return;
	
	//* Update the title
	$post_info = array(
		'ID' => $args['wordpress_property_post_id'],
		'post_title' => $data['name'],
		'post_name' => sanitize_title( $data['name'] ), // update the permalink to match the new title
	);
	
	wp_update_post( $post_info );
	
	$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
	
	if ( !is_array( $api_response ) )
		$api_response = [];
	
	$api_response['properties_api'] = [
		'updated' => current_time('mysql'),
		'api_response' => 'Updated successfully',
	];
		
	//* Update the meta
	$meta = [
		'property_id' => esc_html( $property_id ),
		'address' => esc_html( $data['address'] ),
		'city' => esc_html( $data['city'] ),
		'state' => esc_html( $data['state'] ),
		'zipcode' => esc_html( $data['zipcode'] ),
		'url' => esc_url( $data['url'] ),
		'description' => esc_attr( $data['description'] ),
		'email' => esc_html( $data['email'] ),
		'phone' => esc_html( $data['phone'] ),
		'latitude' => esc_html( $data['Latitude'] ),
		'longitude' => esc_html( $data['Longitude'] ),
		'updated' => current_time('mysql'),
		'api_response' => $api_response,
	];
	
	foreach ( $meta as $key => $value ) { 
		$success = update_post_meta( $args['wordpress_property_post_id'], $key, $value );
	}
	
}

/**
 * Update the property images
 *
 * @param   [array]  $args              includes everything needed to update the property
 * @param   [string]  $property_images  includes the images json string to update
 */
function rfs_yardi_update_property_images( $args, $property_images ) {
		
	// bail if we don't have the images to update this
	if ( !$property_images ) {
		
		$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
	
		if ( !is_array( $api_response ) )
			$api_response = [];
		
		$api_response['property_images_api'] = [
			'updated' => current_time('mysql'),
			'api_response' => 'No response from API',
		];
		
		return;
	}
	
		
	// bail if we don't have the wordpress post ID
	if ( !isset( $args['wordpress_property_post_id'] ) || !$args['wordpress_property_post_id'] )
		return;
	
	//* Update the meta
	
	
	$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
	
	// check if $property_images includes 'error'
	if ( strpos( $property_images, 'Error' ) !== false ) {
			
		if ( !is_array( $api_response ) )
			$api_response = [];
		
		$api_response['property_images_api'] = [
			'updated' => current_time('mysql'),
			'api_response' => $property_images,
		];
		
		return;
		
	} else {
		$success = update_post_meta( $args['wordpress_property_post_id'], 'yardi_property_images', $property_images );		
		
		if ( !is_array( $api_response ) )
			$api_response = [];
		
		$api_response['property_images_api'] = [
			'updated' => current_time('mysql'),
			'api_response' => 'Updated successfully',
		];
		
		$success = update_post_meta( $args['wordpress_property_post_id'], 'api_response', $api_response );
		
	}
		
}
