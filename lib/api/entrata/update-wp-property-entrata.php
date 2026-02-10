<?php
/**
 * Functions to update property data in WordPress from the Entrata API.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Update the property meta
 *
 * @param   array  $args           [$args description]
 * @param   array  $property_data  [$property_data description]
 *
 * @return  null                  There's no information being returned.
 */
function rfs_entrata_update_property_meta( $args, $property_data ) {
	
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
	if ( !isset( $property_data['response']['result']['PhysicalProperty']['Property'][0] ) ) {
		
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

	$data = $property_data['response']['result']['PhysicalProperty']['Property'][0];
	$property_id = $args['property_id'];
	
	// bail if we don't have the property ID
	if ( !isset( $data['PropertyID'] ) || !$data['PropertyID'] )
		return;
	
	//* Update the title
	$post_info = array(
		'ID' => $args['wordpress_property_post_id'],
		'post_title' => $data['MarketingName'],
		'post_name' => sanitize_title( $data['MarketingName'] ), // update the permalink to match the new title
	);
	
	wp_update_post( $post_info );
	
	$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
	
	if ( !is_array( $api_response ) )
		$api_response = [];
	
	$property_data_string = wp_json_encode( $data );

	$api_response['properties_api'] = [
		'updated' => current_time('mysql'),
		'api_response' => $property_data_string,
	];
		
	//* Update the meta
	$meta = [
		'property_id' => esc_html( $property_id ),
		'address' => esc_html( $data['Address']['Address'] ),
		'city' => esc_html( $data['Address']['City'] ),
		'state' => esc_html( $data['Address']['State'] ),
		'zipcode' => esc_html( $data['Address']['PostalCode'] ),
		'email' => esc_html( $data['Address']['Email'] ),
		'url' => esc_url( $data['webSite'] ),
		'description' => esc_attr( $data['LongDescription'] ),
		// 'phone' => esc_html( $data['phone'] ), // don't see a phone number in the API here...
		// 'latitude' => esc_html( $data['latitude'] ), not in the API here...
		// 'longitude' => esc_html( $data['longitude'] ), not in the API here...
		'updated' => current_time('mysql'),
		'api_response' => $api_response,
	];
	
	foreach ( $meta as $key => $value ) { 
		$success = update_post_meta( $args['wordpress_property_post_id'], $key, $value );
	}
	
}

/**
 * Update the property meta (lat/long, images, etc.)
 *
 * @param   array  $args           [$args description]
 * @param   array  $property_data  [$property_data description]
 *
 * @return  null                  There's no information being returned.
 */
function rfs_entrata_update_property_mits_meta( $args, $property_data ) {
	
	// bail if we don't have the wordpress post ID
	if ( !isset( $args['wordpress_property_post_id'] ) || !$args['wordpress_property_post_id'] )
		return;

	// If property_data is a string (cleaned JSON from decode failure), save it directly
	if ( is_string( $property_data ) ) {
		$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
		
		if ( !is_array( $api_response ) )
			$api_response = [];
	
		$api_response['getMitsPropertyUnits'] = [
			'updated' => current_time('mysql'),
			'api_response' => $property_data,
		];
		
		$success = update_post_meta( $args['wordpress_property_post_id'], 'api_response', $api_response );
		return;
	}

	// bail if we don't have the data to update this, updating the meta to give the error
	if ( !isset( $property_data['response']['result']['PhysicalProperty']['Property'][0] ) ) {
		
		$property_data_string = json_encode( $property_data );
		// $success = update_post_meta( $args['wordpress_property_post_id'], 'updated', current_time('mysql') );
		// $success = update_post_meta( $args['wordpress_property_post_id'], 'api_error', $property_data_string );
		
		$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
		
		if ( !is_array( $api_response ) )
			$api_response = [];
	
		// Clean the JSON string
		$property_data_string = rentfetch_clean_json_string( $property_data_string );
	
		$api_response['getMitsPropertyUnits'] = [
			'updated' => current_time('mysql'),
			'api_response' => $property_data_string,
		];
		
		$success = update_post_meta( $args['wordpress_property_post_id'], 'api_response', $api_response );
		
		return;
	}

	$data = $property_data['response']['result']['PhysicalProperty']['Property'][0];
	$property_id = $args['property_id'];
	
	// bail if we don't have the property ID
	if ( !isset( $data['Identification']['IDValue'] ) || !$data['Identification']['IDValue'] )
		return;
	
	$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
	
	if ( !is_array( $api_response ) )
		$api_response = [];
	
	$property_data_string = wp_json_encode( $data );

	$api_response['getMitsPropertyUnits'] = [
		'updated' => current_time('mysql'),
		'api_response' => $property_data_string,
	];
	
	$sanitize_mixed = static function( $value ) use ( &$sanitize_mixed ) {
		if ( is_array( $value ) ) {
			return array_map( $sanitize_mixed, $value );
		}

		if ( is_scalar( $value ) || null === $value ) {
			return sanitize_text_field( (string) $value );
		}

		return '';
	};
		
	//* Update the meta
	$meta = [
		// 'property_id' => esc_html( $property_id ),
		// 'address' => esc_html( $data['Address']['Address'] ),
		// 'city' => esc_html( $data['Address']['City'] ),
		// 'state' => esc_html( $data['Address']['State'] ),
		// 'zipcode' => esc_html( $data['Address']['PostalCode'] ),
		// 'email' => esc_html( $data['Address']['Email'] ),
		// 'url' => esc_url( $data['webSite'] ),
		// 'description' => esc_attr( $data['LongDescription'] ),
		'phone' => isset( $data['PropertyID']['Phone'][0]['PhoneNumber'] ) ? sanitize_text_field( $data['PropertyID']['Phone'][0]['PhoneNumber'] ) : '',
		'latitude' => isset( $data['ILS_Identification']['Latitude'] ) ? sanitize_text_field( $data['ILS_Identification']['Latitude'] ) : '',
		'longitude' => isset( $data['ILS_Identification']['Longitude'] ) ? sanitize_text_field( $data['ILS_Identification']['Longitude'] ) : '',
		'synced_property_images' => isset( $data['File'] ) ? $sanitize_mixed( $data['File'] ) : [],
		// 'updated' => current_time('mysql'),
		'api_response' => $api_response,
	];
	
	foreach ( $meta as $key => $value ) { 
		$success = update_post_meta( $args['wordpress_property_post_id'], $key, $value );
	}
	
}
