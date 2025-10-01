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
	$url                      = sprintf( 'https://%s.api.rentmanager.com/Properties?fields=Name,PropertyID,ShortName', $rentmanager_company_code );

	$partner_token = $credentials['rentmanager']['partner_token'];

	// Prepare the headers.
	$args = array(
		'headers' => array(
			'X-RM12API-PartnerToken' => $partner_token,
			'Content-Type'           => 'application/json',
		),
		'timeout' => 10,
	);

	// Make the request.
	$response = wp_remote_get( $url, $args );

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
	
	// Escape the array.
	$filters = array(
		'Name'       => 'sanitize_text_field',
		'ShortName'  => 'sanitize_text_field',
		'PropertyID' => 'absint',
	);

	// Sanitize the entire array.
	$sanitized_data = array_map(
		function ( $value, $key ) use ( $filters ) {
			return isset( $filters[ $key ] ) ? call_user_func( $filters[ $key ], $value ) : $value;
		},
		$data,
		array_keys( $data )
	);

	// Save the sanitized data.
	update_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames', $sanitized_data );
}
