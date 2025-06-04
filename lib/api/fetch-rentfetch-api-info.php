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
	
	// Let's build the array piece by piece.
	$apis_used = array();
	
	$apis_enabled = get_option( 'rentfetch_options_enabled_integrations' );
	
	// if $apis_enabled is not an array, make it one.
	if ( ! is_array( $apis_enabled ) ) {
		$apis_enabled = array();
	}
	
	// get the Yardi integration settings.
	if ( in_array( 'yardi', $apis_enabled, true ) ) {
		$apis_used['yardi'] = array(
			'api_token'            => get_option( 'rentfetch_options_yardi_integration_creds_yardi_api_key' ),
			'company_code'         => get_option( 'rentfetch_options_yardi_integration_creds_yardi_company_code' ),
			'property_codes'       => get_option( 'rentfetch_options_yardi_integration_creds_yardi_property_code' ),
			'number_of_properties' => rfs_get_number_of_properties( 'yardi' ),
		);
	}
	
	// get the RealPage integration settings.
	if ( in_array( 'realpage', $apis_enabled, true ) ) {
		$apis_used['realpage'] = array(
			'username'             => get_option( 'rentfetch_options_realpage_integration_creds_realpage_user' ),
			'password'             => get_option( 'rentfetch_options_realpage_integration_creds_realpage_pass' ),
			'pmc_id'               => get_option( 'rentfetch_options_realpage_integration_creds_realpage_pmc_id' ),
			'site_ids'             => get_option( 'rentfetch_options_realpage_integration_creds_realpage_site_ids' ),
			'number_of_properties' => rfs_get_number_of_properties( 'realpage' ),
		);
	}
	
	// get the Rent Manager integration settings.
	if ( in_array( 'rentmanager', $apis_enabled, true ) ) {
		$apis_used['rentmanager'] = array(
			'company_code'         => get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_companycode' ),
			'property_shortnames'  => get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames' ),
			'number_of_properties' => rfs_get_number_of_properties( 'rentmanager' ),
		);
	}
	
	// get the Entrata integration settings.
	if ( in_array( 'entrata', $apis_enabled, true ) ) {
		$apis_used['entrata'] = array(
			'subdomain'             => get_option( 'rentfetch_options_entrata_integration_creds_entrata_subdomain' ),
			'property_codes'        => get_option( 'rentfetch_options_entrata_integration_creds_entrata_property_ids' ),
			'number_of_properties'  => rfs_get_number_of_properties( 'entrata' ),
			// we'd love to add the Brindle partner token here, but we get this from the RF API (so trying to do that creates a loop).
		);
	}
	
	// get the Manual integration settings. This won't be in the array, so we need to add it.
	$apis_used['manual'] = array(
		'number_of_properties' => rfs_get_number_of_properties( null ),
	);
	
	$site_url = get_site_url();
	
	// Clean up the site URL to just the domain name.
	$site_url = preg_replace('#^(https?://)?(www\.)?([^/]+).*$#', '$3', $site_url);

	$args = array(
		'site_url'              => $site_url,
		'site_name'             => get_bloginfo( 'name' ),
		'current_date_time'     => current_time( 'mysql' ),
		'rentfetch_version'     => defined( 'RENTFETCH_VERSION' ) ? RENTFETCH_VERSION : 'unknown',
		'rentfetchsync_version' => defined( 'RENTFETCHSYNC_VERSION' ) ? RENTFETCHSYNC_VERSION : 'unknown',
		'wordpress_version'     => get_bloginfo( 'version' ),
		'apis_used'             => $apis_used,
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
 * Grab just the Entrata API key from the Rentfetch API.
 *
 * @return  string the token.
 */
function rfs_get_entrata_api_key() {
	$response = rfs_get_info_from_rentfetch_api();

	if ( isset( $response['entrata']['api_key'] ) ) {
		$token = stripslashes( $response['entrata']['api_key'] );

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

/**
 * Get the number of properties for a given API.
 *
 * @param   string  $api  the name of the API.
 *
 * @return  int     the number of properties.
 */
function rfs_get_number_of_properties( $api ) {
	
	if ( $api ) {
		$args = array(
			'post_type'      => 'properties',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'     => 'property_source',
					'value'   => $api,
					'compare' => '=',
				),
			),
		);
	} else {
		// query for properties where the property source is not set.
		$args = array(
			'post_type'      => 'properties',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'     => 'property_source',
					'value'   => '',
					'compare' => 'NOT EXISTS',
				),
			),
		);
	}
	
	$properties = new WP_Query( $args );
	$number_of_properties = $properties->found_posts;
	
	return (int) $number_of_properties;
}