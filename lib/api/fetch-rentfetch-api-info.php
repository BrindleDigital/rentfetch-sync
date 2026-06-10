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
 * @param bool $reset_request_cache Whether to clear the in-request cache.
 * @return  array|string the response.
 */
function rfs_get_info_from_rentfetch_api( $reset_request_cache = false ) {
	static $request_cache = null;

	if ( $reset_request_cache ) {
		$request_cache = null;
		return null;
	}

	if ( null !== $request_cache ) {
		return $request_cache;
	}

	$apis_enabled = rfs_get_enabled_rentfetch_api_integrations();
	$transient    = rfs_get_valid_rentfetch_api_info_transient( $apis_enabled );

	if ( is_array( $transient ) ) {
		$request_cache = $transient;
		return $request_cache;
	}

	$lock_acquired = rfs_acquire_rentfetch_api_info_lock();
	if ( ! $lock_acquired ) {
		$transient = rfs_wait_for_rentfetch_api_info_refresh( $apis_enabled );
		if ( is_array( $transient ) ) {
			$request_cache = $transient;
			return $request_cache;
		}

		$last_success = rfs_get_valid_rentfetch_api_info_last_success( $apis_enabled );
		if ( is_array( $last_success ) ) {
			$request_cache = $last_success;
			return $request_cache;
		}

		return 'Something went wrong: Rent Fetch API info refresh already in progress.';
	}

	try {
		$request_cache = rfs_refresh_info_from_rentfetch_api();
		if ( ! is_array( $request_cache ) ) {
			$last_success = rfs_get_valid_rentfetch_api_info_last_success( $apis_enabled );
			if ( is_array( $last_success ) ) {
				$request_cache = $last_success;
			}
		}
		return $request_cache;
	} finally {
		delete_option( 'rentfetch_api_info_refresh_lock' );
	}
}

/**
 * Get the enabled integrations as a normalized array.
 *
 * @return array
 */
function rfs_get_enabled_rentfetch_api_integrations() {
	$apis_enabled = get_option( 'rentfetch_options_enabled_integrations' );

	if ( ! is_array( $apis_enabled ) ) {
		return array();
	}

	return $apis_enabled;
}

/**
 * Clear cached Rent Fetch API bootstrap state.
 *
 * @return void
 */
function rfs_clear_rentfetch_api_info_cache() {
	rfs_get_info_from_rentfetch_api( true );
	delete_transient( 'rentfetch_api_info' );
	delete_transient( 'rentfetch_api_info_error' );
	delete_option( 'rentfetch_api_info_refresh_lock' );
}

/**
 * Acquire the lock used to prevent Rent Fetch API info cache stampedes.
 *
 * @return bool
 */
function rfs_acquire_rentfetch_api_info_lock() {
	$lock_key = 'rentfetch_api_info_refresh_lock';
	$now      = time();
	$lock_age = $now - (int) get_option( $lock_key, 0 );

	if ( $lock_age > 120 ) {
		delete_option( $lock_key );
	}

	return add_option( $lock_key, $now, '', 'no' );
}

/**
 * Determine whether the stored API-info transient has the timeout row this
 * plugin expects when WordPress is using database-backed transients.
 *
 * @return bool
 */
function rfs_rentfetch_api_info_transient_has_timeout() {
	if ( wp_using_ext_object_cache() ) {
		return true;
	}

	return false !== get_option( '_transient_timeout_rentfetch_api_info', false );
}

/**
 * Get a cached API-info transient only when it is structurally valid and fresh.
 *
 * @param array $apis_enabled Enabled integration slugs.
 * @return array|false
 */
function rfs_get_valid_rentfetch_api_info_transient( $apis_enabled ) {
	if (
		! wp_using_ext_object_cache()
		&& false !== get_option( '_transient_rentfetch_api_info', false )
		&& ! rfs_rentfetch_api_info_transient_has_timeout()
	) {
		delete_transient( 'rentfetch_api_info' );
		return false;
	}

	// Error responses are stored separately so token consumers never receive a
	// cached error string in place of credentials.
	$transient = get_transient( 'rentfetch_api_info' );

	if ( is_array( $transient ) ) {
		$validation_error = rfs_validate_rentfetch_api_info_response( $transient, $apis_enabled );

		if ( '' === $validation_error ) {
			return $transient;
		}

		delete_transient( 'rentfetch_api_info' );
		return false;
	} elseif ( false !== $transient ) {
		delete_transient( 'rentfetch_api_info' );
	}

	return false;
}

/**
 * Get the last successful API-info payload only when it is still valid.
 *
 * @param array $apis_enabled Enabled integration slugs.
 * @return array|false
 */
function rfs_get_valid_rentfetch_api_info_last_success( $apis_enabled ) {
	$last_success = get_option( 'rentfetch_api_info_last_success', false );

	if ( ! is_array( $last_success ) ) {
		if ( false !== $last_success ) {
			delete_option( 'rentfetch_api_info_last_success' );
		}

		return false;
	}

	if ( '' === rfs_validate_rentfetch_api_info_response( $last_success, $apis_enabled ) ) {
		return $last_success;
	}

	delete_option( 'rentfetch_api_info_last_success' );
	return false;
}

/**
 * Wait briefly for another process to refresh the API info transient.
 *
 * @param array $apis_enabled Enabled integration slugs.
 * @return mixed
 */
function rfs_wait_for_rentfetch_api_info_refresh( $apis_enabled = array() ) {
	for ( $attempt = 0; $attempt < 5; ++$attempt ) {
		usleep( 200000 );
		$transient = rfs_get_valid_rentfetch_api_info_transient( $apis_enabled );

		if ( is_array( $transient ) ) {
			return $transient;
		}
	}

	return false;
}

/**
 * Refresh the information from the Rentfetch API.
 *
 * @return array|string
 */
function rfs_refresh_info_from_rentfetch_api() {
		
	// Let's build the array piece by piece.
	$apis_used = array();
	
	$apis_enabled = rfs_get_enabled_rentfetch_api_integrations();
	
	// get the Yardi integration settings.
	if ( in_array( 'yardi', $apis_enabled, true ) ) {
		$apis_used['yardi'] = array(
			'api_token'            => get_option( 'rentfetch_options_yardi_integration_creds_yardi_api_key' ),
			'company_code'         => get_option( 'rentfetch_options_yardi_integration_creds_yardi_company_code' ),
			'property_codes'       => get_option( 'rentfetch_options_yardi_integration_creds_yardi_property_code' ),
			'number_of_properties' => rfs_get_number_of_properties( 'yardi' ),
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
	
	$site_origin = untrailingslashit( get_site_url() );
	$site_url    = preg_replace('#^(https?://)?(www\.)?([^/]+).*$#', '$3', $site_origin);

	$args = array(
		'site_url'              => $site_url,
		'site_origin'           => $site_origin,
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
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		// When WordPress returns an error.

		$error_message = $response->get_error_message();

		set_transient( 'rentfetch_api_info_error', $error_message, 5 * MINUTE_IN_SECONDS );

		return "Something went wrong: $error_message";
	} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		// When the response code is not 200.

		$error_message = wp_remote_retrieve_response_message( $response );

		set_transient( 'rentfetch_api_info_error', $error_message, 5 * MINUTE_IN_SECONDS );

		return "Something went wrong: $error_message";

	} else {
		// When the response code is 200.

		$body               = wp_remote_retrieve_body( $response ); // Retrieve response body.
		$body = rentfetch_clean_json_string( $body );
		$response_php_array = json_decode( $body, true );

		if ( ! is_array( $response_php_array ) ) {
			set_transient( 'rentfetch_api_info_error', 'Invalid JSON response from Rent Fetch API.', 5 * MINUTE_IN_SECONDS );
			return 'Something went wrong: Invalid JSON response from Rent Fetch API.';
		}

		$validation_error = rfs_validate_rentfetch_api_info_response( $response_php_array, $apis_enabled );
		if ( '' !== $validation_error ) {
			set_transient( 'rentfetch_api_info_error', $validation_error, 5 * MINUTE_IN_SECONDS );
			return "Something went wrong: $validation_error";
		}

		rfs_store_monitoring_bootstrap_data( $response_php_array );

		// cache the response for 20 minutes and keep the last success as a fallback during refresh contention.
		set_transient( 'rentfetch_api_info', $response_php_array, 20 * MINUTE_IN_SECONDS );
		update_option( 'rentfetch_api_info_last_success', $response_php_array, false );
		delete_transient( 'rentfetch_api_info_error' );

		return $response_php_array;
	}
}

/**
 * Validate the central API bootstrap response before caching it as successful.
 *
 * @param array $response_php_array Decoded Rent Fetch API response.
 * @param array $apis_enabled Enabled integration slugs.
 * @return string Empty string when valid, otherwise a readable error.
 */
function rfs_validate_rentfetch_api_info_response( $response_php_array, $apis_enabled ) {
	if ( ! is_array( $response_php_array ) ) {
		return 'Invalid Rent Fetch API response.';
	}

	if ( ! is_array( $apis_enabled ) ) {
		$apis_enabled = array();
	}

	$required_paths = array(
		'yardi'       => array( 'yardi', 'access_token' ),
		'entrata'     => array( 'entrata', 'api_key' ),
		'rentmanager' => array( 'rentmanager', 'partner_token' ),
	);

	foreach ( $required_paths as $integration => $path ) {
		if ( ! in_array( $integration, $apis_enabled, true ) ) {
			continue;
		}

		$value = $response_php_array;
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return sprintf( 'Rent Fetch API response missing %s credentials.', $integration );
			}

			$value = $value[ $key ];
		}

		if ( '' === trim( (string) $value ) ) {
			return sprintf( 'Rent Fetch API response returned empty %s credentials.', $integration );
		}

		if ( 'yardi' === $integration ) {
			$expires_at = rfs_get_jwt_expires_at( (string) $value );

			if ( ! $expires_at ) {
				return 'Rent Fetch API response returned an unreadable Yardi bearer token.';
			}

			if ( $expires_at <= time() + ( 5 * MINUTE_IN_SECONDS ) ) {
				return 'Rent Fetch API response returned an expired Yardi bearer token.';
			}
		}
	}

	return '';
}

/**
 * Read the expiration timestamp from a JWT without verifying the signature.
 *
 * The signature is still verified by the API provider when the token is used;
 * this only prevents obviously stale cached bearer tokens from being reused.
 *
 * @param string $token JWT string.
 * @return int|null
 */
function rfs_get_jwt_expires_at( $token ) {
	$parts = explode( '.', trim( $token ) );

	if ( count( $parts ) < 2 ) {
		return null;
	}

	$payload = strtr( $parts[1], '-_', '+/' );
	$padding = strlen( $payload ) % 4;

	if ( $padding ) {
		$payload .= str_repeat( '=', 4 - $padding );
	}

	$decoded = base64_decode( $payload, true );

	if ( false === $decoded ) {
		return null;
	}

	$payload_data = json_decode( $decoded, true );

	if ( ! is_array( $payload_data ) || ! isset( $payload_data['exp'] ) || ! is_numeric( $payload_data['exp'] ) ) {
		return null;
	}

	return (int) $payload_data['exp'];
}

/**
 * Persist monitoring bootstrap data returned by the Rent Fetch API.
 *
 * @param mixed $response_php_array The decoded API response.
 * @return void
 */
function rfs_store_monitoring_bootstrap_data( $response_php_array ) {
	if ( ! is_array( $response_php_array ) || ! isset( $response_php_array['monitoring'] ) || ! is_array( $response_php_array['monitoring'] ) ) {
		return;
	}

	$monitoring = $response_php_array['monitoring'];
	$key_id     = isset( $monitoring['key_id'] ) ? sanitize_text_field( (string) $monitoring['key_id'] ) : '';
	$public_key = isset( $monitoring['public_key'] ) ? trim( (string) $monitoring['public_key'] ) : '';

	if ( '' === $key_id || '' === $public_key ) {
		return;
	}

	$public_keys = get_option( 'rentfetch_monitoring_public_keys', array() );
	if ( ! is_array( $public_keys ) ) {
		$public_keys = array();
	}

	$public_keys[ $key_id ] = $public_key;

	update_option( 'rentfetch_monitoring_public_keys', $public_keys, false );
	update_option( 'rentfetch_monitoring_active_key_id', $key_id, false );
}

/**
 * Grab just the Yardi bearer token from the Rentfetch API.
 *
 * @return  string the token.
 */
function rfs_get_yardi_bearer_token() {
	$response = rfs_get_info_from_rentfetch_api();

	if ( is_array( $response ) && isset( $response['yardi']['access_token'] ) ) {
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

	if ( is_array( $response ) && isset( $response['entrata']['api_key'] ) ) {
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

	if ( is_array( $response ) && isset( $response['rentmanager']['partner_token'] ) ) {
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
