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

	// If unit_data is a string (cleaned JSON from decode failure), save it directly
	if ( is_string( $unit_data ) ) {
		$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );
		
		if ( ! is_array( $api_response ) ) {
			$api_response = array();
		}
	
		$api_response['apartmentavailability_api'] = array(
			'updated'      => current_time( 'mysql' ),
			'api_response' => $unit_data,
		);
		
		$success = update_post_meta( $args['wordpress_unit_post_id'], 'api_response', $api_response );
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

		$api_response['apartmentavailability_api'] = array(
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

	$api_response['apartmentavailability_api'] = array(
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
 * Delete all units for this property if we get a 204 response from the API.
 *
 * @param   array $args          the current $args in the sync process.
 * @param   array $unit_data_v2  the units data from the API.
 *
 * @return  void.
 */
function rfs_delete_orphan_units_if_property_204_response( $args, $unit_data_v2 ) {
	
	// Bail if we don't have the property ID.
	if ( ! isset( $args['property_id'] ) || ! $args['property_id'] ) {
		return;
	}

	$property_id = $args['property_id'];

	// Get all units for this property with unit_source 'yardi'.
	$units_to_delete = get_posts(
		array(
			'post_type'      => 'units',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => 'property_id',
					'value'   => $property_id,
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

	// Delete each of the units.
	foreach ( $units_to_delete as $unit_wordpress_id ) {
		wp_delete_post( $unit_wordpress_id, true );
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
function rfs_yardi_v2_check_each_unit_and_delete_if_not_still_in_api( $unit_data_v2, $args ) {

	// bail if we don't have the property ID.
	if ( ! isset( $args['property_id'] ) || ! $args['property_id'] ) {
		return;
	}
	
	// first, let's get all units in our database that belong to this vendor and have unit_source 'yardi', which are attached to this property.
	$all_units_in_db_for_property = get_posts(
		array(
			'post_type'      => 'units',
			'posts_per_page' => -1,
			'fields'         => 'ids',
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
	
	// loop through each unit in the database, then check the $units_data_v2 to see if there's a corresponding unit with the same apartmentId, propertyId, and floorplanId.
	foreach ( $all_units_in_db_for_property as $unit_wordpress_id ) {
		$unit_id_in_db = (string) get_post_meta( $unit_wordpress_id, 'unit_id', true );
		$floorplan_id_in_db = (string) get_post_meta( $unit_wordpress_id, 'floorplan_id', true );
		
		$found = false;
		
		foreach ( $unit_data_v2 as $unit_from_api ) {
			if ( isset( $unit_from_api['apartmentId'] ) && isset( $unit_from_api['floorplanId'] ) ) {
				if ( (string) $unit_from_api['apartmentId'] === $unit_id_in_db && (string) $unit_from_api['floorplanId'] === $floorplan_id_in_db ) {
					$found = true;
					break;
				}
			}
		}
		
		// if not found, delete the unit from the database.
		if ( ! $found ) {
			wp_delete_post( $unit_wordpress_id, true );
		}
	}
}