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
	$subdomain = isset( $args['credentials']['entrata']['subdomain'] ) ? $args['credentials']['entrata']['subdomain'] : '';
	$property_id = isset( $args['property_id'] ) ? $args['property_id'] : '';

	// Bail if required arguments are missing.
	if ( ! $api_key || ! $subdomain || ! $property_id ) {
		return array(
			'success'     => false,
			'delete_safe' => false,
			'units'       => null,
			'reason'      => 'missing_credentials',
		);
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
		return array(
			'success'      => false,
			'delete_safe'  => false,
			'units'        => null,
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
			'units'        => null,
			'reason'       => '' === trim( (string) $response_body ) ? 'blank_response' : 'unexpected_response_code',
			'raw_response' => $response_body,
			'status_code'  => (int) $response_code,
		);
	}

	$unit_data = json_decode( $response_body, true );

	if ( $unit_data === null && json_last_error() !== JSON_ERROR_NONE ) {
		return array(
			'success'      => false,
			'delete_safe'  => false,
			'units'        => null,
			'reason'       => 'invalid_json',
			'raw_response' => $response_body,
			'status_code'  => (int) $response_code,
		);
	}

	if (
		isset( $unit_data['response']['result'] )
		&& is_array( $unit_data['response']['result'] )
		&& array_key_exists( 'ILS_Units', $unit_data['response']['result'] )
		&& is_array( $unit_data['response']['result']['ILS_Units'] )
	) {
		$ils_units = $unit_data['response']['result']['ILS_Units'];
		$units     = array();

		if ( array_key_exists( 'Unit', $ils_units ) ) {
			$units = rfs_entrata_normalize_unit_collection( $ils_units['Unit'] );
		}

		$unit_ids = array();
		foreach ( $units as $unit ) {
			if ( is_array( $unit ) && ! empty( $unit['@attributes']['PropertyUnitId'] ) ) {
				$unit_ids[] = (string) $unit['@attributes']['PropertyUnitId'];
			}
		}

		if ( ! empty( $units ) && empty( $unit_ids ) ) {
			return array(
				'success'      => false,
				'delete_safe'  => false,
				'units'        => null,
				'data'         => $unit_data,
				'reason'       => 'missing_unit_ids',
				'raw_response' => $response_body,
				'status_code'  => (int) $response_code,
			);
		}

		return array(
			'success'      => true,
			'delete_safe'  => true,
			'units'        => $units,
			'data'         => $unit_data,
			'reason'       => empty( $units ) ? 'valid_empty_units_array' : 'valid_units_array',
			'raw_response' => $response_body,
			'status_code'  => (int) $response_code,
		);
	}

	return array(
		'success'      => false,
		'delete_safe'  => false,
		'units'        => null,
		'data'         => $unit_data,
		'reason'       => 'missing_units_key',
		'raw_response' => $response_body,
		'status_code'  => (int) $response_code,
	);
}

/**
 * Normalize Entrata Unit payloads to a list.
 *
 * @param mixed $units Raw Entrata Unit payload.
 * @return array
 */
function rfs_entrata_normalize_unit_collection( $units ) {
	if ( ! is_array( $units ) || empty( $units ) ) {
		return array();
	}

	if ( isset( $units['@attributes'] ) ) {
		return array( $units );
	}

	return array_values( $units );
}
