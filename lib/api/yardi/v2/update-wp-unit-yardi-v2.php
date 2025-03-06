<?php
/**
 * Functions to update unit data in WordPress from the Yardi API (v2)
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Update the unit meta
 *
 * @param   array $args       the current $args in the sync process.
 * @param   array $unit_data  the unit data from the API.
 *
 * @return  void.
 */
function rfs_yardi_v2_update_unit_meta( $args, $unit_data ) {

	// bail if we don't have the WordPress post ID.
	if ( ! isset( $args['wordpress_unit_post_id'] ) || ! $args['wordpress_unit_post_id'] ) {
		return;
	}

	$apartmentID = $unit_data['apartmentId']; // phpcs:ignore.

	// bail if we don't have the data to update this, updating the meta to give the error.
	if ( ! $unit_data['apartmentId'] ) {

		$unit_data_string = wp_json_encode( $unit_data );

		$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );

		if ( ! is_array( $api_response ) ) {
			$api_response = array();
		}

		$api_response['units_api'] = array(
			'updated'      => current_time( 'mysql' ),
			'api_response' => $unit_data_string,
		);

		$success = update_post_meta( $args['wordpress_unit_post_id'], 'api_response', $api_response );

		return;
	}

	// * Update the title
	$post_info = array(
		'ID'         => (int) $args['wordpress_unit_post_id'],
		'post_title' => esc_html( $unit_data['apartmentName'] ),
		'post_name'  => sanitize_title( $unit_data['apartmentName'] ), // update the permalink to match the new title.
	);

	wp_update_post( $post_info );

	$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );

	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	$api_response['units_api'] = array(
		'updated'      => current_time( 'mysql' ),
		'api_response' => wp_json_encode( $unit_data ),
	);

	$meta = array(
		'unit_id'           => $unit_data['apartmentId'],
		'floorplan_id'      => $unit_data['floorplanId'],
		'property_id'       => $args['property_id'],
		'apply_online_url'  => $unit_data['applyOnlineURL'],
		'availability_date' => $unit_data['availableDate'],
		'baths'             => $unit_data['baths'],
		'beds'              => $unit_data['beds'],
		'deposit'           => $unit_data['deposit'],
		'minimum_rent'      => $unit_data['minimumRent'],
		'maximum_rent'      => $unit_data['maximumRent'],
		'sqrft'             => $unit_data['sqft'],
		'amenities'         => $unit_data['amenities'],
		'specials'          => $unit_data['specials'],
		'unit_source'       => 'yardi',
		'api_response'      => $api_response,
	);

	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_unit_post_id'], $key, $value );
	}
}

/**
 * Remove any units from our database that are no longer in the Yardi API.
 *
 * @param   array $unit_data_v2  the units data from the API.
 * @param   array $args          the current $args in the sync process.
 *
 * @return  void.
 */
function rfs_yardi_v2_remove_orphan_units( $unit_data_v2, $args ) {

	// bail if we don't have $args['property_id'].
	if ( ! isset( $args['property_id'] ) || ! $args['property_id'] ) {
		return;
	}

	$property_id = $args['property_id'];

	// get an array of the unit IDs (apartmentId) from the API.
	$correct_unit_ids_from_api = wp_list_pluck( $unit_data_v2, 'apartmentId' );

	// do a query where we get the WordPress post IDs for our corresponding units in the database.
	$correct_units_in_db = get_posts(
		array(
			'post_type'      => 'units',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore
				'relation' => 'AND',
				array(
					'key'     => 'unit_id',
					'value'   => $correct_unit_ids_from_api,
					'compare' => 'IN',
				),
				array(
					'key'     => 'unit_source',
					'value'   => 'yardi',
					'compare' => '=',
				),
			),
		)
	);

	// let's query for all of the units for this property.
	$incorrect_units_in_db = get_posts(
		array(
			'post_type'      => 'units',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post__not_in'   => $correct_units_in_db,
			'meta_query'     => array( // phpcs:ignore.
				'relation' => 'AND',
				array(
					'key'     => 'property_id',
					'value'   => $args['property_id'],
					'compare' => '=',
				),
				array(
					'key'     => 'unit_source',
					'value'   => 'yardi',
					'compare' => '=',
				),
			),
		)
	);

	// delete each of the units that are no longer in the API.
	foreach ( $incorrect_units_in_db as $unit_wordpress_id ) {
		wp_delete_post( $unit_wordpress_id, true );
	}
}
