<?php
/**
 * Functions to get Units data from the Entrata API
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get units data from the Entrata API
 *
 * @param   array $args  The arguments for the function.
 *
 * @return  array         The units data
 */
function rfs_entrata_get_unit_data( $args ) {
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
			'name' => 'getUnitsAvailabilityAndPricing',
			'version' => 'r1',
			'params' => array(
				'propertyId' => $property_id,
				'availableUnitsOnly' => '1',
				'unavailableUnitsOnly' => '0',
				'skipPricing' => '0',
				'showChildProperties' => '1',
				'includeDisabledFloorplans' => '0',
				//   'showUnitSpaces' => '1',
				//   'useSpaceConfiguration' => '0',
				//   'allowLeaseExpirationOverride' => '1',
				//   'moveInStartDate' => 'MM/DD/YYYY',
				//   'moveInEndDate' => 'MM/DD/YYYY',
				'includeDisabledUnits' => '0'
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
	$unit_data = json_decode( $response_body, true );
	
	return $unit_data;
}