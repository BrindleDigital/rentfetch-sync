<?php
/**
 * Send leads to Entrata API.
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Sends lead data to Entrata API.
 *
 * @param array  $form_data    The form data submitted by the user.
 * @param string $integration  The integration type (e.g., 'entrata').
 * @param string $property_id  The property ID associated with the lead.
 *
 * @return array Array with 'success' (bool), 'status_code' (int), and 'message' (string).
 */
function rentfetch_send_lead_to_entrata( $form_data, $integration, $property_id ) {
	
	$args = [
		'integration' => $integration,
		'property_id' => $property_id,
		'credentials' => rfs_get_credentials(),
		'floorplan_id' => null,
	];
	
	$api_key   = rfs_get_entrata_api_key();
	$subdomain = $args['credentials']['entrata']['subdomain'];
	$property_id = $args['property_id'];

	// Bail if required arguments are missing.
	$missing = [];
	
	if ( ! $api_key ) {
		$missing[] = 'API key';
	}
	
	if ( ! $subdomain ) {
		$missing[] = 'subdomain';
	}
	
	if ( ! $property_id ) {
		$missing[] = 'property ID';
	}
	
	if ( ! empty( $missing ) ) {
		return array(
			'success' => false,
			'status_code' => 0,
			'message' => 'Missing required API information: ' . implode(', ', $missing),
		);
	}
	
	// If the lead_source is not set, set it to the default value.
	if ( empty( $form_data['lead_source'] ) ) {
		$form_data['lead_source'] = '105949'; // Default lead source ID
	}
	
	//! GETLEADS API to check if there's already a lead with this email address.
	
	// Set the URL for the API request.
	$url = sprintf( 'https://apis.entrata.com/ext/orgs/%s/v1/leads', $subdomain );
	
	// Set the body for the request.
	$getleads_body_array = array(
		'auth'      => array(
			'type' => 'apikey',
		),
		'requestId' => '15',
		'method'    => array(
			'name'   => 'getLeads',
			'params' => array(
				'propertyId' => $property_id,
				'email' => $form_data['email'],
				'excludeAmenities' => '1', // Exclude amenities from the response
				'doNotSendConfirmationEmail' => '0',
			),
		),
	);
	
	$getleads_body_json = wp_json_encode( $getleads_body_array );
	
	// Set the headers for the request.
	$headers = array(
		'X-Api-Key'    => $api_key,
		'Content-Type' => 'application/json',
	);

	// Make the API request using wp_remote_post.
	$getleads_response = wp_remote_post(
		$url,
		array(
			'headers' => $headers,
			'body'    => $getleads_body_json,
			'timeout' => 10,
		)
	);
	
	// Retrieve and decode the response body.
	$getleads_response_body = wp_remote_retrieve_body( $getleads_response );
	
	// let's decode the response body
	$getleads_response_body = json_decode( $getleads_response_body, true );
	
	//! If there's an applicationId already, then we don't use the sendLeads API, but we'll instead use the updateLeads API.
	$application_id = (int) $getleads_response_body['response']['result']['prospects'][0]['prospect'][0]['applicationId'] ?? null;
	
	//! SENDLEADS API if this is a brand new lead, or UPDATELEADS API if this is an existing lead with an applicationId.
	
	// Set the URL for the API request.
	$url = sprintf( 'https://apis.entrata.com/ext/orgs/%s/v1/leads', $subdomain );
	
	// Set the body for the request.
	$body_array = array(
		'auth'      => array(
			'type' => 'apikey',
		),
		'requestId' => '15',
		'method'    => array(
			'name'   => $application_id ? 'updateLeads' : 'sendLeads',
			'params' => array(
				'propertyId' => (int) $property_id,
				'doNotSendConfirmationEmail' => '0',
				'isWaitList' => '0',
				'prospects' => array(
					'prospect' => array(
						'leadSource' => array(
							'originatingLeadSourceId' => $form_data['lead_source'],
						),
						'createdDate' => gmdate( 'm/d/Y\TH:i:s', strtotime( '-6 hours' ) ), // this API requires mountain time
						'customers' => array(
							'customer' => array(
								'name' => array(
									'firstName' => $form_data['first_name'],
									'lastName'  => $form_data['last_name'],
								),
								'phone' => array(
									'personalPhoneNumber' => $form_data['phone'],
								),
								'email' => $form_data['email'],
							),
						),
						'customerPreferences' => array(
							'desiredMoveInDate' => ! empty( $form_data['desired_move_in_date'] ) ? $form_data['desired_move_in_date'] : null,
							'comment'            => ! empty( $form_data['message'] ) ? $form_data['message'] : 'customer Preferences Comment',
						),
					),
				),
			),
		),
	);
	
	// If the applicationId is set, add it to the body
	if ( ! empty( $application_id ) ) {
		
		// add the applicationId to the body.
		$body_array['method']['params']['prospects']['prospect']['applicationId'] = $application_id;
		
		// add the version 'r2' to the method.
		$body_array['method']['version'] = 'r2';
		
	}
	
	// If  $form_data['schedule'] is set, add it to the body
	if ( ! empty( $form_data['appointment_date'] ) && ! empty( $form_data['appointment_start_time'] ) && ! empty( $form_data['appointment_end_time'] ) ) {
		$body_array['method']['params']['prospects']['prospect']['events']['event'] = array(
			array(
				'type'            => 'Appointment',
				'eventTypeId'     => '17', // this is the nomenclature for sendLeads.
				'subtypeId'       => '454',
				'date'            => gmdate( 'm/d/Y\TH:i:s', strtotime( '-6 hours' ) ), // this API requires mountain time
				'appointmentDate' => $form_data['appointment_date'],
				'timeFrom'        => $form_data['appointment_start_time'],
				'timeTo'          => $form_data['appointment_end_time'],
				'eventReasons'    => 'Tour',
				'typeId'          => '17', // required for updateLeads.
				'title'           => 'Appointment', // required for updateLeads.
				'comments'        => 'n/a', // required to have a value for updateLeads. For sendLeads, a value is not required.
			),
		);
	}

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
	
	// Retrieve and decode the response body.
	$response_body = wp_remote_retrieve_body( $response );
	
	//! This is important for every API. Needs to work similarly once those are implemented.
	// if the debug parameter is 1, output the response body
	if ( isset( $form_data['debug'] ) && 1 === (int) $form_data['debug'] ) {
		wp_send_json( $response_body );
	}

	// let's decode the response body
	$response_body = json_decode( $response_body, true );
	
	// Check if the request was successful
	$http_status = (int) $response_body['response']['code'];
	
	// Any non-200 HTTP status is an error
	if ( 200 !== $http_status ) {
		error_log( 'Entrata API Error - HTTP Status: ' . $http_status . ', Response: ' . wp_json_encode( $response_body ) );
		
		$error_message = '';
		if ( isset( $response_body['response']['result']['prospects']['prospect'][0]['message'] ) ) {
			$error_message = $response_body['response']['result']['prospects']['prospect'][0]['message'];
		} elseif ( isset( $response_body['response']['result']['prospects'][0]['message'] ) ) {
			$error_message = $response_body['response']['result']['prospects'][0]['message'];
		} elseif ( isset( $response_body['response']['error']['message'] ) ) {
			$error_message = $response_body['response']['error']['message'];
			// Append Entrata error code if available
			if ( isset( $response_body['response']['error']['code'] ) ) {
				$error_message .= ' (Error Code: ' . $response_body['response']['error']['code'] . ')';
			}
		} elseif ( isset( $response_body['error'] ) ) {
			$error_message = $response_body['error'];
		}
		
		return array(
			'success' => false,
			'status_code' => $http_status,
			'message' => $error_message ?: 'HTTP ' . $http_status . ' error',
		);
	}
	
	// HTTP 200 - check for error indicators in the response body
	$has_error = false;
	$error_message = '';
	if ( isset( $response_body['response']['result']['prospects']['prospect'][0]['message'] ) ) {
		$message = $response_body['response']['result']['prospects']['prospect'][0]['message'];
		// Check if the message indicates an error
		if ( stripos( $message, 'error' ) !== false || 
			 stripos( $message, 'failed' ) !== false || 
			 stripos( $message, 'invalid' ) !== false ||
			 stripos( $message, 'denied' ) !== false ) {
			$has_error = true;
			$error_message = $message;
		}
	}
	
	if ( ! $has_error ) {
		return array(
			'success' => true,
			'status_code' => 200,
			'message' => 'Lead submitted successfully',
		);
	}
	
	// Error found in 200 response
	error_log( 'Entrata API Error in 200 response - Response: ' . wp_json_encode( $response_body ) );
	
	return array(
		'success' => false,
		'status_code' => 200,
		'message' => $error_message ?: 'Unknown error in successful response',
	);
	
}