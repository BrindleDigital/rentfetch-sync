<?php
/**
 * Functions to get Properties data from the Entrata API
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get property data from the Entrata API
 *
 * @param   array $args  The arguments for the function.
 *
 * @return  array         The property data
 */
function rfs_entrata_get_property_data( $args ) {
	$api_key   = rfs_get_entrata_api_key();
	$subdomain = $args['credentials']['entrata']['subdomain'];
	$property_id = $args['property_id'];

	// Bail if required arguments are missing.
	if ( ! $api_key || ! $subdomain || ! $property_id ) {
		return;
	}

	// Set the URL for the API request.
	$url = sprintf( 'https://apis.entrata.com/ext/orgs/%s/v1/properties', $subdomain );

	// Set the body for the request.
	$body_array = array(
		'auth' => array(
			'type' => 'apikey',
		),
		'requestId' => '15',
		'method' => array(
			'name' => 'getProperties',
			'params' => array(
				'propertyIds' => $property_id,
			),
		),
	);

	// Convert the body to JSON format.
	$body_json = wp_json_encode( $body_array );

	// Set the headers for the request.
	$headers = array(
		'X-Api-Key'    => $api_key,
		'Content-Type' => 'application/json',
	);

	// Make the API request using wp_remote_post.
	$response = wp_remote_post(
		$url,
		array(
			'headers' => $headers,
			'body'    => $body_json,
			'timeout' => 10,
		)
	);

	// Check for errors.
	if ( is_wp_error( $response ) ) {
		return; // Handle the error as needed.
	}

	// Retrieve and decode the response body.
	$response_body = wp_remote_retrieve_body( $response );
	$property_data = json_decode( $response_body, true );
	
	return $property_data;
}

/**
 * Get property data (lat/long and images) from the Entrata API
 *
 * @param   array $args  The arguments for the function.
 *
 * @return  array         The property data
 */
function rfs_entrata_get_property_mits_data( $args ) {
	$api_key   = rfs_get_entrata_api_key();
	$subdomain = $args['credentials']['entrata']['subdomain'];
	$property_id = $args['property_id'];

	// Bail if required arguments are missing.
	if ( ! $api_key || ! $subdomain || ! $property_id ) {
		return;
	}

	// Set the URL for the API request.
	$url = sprintf( 'https://apis.entrata.com/ext/orgs/%s/v1/propertyunits', $subdomain );

	// Set the body for the request.
	$body_array = array(
		'auth' => array(
			'type' => 'apikey',
		),
		'requestId' => '15',
		'method' => array(
			'name' => 'getMitsPropertyUnits',
			'params' => array(
				'propertyIds' => $property_id,
			),
		),
	);

	// Convert the body to JSON format.
	$body_json = wp_json_encode( $body_array );

	// Set the headers for the request.
	$headers = array(
		'X-Api-Key'    => $api_key,
		'Content-Type' => 'application/json',
	);

	// Make the API request using wp_remote_post.
	$response = wp_remote_post(
		$url,
		array(
			'headers' => $headers,
			'body'    => $body_json,
			'timeout' => 10,
		)
	);

	// Check for errors.
	if ( is_wp_error( $response ) ) {
		return; // Handle the error as needed.
	}

	// Retrieve and decode the response body.
	$response_body = wp_remote_retrieve_body( $response );
	$property_data = json_decode( $response_body, true );
	
	return $property_data;
}