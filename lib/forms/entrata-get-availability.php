<?php

/**
 * Handle AJAX request to fetch Entrata availability.
 *
 * This function makes the server-side API call to Entrata.
 */
function rentfetch_fetch_entrata_availability_callback() {
	// Check for nonce for security
	check_ajax_referer( 'rentfetch_entrata_tour_availability_check', 'nonce' );

	// Get the property ID from the AJAX request
	$property_id = isset( $_POST['property_id'] ) ? sanitize_text_field( $_POST['property_id'] ) : '';

	if ( empty( $property_id ) ) {
		wp_send_json_error( array( 'message' => 'Property ID is missing.' ) );
		wp_die();
	}
	
	// check for a transient for this property id
	$transient_key = 'entrata_tour_availability_' . $property_id;
	$cached_data = get_transient( $transient_key );
	if ( $cached_data ) {
		wp_send_json_success( $cached_data );
		wp_die();
	}
	
	// If no transient, proceed with the API call
	$args = [
		'integration' => 'entrata',
		'property_id' => $property_id,
		'credentials' => rfs_get_credentials(),
		'floorplan_id' => null,
	];
	
	$entrata_api_key   = rfs_get_entrata_api_key();
	$subdomain = $args['credentials']['entrata']['subdomain'];
	$property_id = $args['property_id'];
	
	if ( empty( $entrata_api_key ) ) {
		wp_send_json_error( array( 'message' => 'Entrata API key is not configured.' ) );
		wp_die();
	}

	// Prepare the data for the Entrata API call
	$api_data = array(
		"auth" => array(
			"type" => "apikey"
		),
		"requestId" => "15",
		"method" => array(
			"name" => "getCalendarAvailability",
			"version" => "r2",
			"params" => array(
				"propertyId" => (int) $property_id,
				"fromDate" => date( 'm/d/Y' ), // Example: Use current date
				"toDate" => date( 'm/d/Y', strtotime( '+1 month' ) ), // Example: 1 month from now
				"tourType" => "AGENT_GUIDED" // Consider if this should be dynamic
			)
		)
	);

	// Entrata API Endpoint
	$api_url = "https://apis.entrata.com/ext/orgs/{$subdomain}/v1/properties";

	// Use WordPress HTTP API for the server-side request
	$response = wp_remote_post(
		$api_url,
		array(
			'method'      => 'POST',
			'timeout'     => 45,
			'headers'     => array(
				'X-Api-Key'    => $entrata_api_key,
				'Content-Type' => 'application/json',
				// Avoid sending client-side cookies from here unless necessary for the API
			),
			'body'        => json_encode( $api_data ),
			'data_format' => 'body',
		)
	);

	// Check for errors in the HTTP request
	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array( 'message' => 'HTTP error: ' . $response->get_error_message() ) );
		wp_die();
	}

	$http_status = wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );

	// Check for non-200 HTTP status codes
	if ( $http_status !== 200 ) {
		$error_message = "Entrata API returned status code: " . $http_status;
		// Attempt to decode JSON body to get more specific error if available
		$error_data = json_decode( $body, true );
		if ( json_last_error() === JSON_ERROR_NONE && isset( $error_data['message'] ) ) {
			$error_message .= ' - ' . $error_data['message'];
		} else {
			$error_message .= ' - Response body: ' . $body;
		}
		wp_send_json_error( array( 'message' => $error_message ) );
		wp_die();
	}

	// Decode the JSON response from the API
	$api_response_data = json_decode( $body, true );

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		wp_send_json_error( array( 'message' => 'Failed to decode API response JSON.' ) );
		wp_die();
	}

	// Set the transient before sending the response
	set_transient( $transient_key, $api_response_data, HOUR_IN_SECONDS );

	// Send the API response back to the JavaScript
	wp_send_json_success( $api_response_data );

	// Check if the API response contains the expected data
	if ( ! isset( $api_response_data['response']['result']['CalendarAvailability'] ) ) {
		wp_send_json_error( array( 'message' => 'Invalid API response structure.' ) );
		wp_die();
	}
}

// Hook the AJAX handler for both logged-in and non-logged-in users
add_action( 'wp_ajax_rentfetch_fetch_entrata_availability', 'rentfetch_fetch_entrata_availability_callback' );
add_action( 'wp_ajax_nopriv_rentfetch_fetch_entrata_availability', 'rentfetch_fetch_entrata_availability_callback' );
