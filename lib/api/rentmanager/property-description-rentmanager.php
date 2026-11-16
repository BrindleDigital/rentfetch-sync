<?php
/** Property descriptions are optional enrichment and must never gate inventory sync. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function rfs_rentmanager_sync_property_description( $args ) {
	if ( rfs_is_sync_locked() || empty( $args['wordpress_property_post_id'] ) || empty( $args['rentmanager_property_id'] ) ) {
		return;
	}
	$response = rfs_rentfetch_api_post( 'rentmanager/property-description', array(
		'company_code' => $args['credentials']['rentmanager']['companycode'],
		'property_id' => $args['rentmanager_property_id'],
	), 90 );
	if ( rfs_is_sync_locked() ) { return; }

	$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	$body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	$valid = 200 === $status && is_array( $data )
		&& ( is_int( $data['PropertyID'] ?? null ) || is_string( $data['PropertyID'] ?? null ) )
		&& (string) $data['PropertyID'] === (string) $args['rentmanager_property_id']
		&& isset( $data['Description'] ) && is_string( $data['Description'] );

	$post_id = $args['wordpress_property_post_id'];
	$api = get_post_meta( $post_id, 'api_response', true );
	$api = is_array( $api ) ? $api : array();
	$api['property_description_api'] = array(
		'updated' => current_time( 'mysql' ),
		'status_code' => $status,
		'api_response' => $body,
	);
	update_post_meta( $post_id, 'api_response', wp_slash( $api ) );
	if ( $valid ) {
		// Slashing preserves literal backslashes through WordPress's meta API.
		update_post_meta( $post_id, 'description', wp_slash( wp_kses_post( $data['Description'] ) ) );
		rfs_mark_sync_succeeded( $post_id, 'property_description_api' );
	} elseif ( 404 === $status && in_array( $data['code'] ?? '', array( 'rest_no_route', 'rentmanager_property_not_listed' ), true ) ) {
		// An older API or unlisted property has no authoritative copy to import.
		rfs_mark_sync_partial( $post_id, 'property_description_api' );
	} else {
		rfs_mark_sync_failed( $post_id, 'property_description_api' );
	}
}
