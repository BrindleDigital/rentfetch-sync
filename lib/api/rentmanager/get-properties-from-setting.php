<?php
/**
 * Rent manager get the properties list using an API call.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Query the RentManager API for properties and save the shortnames to the settings.
 *
 * @return  void
 */
function rfs_get_rentmanager_properties_from_setting() {

	// Get the enabled integrations.
	$enabled_integrations = get_option( 'rentfetch_options_enabled_integrations' );

	// Bail if rentmanager is not enabled.
	if ( ! in_array( 'rentmanager', $enabled_integrations, true ) ) {
		return;
	}

	$credentials = rfs_get_credentials();

	if ( ! isset( $credentials['rentmanager']['companycode'] ) || ! isset( $credentials['rentmanager']['partner_token'] ) ) {
		update_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames', 'Provide credentials to get properties list.' );
		return;
	}

	$rentmanager_company_code = $credentials['rentmanager']['companycode'];
	$partner_token = $credentials['rentmanager']['partner_token'];

	// Use the proxy endpoint instead of direct API call
	$url = 'https://api.rentfetch.net/wp-json/rentfetchapi/v1/rentmanager/properties-all';

	$body = wp_json_encode(array(
		'company_code' => $rentmanager_company_code,
		'partner_token' => $partner_token
	));

	// Prepare the headers.
	$args = array(
		'headers' => array(
			'Content-Type' => 'application/json',
		),
		'body' => $body,
		'timeout' => 10,
	);

	// Make the request.
	$response = wp_remote_post( $url, $args );

	// Check for errors.
	if ( is_wp_error( $response ) ) {
		update_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames', 'Failed to get properties list.' );
		return;
	}

	// Decode the response body.
	$body = wp_remote_retrieve_body( $response );
	$body = rentfetch_clean_json_string( $body );
	$data = json_decode( $body, true );
	
	if ( !isset(  $response['response']['code'] ) ) {
		update_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames', 'Failed to get properties list.' );
		return;
	} elseif ( $response['response']['code'] !== 200 ) {
		update_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames', 'Error code '. $response['response']['code'] . '. Failed to get properties list.' );
		return;
	}
	
	// Ensure we only store a sanitized list of expected fields.
	$sanitized_data = array();
	if ( is_array( $data ) ) {
		// Support both API shapes: list of properties or a single property object.
		if ( isset( $data['Name'] ) || isset( $data['ShortName'] ) || isset( $data['PropertyID'] ) ) {
			$data = array( $data );
		}

		foreach ( $data as $property ) {
			if ( ! is_array( $property ) ) {
				continue;
			}

			$sanitized_data[] = array(
				'Name'       => isset( $property['Name'] ) ? sanitize_text_field( $property['Name'] ) : '',
				'ShortName'  => isset( $property['ShortName'] ) ? sanitize_text_field( $property['ShortName'] ) : '',
				'PropertyID' => isset( $property['PropertyID'] ) ? absint( $property['PropertyID'] ) : 0,
			);
		}
	}

	// Save the sanitized data.
	update_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames', $sanitized_data );
}
