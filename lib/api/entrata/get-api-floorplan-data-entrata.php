<?php
/**
 * Functions to get Floorplans data from the Entrata API
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get floorplan data from the Entrata API
 *
 * @param   array $args  The arguments for the function.
 *
 * @return  array         The floorplan data
 */
function rfs_entrata_get_floorplan_data( $args ) {
	$api_key   = rfs_get_entrata_api_key();
	$subdomain = isset( $args['credentials']['entrata']['subdomain'] ) ? $args['credentials']['entrata']['subdomain'] : '';
	$property_id = isset( $args['property_id'] ) ? $args['property_id'] : '';

	// Bail if required arguments are missing.
	if ( ! $api_key || ! $subdomain || ! $property_id ) {
		return array(
			'success'     => false,
			'delete_safe' => false,
			'floorplans'  => null,
			'reason'      => 'missing_credentials',
		);
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
			'name' => 'getFloorPlans',
			'params' => array(
				'propertyId' => $property_id,
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
		return array(
			'success'      => false,
			'delete_safe'  => false,
			'floorplans'   => null,
			'reason'       => 'request_error',
			'raw_response' => $response->get_error_message(),
		);
	}

	// Retrieve and decode the response body.
	$response_code = wp_remote_retrieve_response_code( $response );
	$response_body = wp_remote_retrieve_body( $response );
	$response_body = rentfetch_clean_json_string( $response_body );

	if ( 200 !== (int) $response_code || '' === trim( (string) $response_body ) ) {
		return array(
			'success'      => false,
			'delete_safe'  => false,
			'floorplans'   => null,
			'reason'       => '' === trim( (string) $response_body ) ? 'blank_response' : 'unexpected_response_code',
			'raw_response' => $response_body,
			'status_code'  => (int) $response_code,
		);
	}

	$floorplan_data = json_decode( $response_body, true );

	if ( $floorplan_data === null && json_last_error() !== JSON_ERROR_NONE ) {
		return array(
			'success'      => false,
			'delete_safe'  => false,
			'floorplans'   => null,
			'reason'       => 'invalid_json',
			'raw_response' => $response_body,
			'status_code'  => (int) $response_code,
		);
	}

	if (
		isset( $floorplan_data['response']['result'] )
		&& is_array( $floorplan_data['response']['result'] )
		&& array_key_exists( 'FloorPlans', $floorplan_data['response']['result'] )
		&& is_array( $floorplan_data['response']['result']['FloorPlans'] )
	) {
		$floorplans_container = $floorplan_data['response']['result']['FloorPlans'];
		$floorplans           = array();

		if ( array_key_exists( 'FloorPlan', $floorplans_container ) ) {
			$floorplans = rfs_entrata_normalize_floorplan_collection( $floorplans_container['FloorPlan'] );
		}

		$floorplan_ids = array();
		foreach ( $floorplans as $floorplan ) {
			if ( is_array( $floorplan ) && ! empty( $floorplan['Identification']['IDValue'] ) ) {
				$floorplan_ids[] = (string) $floorplan['Identification']['IDValue'];
			}
		}

		if ( ! empty( $floorplans ) && empty( $floorplan_ids ) ) {
			return array(
				'success'      => false,
				'delete_safe'  => false,
				'floorplans'   => null,
				'data'         => $floorplan_data,
				'reason'       => 'missing_floorplan_ids',
				'raw_response' => $response_body,
				'status_code'  => (int) $response_code,
			);
		}

		return array(
			'success'      => true,
			'delete_safe'  => true,
			'floorplans'   => $floorplans,
			'data'         => $floorplan_data,
			'reason'       => empty( $floorplans ) ? 'valid_empty_floorplans_array' : 'valid_floorplans_array',
			'raw_response' => $response_body,
			'status_code'  => (int) $response_code,
		);
	}

	return array(
		'success'      => false,
		'delete_safe'  => false,
		'floorplans'   => null,
		'data'         => $floorplan_data,
		'reason'       => 'missing_floorplans_key',
		'raw_response' => $response_body,
		'status_code'  => (int) $response_code,
	);
}

/**
 * Normalize Entrata FloorPlan payloads to a list.
 *
 * @param mixed $floorplans Raw Entrata FloorPlan payload.
 * @return array
 */
function rfs_entrata_normalize_floorplan_collection( $floorplans ) {
	if ( ! is_array( $floorplans ) || empty( $floorplans ) ) {
		return array();
	}

	if ( isset( $floorplans['Identification'] ) ) {
		return array( $floorplans );
	}

	return array_values( $floorplans );
}
