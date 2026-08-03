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
 * Store or clear the latest property-level apartment availability response.
 *
 * @param array      $args     Sync arguments.
 * @param array|null $response Response to store, or null after a successful response.
 * @return void
 */
function rfs_yardi_v2_update_property_units_api_response( $args, $response = null ) {
	if ( empty( $args['wordpress_property_post_id'] ) ) {
		return;
	}

	$property_id  = $args['wordpress_property_post_id'];
	$api_response = get_post_meta( $property_id, 'api_response', true );

	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	if ( null === $response ) {
		if ( isset( $api_response['apartmentavailability_api'] ) ) {
			unset( $api_response['apartmentavailability_api'] );
			update_post_meta( $property_id, 'api_response', $api_response );
		}

		return;
	}

	$api_response['apartmentavailability_api'] = array(
		'updated'      => current_time( 'mysql' ),
		'reason'       => isset( $response['reason'] ) ? sanitize_text_field( $response['reason'] ) : '',
		'delete_safe'  => ! empty( $response['delete_safe'] ) ? 'true' : 'false',
		'status_code'  => isset( $response['status_code'] ) ? absint( $response['status_code'] ) : '',
		'api_response' => isset( $response['raw_response'] ) ? (string) $response['raw_response'] : '',
	);

	update_post_meta( $property_id, 'api_response', $api_response );
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

	$response_code = wp_remote_retrieve_response_code( $response );

	if ( 204 === (int) $response_code ) {
		rfs_yardi_v2_update_property_units_api_response(
			$args,
			array(
				'reason'       => 'blank_response',
				'delete_safe'  => true,
				'status_code'  => 204,
				'raw_response' => '',
			)
		);
		rfs_delete_orphan_units_if_property_204_response( $args, array() );
		return array();
	}
	
	if ( 304 === (int) $response_code ) {
		rfs_yardi_v2_update_property_units_api_response( $args );
		return '304';
	}

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
		rfs_yardi_v2_update_property_units_api_response( $args );
		return $data['apartmentAvailabilities'];
	}
		
	return;
}
