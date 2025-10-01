<?php
/**
 * Functions to update property data in WordPress from the Yardi API (v2)
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Update the property meta (API v2)
 *
 * @param   array  $args           [$args description]
 * @param   array  $property_data  [$property_data description]
 *
 * @return  null                  There's no information being returned.
 */
function rfs_yardi_v2_update_property_meta( $args, $property_data ) {
	
	// bail if we don't have the wordpress post ID
	if ( !isset( $args['wordpress_property_post_id'] ) || !$args['wordpress_property_post_id'] )
		return;

	// If property_data is a string (cleaned JSON from decode failure), save it directly
	if ( is_string( $property_data ) ) {
		$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
		
		if ( !is_array( $api_response ) )
			$api_response = [];
	
		$api_response['properties_api'] = [
			'updated' => current_time('mysql'),
			'api_response' => $property_data,
		];
		
		$success = update_post_meta( $args['wordpress_property_post_id'], 'api_response', $api_response );
		return;
	}
	
	// bail if we don't have the data to update this, updating the meta to give the error
	if ( !isset( $property_data['properties'][0] ) || !$property_data['properties'][0] ) {
		
		$property_data_string = json_encode( $property_data );
		// $success = update_post_meta( $args['wordpress_property_post_id'], 'updated', current_time('mysql') );
		// $success = update_post_meta( $args['wordpress_property_post_id'], 'api_error', $property_data_string );
		
		$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
		
		if ( !is_array( $api_response ) )
			$api_response = [];
	
		// Clean the JSON string to handle smart quotes and other issues
		$property_data_string = rentfetch_clean_json_string( $property_data_string );
	
		$api_response['properties_api'] = [
			'updated' => current_time('mysql'),
			'api_response' => $property_data_string,
		];
		
		$success = update_post_meta( $args['wordpress_property_post_id'], 'api_response', $api_response );
		
		return;
	}

	$data = $property_data['properties'][0];
	$property_id = $args['property_id'];
	
	// bail if we don't have the property ID
	if ( !isset( $data['propertyId'] ) || !$data['propertyId'] )
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
	
	$property_data_string = json_encode( $property_data );
	
	$api_response['properties_api'] = [
		'updated' => current_time('mysql'),
		'api_response' => $property_data_string,
	];
		
	//* Update the meta
	$meta = [
		'property_id' => esc_html( $property_id ),
		'address' => esc_html( $data['address'] ),
		'city' => esc_html( $data['city'] ),
		'state' => esc_html( $data['state'] ),
		'zipcode' => esc_html( $data['zipcode'] ),
		'url' => esc_url( $data['url'] ),
		'description' => esc_html( $data['description'] ),
		'email' => esc_html( $data['email'] ),
		'phone' => esc_html( $data['phone'] ),
		'latitude' => esc_html( $data['latitude'] ),
		'longitude' => esc_html( $data['longitude'] ),
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
function rfs_yardi_v2_update_property_images( $args, $property_images ) {
	
	// bail if we don't have the wordpress post ID.
	if ( !isset( $args['wordpress_property_post_id'] ) || !$args['wordpress_property_post_id'] ) {
		return;
	}
		
	// bail if we don't have the images to update this, updating the meta to say what happened.
	if ( !$property_images ) {
		
		$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
	
		if ( !is_array( $api_response ) )
			$api_response = [];
		
		$api_response['property_images_api'] = [
			'updated' => current_time( 'mysql' ),
			'api_response' => wp_json_encode( $property_images ),
		];
		
		return;
	}

	//* Update the meta.
	$success = update_post_meta( $args['wordpress_property_post_id'], 'synced_property_images', $property_images );		
	
	//* Update the API response.
	$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );

	if ( !is_array( $api_response ) ) {
		$api_response = [];
	}
	
	$property_images_string = json_encode( $property_images );
	
	// Clean the JSON string
	$property_images_string = rentfetch_clean_json_string( $property_images_string );
	
	$api_response['property_images_api'] = [
		'updated' => current_time('mysql'),
		'api_response' => $property_images_string,
	];
	
	$success = update_post_meta( $args['wordpress_property_post_id'], 'api_response', $api_response );
		
}