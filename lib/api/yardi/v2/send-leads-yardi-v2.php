<?php
/**
 * Send leads to Yardi API.
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Sends a lead to Yardi API.
 *
 * @param array  $form_data    The form data submitted by the user.
 * @param string $property_source The integration type (e.g., 'yardi').
 * @param string $property     The property code.
 *
 * @return array Array with 'success' (bool), 'status_code' (int), and 'message' (string).
 */
function rentfetch_send_lead_to_yardi( $form_data, $property_source, $property ) {
	
	// Get the bearer token.
	$bearer_token = rfs_get_yardi_bearer_token();
	if ( ! $bearer_token ) {
		return array(
			'success'     => false,
			'status_code' => 0,
			'message'     => 'Yardi bearer token not available.',
		);
	}
	
	// Get credentials.
	$creds = rfs_get_credentials();
	if ( ! isset( $creds['yardi'] ) ) {
		return array(
			'success'     => false,
			'status_code' => 0,
			'message'     => 'Yardi credentials not configured.',
		);
	}
	
	$api_token    = $creds['yardi']['apikey'];
	$company_code = $creds['yardi']['company_code'];
	$vendor_email = $creds['yardi']['vendor'];
	
	// Prepare the data for the API request.
	$data = array(
		'apiToken'         => $api_token,
		'companyCode'      => $company_code,
		'propertyCode'     => $property, // $property is the property code.
		'firstName'        => $form_data['first_name'] ?? null,
		'lastName'         => $form_data['last_name'] ?? null,
		'email'            => $form_data['email'] ?? null,
		'phone'            => $form_data['phone'] ?? null,
		'desiredMoveinDate' => null, // Not provided in form_data, can be added if needed.
		'message'          => $form_data['message'] ?? null,
	);
	
	// Add lead source if available.
	if ( ! empty( $form_data['lead_source'] ) ) {
		$data['source'] = $form_data['lead_source'];
	}
	
	// Prepare headers.
	$headers = array(
		'Content-Type'  => 'application/json',
		'vendor'        => $vendor_email,
		'Authorization' => 'Bearer ' . $bearer_token,
	);
	
	// First, attempt to create the lead.
	$create_response = rentfetch_yardi_create_lead( $data, $headers );
	
	if ( is_wp_error( $create_response ) ) {
		return array(
			'success'     => false,
			'status_code' => 0,
			'message'     => 'Create API request failed: ' . $create_response->get_error_message(),
		);
	}
	
	$http_status = wp_remote_retrieve_response_code( $create_response );
	$response_body = wp_remote_retrieve_body( $create_response );
	
	// If successful, check for prospect match.
	if ( 200 === $http_status ) {
		$response_data = json_decode( $response_body, true );
		
		// If there's a prospect match, update the lead instead.
		if ( isset( $response_data['isProspectMatch'] ) && true === $response_data['isProspectMatch'] ) {
			
			// Determine the update identifier.
			$update_id = null;
			$update_value = null;
			
			if ( isset( $response_data['rentCafeProspectId'] ) && ! empty( $response_data['rentCafeProspectId'] ) ) {
				$update_id = 'rentCafeProspectId';
				$update_value = $response_data['rentCafeProspectId'];
			} elseif ( isset( $response_data['voyagerProspectId'] ) && ! empty( $response_data['voyagerProspectId'] ) ) {
				$update_id = 'voyagerProspectId';
				$update_value = $response_data['voyagerProspectId'];
			} elseif ( isset( $response_data['voyagerProspectCode'] ) && ! empty( $response_data['voyagerProspectCode'] ) ) {
				$update_id = 'voyagerProspectCode';
				$update_value = $response_data['voyagerProspectCode'];
			}
			
			if ( $update_id && $update_value ) {
				// Prepare data for update: add identifier.
				$update_data = $data;
				$update_data[ $update_id ] = $update_value;
				
				// Update the lead.
				$update_response = rentfetch_yardi_update_lead( $update_data, $headers );
				
				if ( is_wp_error( $update_response ) ) {
					return array(
						'success'     => false,
						'status_code' => 0,
						'message'     => 'Update API request failed: ' . $update_response->get_error_message(),
					);
				}
				
				$update_http_status = wp_remote_retrieve_response_code( $update_response );
				$update_response_body = wp_remote_retrieve_body( $update_response );
				
				if ( 200 === $update_http_status ) {
					$message = 'Lead updated successfully.';
					if ( isset( $response_data['prospectMatchNotes'] ) && ! empty( $response_data['prospectMatchNotes'] ) ) {
						$message .= ' ' . $response_data['prospectMatchNotes'];
					}
					return array(
						'success'     => true,
						'status_code' => 200,
						'message'     => $message,
					);
				} else {
					$update_error_data = json_decode( $update_response_body, true );
					$update_error_message = isset( $update_error_data['errorMessage'] ) && ! empty( $update_error_data['errorMessage'] ) ? 'Update API: ' . $update_error_data['errorMessage'] : 'Update API: HTTP ' . $update_http_status . ' error.';
					
					error_log( 'Yardi API update error - HTTP Status: ' . $update_http_status . ', Response: ' . $update_response_body );
					return array(
						'success'     => false,
						'status_code' => $update_http_status,
						'message'     => $update_error_message,
					);
				}
			}
		}
		
		// If no match or update not needed, return success for create.
		return array(
			'success'     => true,
			'status_code' => 200,
			'message'     => 'Lead submitted successfully.',
		);
	} else {
		$error_data = json_decode( $response_body, true );
		$error_message = isset( $error_data['errorMessage'] ) && ! empty( $error_data['errorMessage'] ) ? 'Create API: ' . $error_data['errorMessage'] : 'Create API: HTTP ' . $http_status . ' error.';
		
		error_log( 'Yardi API error - HTTP Status: ' . $http_status . ', Response: ' . $response_body );
		return array(
			'success'     => false,
			'status_code' => $http_status,
			'message'     => $error_message,
		);
	}
}

/**
 * Sends a create lead request to Yardi API.
 *
 * @param array $data    The data to send.
 * @param array $headers The headers for the request.
 *
 * @return array|WP_Error The response or WP_Error on failure.
 */
function rentfetch_yardi_create_lead( $data, $headers ) {
	$url  = 'https://basic.rentcafeapi.com/lead/createlead';
	$args = array(
		'body'    => wp_json_encode( $data ),
		'headers' => $headers,
		'method'  => 'POST',
		'timeout' => 10,
	);
	
	return wp_remote_post( $url, $args );
}

/**
 * Sends an update lead request to Yardi API.
 *
 * @param array $data    The data to send.
 * @param array $headers The headers for the request.
 *
 * @return array|WP_Error The response or WP_Error on failure.
 */
function rentfetch_yardi_update_lead( $data, $headers ) {
	$url  = 'https://basic.rentcafeapi.com/lead/updatelead';
	$args = array(
		'body'    => wp_json_encode( $data ),
		'headers' => $headers,
		'method'  => 'POST',
		'timeout' => 10,
	);
	
	return wp_remote_post( $url, $args );
}