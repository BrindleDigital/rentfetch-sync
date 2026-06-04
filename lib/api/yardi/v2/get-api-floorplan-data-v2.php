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
 * Get the floorplan data for a particular property from the Yardi API (v2)
 *
 * @param   array $args  The credentials and property ID.
 *
 * @return  array         The floorplan response, including whether orphan cleanup is safe.
 */
function rfs_yardi_v2_get_floorplan_data( $args ) {

	$yardi_api_key = $args['credentials']['yardi']['apikey'];
	$property_id   = $args['property_id'];
	$access_token  = rfs_get_yardi_bearer_token();
	$company_code  = $args['credentials']['yardi']['company_code'];
	$vendor        = $args['credentials']['yardi']['vendor'];

	// Bail if we don't have a company code or an access token.
	if ( ! $yardi_api_key || ! $property_id || ! $company_code || ! $access_token ) {
		return array(
			'success'     => false,
			'delete_safe' => false,
			'floorplans'  => null,
			'reason'      => 'missing_credentials',
		);
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
		'https://basic.rentcafeapi.com/floorplan/getfloorplans',
		array(
			'headers' => $headers,
			'body'    => $body_json,
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'success'     => false,
			'delete_safe' => false,
			'floorplans'  => null,
			'reason'      => 'request_error',
			'raw_response' => $response->get_error_message(),
		);
	}

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

	$data          = json_decode( $response_body, true );

	if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
		return array(
			'success'      => false,
			'delete_safe'  => false,
			'floorplans'   => null,
			'reason'       => 'invalid_json',
			'raw_response' => $response_body,
			'status_code'  => (int) $response_code,
		);
	}

	if ( isset( $data['floorplans'] ) && is_array( $data['floorplans'] ) ) {
		return array(
			'success'      => true,
			'delete_safe'  => true,
			'floorplans'   => $data['floorplans'],
			'reason'       => empty( $data['floorplans'] ) ? 'valid_empty_floorplans_array' : 'valid_floorplans_array',
			'raw_response' => $response_body,
			'status_code'  => (int) $response_code,
		);
	}

	return array(
		'success'      => false,
		'delete_safe'  => false,
		'floorplans'   => null,
		'reason'       => 'missing_floorplans_key',
		'raw_response' => $response_body,
		'status_code'  => (int) $response_code,
	);
}

/**
 * Save the property-level floorplans API snapshot when the response needs review.
 *
 * Normal successful responses are already stored on each floorplan. This helper
 * records failed or explicitly empty property-level floorplan responses so an
 * empty authoritative response can be distinguished from an outage or malformed
 * payload.
 *
 * @param array $args     Sync args.
 * @param array $response Structured floorplan API response.
 * @return void
 */
function rfs_yardi_v2_update_property_floorplans_api_response( $args, $response ) {
	if ( empty( $args['wordpress_property_post_id'] ) || ! is_array( $response ) ) {
		return;
	}

	$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );

	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	$raw_response = isset( $response['raw_response'] ) ? (string) $response['raw_response'] : '';

	if ( '' === $raw_response && array_key_exists( 'floorplans', $response ) ) {
		$raw_response = wp_json_encode( $response['floorplans'] );
	}

	$api_response['floorplans_api'] = array(
		'updated'       => current_time( 'mysql' ),
		'reason'        => isset( $response['reason'] ) ? sanitize_text_field( $response['reason'] ) : '',
		'delete_safe'   => ! empty( $response['delete_safe'] ) ? 'true' : 'false',
		'status_code'   => isset( $response['status_code'] ) ? absint( $response['status_code'] ) : '',
		'api_response'  => $raw_response,
	);

	update_post_meta( $args['wordpress_property_post_id'], 'api_response', $api_response );
}
