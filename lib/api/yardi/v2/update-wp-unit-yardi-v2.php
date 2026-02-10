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
		
		// unset the units_api from $api_response to avoid bloating the meta (this is just a rename)
		if ( isset( $api_response['units_api'] ) ) {
			unset( $api_response['units_api'] );
		}
	
		$api_response['apartmentavailability_api'] = array(
			'updated'      => current_time( 'mysql' ),
			'api_response' => $unit_data,
		);
		
		// Clear any previous 304 response since we have new data
		if ( isset( $api_response['apartmentavailability_api_304'] ) ) {
			unset( $api_response['apartmentavailability_api_304'] );
		}
		
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
		
		// unset the units_api from $api_response to avoid bloating the meta (this is just a rename)
		if ( isset( $api_response['units_api'] ) ) {
			unset( $api_response['units_api'] );
		}

		$api_response['apartmentavailability_api'] = array(
			'updated'      => current_time( 'mysql' ),
			'api_response' => $unit_data_string,
		);

		// Clear any previous 304 response since we have new data
		if ( isset( $api_response['apartmentavailability_api_304'] ) ) {
			unset( $api_response['apartmentavailability_api_304'] );
		}

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
	
	// Clear any previous 304 response since we have new data
	if ( isset( $api_response['apartmentavailability_api_304'] ) ) {
		unset( $api_response['apartmentavailability_api_304'] );
	}
	
	// unset the units_api from $api_response to avoid bloating the meta (this is just a rename)
	if ( isset( $api_response['units_api'] ) ) {
		unset( $api_response['units_api'] );
	}

	$sanitize_mixed = static function( $value ) use ( &$sanitize_mixed ) {
		if ( is_array( $value ) ) {
			return array_map( $sanitize_mixed, $value );
		}

		if ( is_scalar( $value ) || null === $value ) {
			return sanitize_text_field( (string) $value );
		}

		return '';
	};

	$meta = array(
		'unit_id'           => sanitize_text_field( $unit_data['apartmentId'] ?? '' ),
		'floorplan_id'      => sanitize_text_field( $unit_data['floorplanId'] ?? '' ),
		'property_id'       => sanitize_text_field( $args['property_id'] ?? '' ),
		'apply_online_url'  => esc_url_raw( $unit_data['applyOnlineURL'] ?? '' ),
		'availability_date' => sanitize_text_field( $unit_data['availableDate'] ?? '' ),
		'baths'             => floatval( $unit_data['baths'] ?? 0 ),
		'beds'              => floatval( $unit_data['beds'] ?? 0 ),
		'deposit'           => floatval( $unit_data['deposit'] ?? 0 ),
		'minimum_rent'      => floatval( $unit_data['minimumRent'] ?? 0 ),
		'maximum_rent'      => floatval( $unit_data['maximumRent'] ?? 0 ),
		'sqrft'             => floatval( $unit_data['sqft'] ?? 0 ),
		'amenities'         => isset( $unit_data['amenities'] ) ? $sanitize_mixed( $unit_data['amenities'] ) : '',
		'specials'          => isset( $unit_data['specials'] ) ? $sanitize_mixed( $unit_data['specials'] ) : '',
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

/**
 * Handle 304 responses for units by updating their api_response meta.
 *
 * @param   array $args  The current $args in the sync process.
 *
 * @return  void.
 */
function rfs_yardi_v2_handle_304_response_for_units( $args ) {
	
	// Bail if we don't have the property ID.
	if ( ! isset( $args['property_id'] ) || ! $args['property_id'] ) {
		return;
	}

	// Get all units for this property with unit_source 'yardi'.
	$units = get_posts(
		array(
			'post_type'      => 'units',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
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
	
	// Update each unit's api_response for 304.
	foreach ( $units as $unit_id ) {
		$api_response = get_post_meta( $unit_id, 'api_response', true );
		
		if ( ! is_array( $api_response ) ) {
			$api_response = array();
		}
		
		// unset the units_api from $api_response to avoid bloating the meta (this is just a rename)
		if ( isset( $api_response['units_api'] ) ) {
			unset( $api_response['units_api'] );
		}
	
		// Initialize the 304 array if it doesn't exist
		if ( ! isset( $api_response['apartmentavailability_api_304'] ) ) {
			$api_response['apartmentavailability_api_304'] = array();
		}
		
		// Append the new 304 response
		$api_response['apartmentavailability_api_304'][] = array(
			'updated' => current_time( 'mysql' ),
			'status'  => '304 - no change',
		);
		
		// Ensure apartmentavailability_api_304 comes before apartmentavailability_api in the array
		if ( isset( $api_response['apartmentavailability_api_304'] ) && isset( $api_response['apartmentavailability_api'] ) ) {
			$temp_304 = $api_response['apartmentavailability_api_304'];
			unset( $api_response['apartmentavailability_api_304'] );
			$api_response = array_merge( array( 'apartmentavailability_api_304' => $temp_304 ), $api_response );
		}
		
		update_post_meta( $unit_id, 'api_response', $api_response );
	}
}
