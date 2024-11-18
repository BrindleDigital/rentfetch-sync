<?php
/**
 * Pull what's needed from the Rent Fetch API.
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get the information from the Rentfetch API.
 *
 * @return  array the response.
 */
function rfs_get_info_from_rentfetch_api() {

	// get the transient and return it if it exists.
	$transient = get_transient( 'rentfetch_api_info' );

	if ( $transient ) {
		// silence is golden.
		return $transient;
	}

	$args = array(
		'site_url'              => get_site_url(),
		'site_name'             => get_bloginfo( 'name' ),
		'current_date_time'     => current_time( 'mysql' ),
		'rentfetch_version'     => defined( 'RENTFETCH_VERSION' ) ? RENTFETCH_VERSION : 'unknown',
		'rentfetchsync_version' => defined( 'RENTFETCHSYNC_VERSION' ) ? RENTFETCHSYNC_VERSION : 'unknown',
		'wordpress_version'     => get_bloginfo( 'version' ),
		'apis_used'             => array(
			'yardi'       => array(
				'api_token'            => get_option( 'rentfetch_options_yardi_integration_creds_yardi_api_key' ),
				'company_code'         => get_option( 'rentfetch_options_yardi_integration_creds_yardi_company_code' ),
				'property_codes'       => get_option( 'rentfetch_options_yardi_integration_creds_yardi_property_code' ),
				'number_of_properties' => null, // todo get this information.
			),
			'realpage'    => array(
				'username'             => get_option( 'rentfetch_options_realpage_integration_creds_realpage_user' ),
				'password'             => get_option( 'rentfetch_options_realpage_integration_creds_realpage_pass' ),
				'pmc_id'               => get_option( 'rentfetch_options_realpage_integration_creds_realpage_pmc_id' ),
				'site_ids'             => get_option( 'rentfetch_options_realpage_integration_creds_realpage_site_ids' ),
				'number_of_properties' => null, // todo get this information.
			),
			'rentmanager' => array(
				'company_code'         => get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_companycode' ),
				'property_shortnames'  => get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames' ),
				'number_of_properties' => null, // todo get this information.
			),
			'manual'      => array(
				'number_of_properties' => null, // todo get this information.
			),
		),
	);

	// turn the array into a json string.
	$args = wp_json_encode( $args );

	// If RENTFETCH_API_URL is defined, constant('RENTFETCH_API_URL') retrieves its value and assigns it to $api_url.
	// If RENTFETCH_API_URL is not defined, the default URL 'https://api.rentfetch.net/wp-json/rentfetchapi/v1/data' is assigned to $api_url.
	$api_url = defined( 'RENTFETCH_API_URL' ) ? constant( 'RENTFETCH_API_URL' ) : 'https://api.rentfetch.net/wp-json/rentfetchapi/v1/data';

	$response = wp_remote_post(
		$api_url,
		array(
			'method'  => 'POST',
			'body'    => $args,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		// When WordPress returns an error.

		$error_message = $response->get_error_message();

		set_transient( 'rentfetch_api_info', $error_message, 5 * MINUTE_IN_SECONDS );

		return "Something went wrong: $error_message";
	} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		// When the response code is not 200.

		$error_message = wp_remote_retrieve_response_message( $response );

		set_transient( 'rentfetch_api_info', $error_message, 5 * MINUTE_IN_SECONDS );

		return "Something went wrong: $error_message";

	} else {
		// When the response code is 200.

		$body               = wp_remote_retrieve_body( $response ); // Retrieve response body.
		$response_php_array = json_decode( $body, true );

		// cache the response for 5 minutes.
		set_transient( 'rentfetch_api_info', $response_php_array, 20 * MINUTE_IN_SECONDS );

		return $response_php_array;
	}
}

/**
 * Grab just the Yardi bearer token from the Rentfetch API.
 *
 * @return  string the token.
 */
function rfs_get_yardi_bearer_token() {
	$response = rfs_get_info_from_rentfetch_api();

	if ( isset( $response['yardi']['access_token'] ) ) {
		$token = stripslashes( $response['yardi']['access_token'] );

		return $token;
	}

	return null;
}

/**
 * Grab just the Rent Manager partner token from the Rentfetch API.
 *
 * @return  string the token.
 */
function rfs_get_rentmanager_partner_token() {
	$response = rfs_get_info_from_rentfetch_api();

	if ( isset( $response['rentmanager']['partner_token'] ) ) {
		$token = stripslashes( $response['rentmanager']['partner_token'] );

		return $token;
	}

	return null;
}