<?php
/**
 * Functions to get property data from the Yardi API (v2)
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get property data from the Yardi API (v2)
 *
 * @param   array $args  The arguments for the function.
 *
 * @return  array         The property data
 */
function rfs_yardi_v2_get_property_data( $args ) {

	$yardi_api_key = $args['credentials']['yardi']['apikey'];
	$property_id   = $args['property_id'];
	$access_token  = $args['credentials']['yardi']['access_token'];
	$company_code  = $args['credentials']['yardi']['company_code'];
	$vendor        = $args['credentials']['yardi']['vendor'];

	// Bail if we don't have a company code or an access token.
	if ( ! $yardi_api_key || ! $property_id || ! $company_code || ! $access_token ) {
		return;
	}

	// set the headers for the request.
	$headers = array(
		'vendor'        => $vendor,
		'Authorization' => 'Bearer ' . $access_token,
		'Content-Type'  => 'application/json',
	);

	// set the body for the request.
	$body_array = array(
		'apiToken'     => $yardi_api_key,
		'companyCode'  => $company_code,
		'propertyCode' => $property_id,
	);

	// convert the body to json, as the API requires.
	$body_json = wp_json_encode( $body_array );

	// Do the request.
	$response = wp_remote_post(
		'https://basic.rentcafeapi.com/property/getmarketingdetails',
		array(
			'headers' => $headers,
			'body'    => $body_json,
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return; // Handle errors as needed.
	}

	$response_body = wp_remote_retrieve_body( $response );
	$data = json_decode( $response_body, true );
	return $data;
}

/**
 * Get the property images from the Yardi API (v2)
 *
 * @param   array  $args  The arguments for the function.
 *
 * @return  array         The property images.
 */
function rfs_yardi_v2_get_property_images( $args ) {
	
	$yardi_api_key = $args['credentials']['yardi']['apikey'];
	$property_id   = $args['property_id'];
	$access_token  = $args['credentials']['yardi']['access_token'];
	$company_code  = $args['credentials']['yardi']['company_code'];
	$vendor        = $args['credentials']['yardi']['vendor'];

	// Bail if we don't have a company code or an access token.
	if ( ! $yardi_api_key || ! $property_id || ! $company_code || ! $access_token ) {
		return;
	}

	// set the headers for the request.
	$headers = array(
		'vendor'        => $vendor,
		'Authorization' => 'Bearer ' . $access_token,
		'Content-Type'  => 'application/json',
	);

	// set the body for the request.
	$body_array = array(
		'apiToken'     => $yardi_api_key,
		'companyCode'  => $company_code,
		'propertyCode' => $property_id,
	);

	// convert the body to json, as the API requires.
	$body_json = wp_json_encode( $body_array );

	// Do the request.
	$response = wp_remote_post(
		'https://basic.rentcafeapi.com/images/getpropertyimages',
		array(
			'headers' => $headers,
			'body'    => $body_json,
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return; // Handle errors as needed.
	}

	$response_body = wp_remote_retrieve_body( $response );
	$data = json_decode( $response_body, true );
	
	if ( isset( $data['images'] ) ) {
		return $data['images'];
	}
	
	return;
	
}

function rfs_yardi_v2_update_property_amenities( $args, $property_data ) {
		
	// bail if we don't have Amenities in the data	
	if ( !isset( $property_data['amenities'] ) ) {
		return;
	}
		
	$amenities = $property_data['amenities'];
		
	// bail if we don't have the property ID.
	if ( !isset( $args['wordpress_property_post_id'] ) ) {
		return;
	}
	
	$property_id = $args['wordpress_property_post_id'];
	$taxonomy = 'amenities';
	
	// remove all of the current terms from $property_id
	$term_ids = wp_get_object_terms( $property_id, $taxonomy, array( 'fields' => 'ids' ) );
	$term_ids = array_map( 'intval', $term_ids );
	wp_remove_object_terms( $property_id, $term_ids, $taxonomy );
		
	// for each of those amenities, grab the name, then add it
	foreach ( $amenities as $amenity ) {
		
		if ( !isset( $amenity['customAmenityName'] ) ) {
			continue;
		}
		
		// get the name
		$name = $amenity['customAmenityName'];
						
		// this function checks if the amenity exists, creates it if not, then adds it to the post
		rentfetch_set_post_term( $property_id, $name, 'amenities' );
	}
	
}