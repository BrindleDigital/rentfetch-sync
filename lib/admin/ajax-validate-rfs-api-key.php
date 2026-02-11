<?php
/**
 * AJAX handler for RFS API key validation
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Validate the RFS API key against the Rent Fetch API
 *
 * @return void
 */
function rfs_validate_api_key_ajax_handler() {
	// Verify nonce
	check_ajax_referer( 'rfs_ajax_nonce', '_ajax_nonce' );

	// Check if API key is provided
	if ( ! isset( $_POST['api_key'] ) ) {
		wp_send_json_error( 'API key is required.' );
	}

	$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ) );

	if ( empty( $api_key ) ) {
		wp_send_json_error( 'API key cannot be empty.' );
	}

	// Validate the API key against the Rent Fetch API
	$validation_result = rfs_validate_api_key_with_api( $api_key );

	if ( is_wp_error( $validation_result ) ) {
		wp_send_json_error( $validation_result->get_error_message() );
	}

	wp_send_json_success( $validation_result );
}
add_action( 'wp_ajax_rfs_validate_api_key', 'rfs_validate_api_key_ajax_handler' );

/**
 * Validate the API key with the Rent Fetch API
 *
 * @param string $api_key The API key to validate.
 *
 * @return array|WP_Error The validation result or WP_Error on failure.
 */
function rfs_validate_api_key_with_api( $api_key ) {
	
	// Get the site URL for validation
	$site_url = get_site_url();
	
	// Clean up the site URL to just the domain name
	$site_url = preg_replace( '#^(https?://)?(www\.)?([^/]+).*$#', '$3', $site_url );

	// Prepare the request body
	$body = array(
		'api_key'  => $api_key,
		'site_url' => $site_url,
	);

	// Get the API URL (allow override via constant for testing)
	$api_url = defined( 'RENTFETCH_API_VALIDATE_URL' ) 
		? constant( 'RENTFETCH_API_VALIDATE_URL' ) 
		: 'https://api.rentfetch.net/wp-json/rentfetchapi/v1/validate-key';

	// Make the API request
	$response = wp_remote_post(
		$api_url,
		array(
			'method'  => 'POST',
			'body'    => wp_json_encode( $body ),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'timeout' => 15,
		)
	);

	// Check for errors
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 
			'api_request_failed', 
			'Unable to connect to Rent Fetch API: ' . $response->get_error_message() 
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$response_body = wp_remote_retrieve_body( $response );

	// Parse the response
	$data = json_decode( $response_body, true );

	// Handle different response codes
	switch ( $response_code ) {
		case 200:
			// Valid API key
			return array(
				'status'  => 'valid',
				'message' => isset( $data['message'] ) 
					? $data['message'] 
					: 'API key is valid and ready to use.',
			);

		case 404:
			// API key not found
			return array(
				'status'  => 'not_found',
				'message' => isset( $data['message'] ) 
					? $data['message'] 
					: 'API key not found. Please check your key and try again.',
			);

		case 409:
			// API key already in use on another site
			$existing_site = isset( $data['existing_site'] ) ? $data['existing_site'] : 'another site';
			return array(
				'status'  => 'in_use',
				'message' => isset( $data['message'] ) 
					? $data['message'] 
					: sprintf( 'This API key is already in use on %s.', $existing_site ),
			);

		default:
			// Unexpected response
			return new WP_Error(
				'unexpected_response',
				sprintf(
					'Unexpected response from API (HTTP %d): %s',
					$response_code,
					isset( $data['message'] ) ? $data['message'] : wp_remote_retrieve_response_message( $response )
				)
			);
	}
}
