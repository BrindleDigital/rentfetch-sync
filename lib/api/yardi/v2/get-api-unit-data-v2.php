<?php
/**
 * Functions to get floorplan data from the Yardi API (v2)
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get the unit availability for a particular property from the Yardi API (v2)
 *
 * @param   array  $args  The credentials and property ID.
 *
 * @return  array 	   The unit availability.
 */
function rfs_yardi_v2_get_unit_data( $args ) {

	$yardi_api_key = $args['credentials']['yardi']['apikey'];
	$property_id   = $args['property_id'];
	$access_token  = rfs_get_yardi_bearer_token();
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
		// 'floorPlanId'  => $floorplan_id,
	);

	// convert the body to json, as the API requires.
	$body_json = wp_json_encode( $body_array );

	// Do the request.
	$response = wp_remote_post(
		'https://basic.rentcafeapi.com/apartmentavailability/getapartmentavailability',
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
	$response_body = rentfetch_clean_json_string( $response_body );
	$data          = json_decode( $response_body, true );

	if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
		return $response_body; // Return the cleaned JSON string if decode fails
	}

	if ( isset( $data['apartmentAvailabilities'] ) && is_array( $data['apartmentAvailabilities'] ) ) {
		return $data['apartmentAvailabilities'];
	}
	
	return;
}
