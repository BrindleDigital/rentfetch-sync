<?php
/** RentEGI configuration and full-feed transport through the Rent Fetch API. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rfs_rentegi_get_property_codes() {
	// Another worker may add a property while cleanup waits for its execution lock.
	return rfs_parse_property_identifiers( rfs_get_fresh_option( 'rentfetch_options_rentegi_property_codes', '' ) );
}

function rfs_rentegi_get_feed( $property_code ) {
	$response = rfs_rentfetch_api_post( 'rentegi/feed', array( 'property_code' => $property_code ), 190 );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	$data   = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== $status || ! is_array( $data ) || ! isset( $data['xml'] ) || ! is_string( $data['xml'] ) ) {
		return new WP_Error(
			'rentegi_feed_failed',
			is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] )
				? sanitize_text_field( $data['message'] )
				: 'Rent Fetch API did not return a valid RentEGI feed.',
			array( 'status' => $data['data']['provider_status'] ?? $status )
		);
	}
	return $data['xml'];
}
