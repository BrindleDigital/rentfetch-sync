<?php
/**
 * Realpage unit functions.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Update the unit meta from the $args and the $unit data.
 *
 * @param   array $args arguments, including the property ID and the credentials.
 * @param   array $unit the unit data that we pulled from the API.
 *
 * @return  void.
 */
function rfs_realpage_update_unit_meta( $args, $unit ) {

	// bail if we don't have the WordPress post ID.
	if ( ! isset( $args['wordpress_unit_post_id'] ) || ! $args['wordpress_unit_post_id'] ) {
		return;
	}

	// bail if we don't have the data to update this, updating the meta to give the error.
	if ( ! $args['unit_id'] ) {
		$unit_data_string = wp_json_encode( $unit );
		$success          = update_post_meta( $args['wordpress_unit_post_id'], 'updated', current_time( 'mysql' ) );
		$success          = update_post_meta( $args['wordpress_unit_post_id'], 'api_error', $unit_data_string );

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

	if ( ! isset( $args['property_id'] ) || ! $args['property_id'] ) {
		return;
	}

	if ( ! isset( $args['floorplan_id'] ) || ! $args['floorplan_id'] ) {
		return;
	}

	if ( ! isset( $args['unit_id'] ) || ! $args['unit_id'] ) {
		return;
	}

	$unit_id      = $args['unit_id'];
	$floorplan_id = $args['floorplan_id'];
	$property_id  = $args['property_id'];
	$integration  = $args['integration'];

	// * Update the title
	$post_info = array(
		'ID'         => $args['wordpress_unit_post_id'],
		'post_title' => $unit_id,
		'post_name'  => sanitize_title( $unit_id ), // update the permalink to match the new title.
	);

	wp_update_post( $post_info );

	$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );

	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	$api_response['units_api'] = array(
		'updated'      => current_time( 'mysql' ),
		'api_response' => 'Updated successfully',
	);

	// ! Option 1
	// get the default max and min rents. This is a single number in the API which is not very accurate, but it's available for every Unit.
	// e.g. <BaseRentAmount xmlns="">1682.3000</BaseRentAmount>.
	$maximum_rent_base_rent = floatval( $unit['BaseRentAmount'] );
	$minimum_rent_base_rent = floatval( $unit['BaseRentAmount'] );

	// ! Option 2
	// get the best min and max for today. These are in a common location, and reflect the best price for today **of those that are in the API**.
	// These are always in a <row> element just inside the Rentmatrix:
	// <row Building="N/A" LeaseEndDate="" LeaseStartDate="" MakeReadyDate="2/9/2024" MaxRent="2013" MinRent="1730" Rent="1792" Unit="838">.
	if ( isset( $unit['rentMatrix']['row']['@attributes']['MaxRent'] ) ) {
		$maximum_rent_best_overall = $unit['rentMatrix']['row']['@attributes']['MaxRent'];
	}

	if ( isset( $unit['rentMatrix']['row']['@attributes']['MinRent'] ) ) {
		$minimum_rent_best_overall = $unit['rentMatrix']['row']['@attributes']['MinRent'];
	}

	// ! Option 3
	// get the best min and max for tomorrow. This is what shows up on RealPage sites, but ours will only match if the API is showing the same number of months
	// that are being shown on the RealPage site. This can only be obtained by looking through the values.
	if ( isset( $unit['rentMatrix']['row']['options'][1]['option'] ) ) {

		$rent_best_tomorrow_array = array();

		$rent_best_tomorrows = $unit['rentMatrix']['row']['options'][1]['option'];
		foreach ( $rent_best_tomorrows as $rent_best_tomorrow ) {
			$rent_best_tomorrow_array[] = $rent_best_tomorrow['@attributes']['Rent'];
		}

		$minimum_rent_best_tomorrow = min( $rent_best_tomorrow_array );
		$maximum_rent_best_tomorrow = max( $rent_best_tomorrow_array );

	}

	// * Set the maximum rent to update.
	if ( isset( $maximum_rent_best_tomorrow ) ) {
		$maximum_rent_to_update = $maximum_rent_best_tomorrow;
	} elseif ( isset( $maximum_rent_best_overall ) ) {
		$maximum_rent_to_update = $maximum_rent_best_overall;
	} else {
		$maximum_rent_to_update = $maximum_rent_base_rent;
	}

	// * Set the minimum rent to update (our default is tomorrow, because that's what the client is generally comparing against).
	if ( isset( $minimum_rent_best_tomorrow ) ) {
		$minimum_rent_to_update = $minimum_rent_best_tomorrow;
	} elseif ( isset( $minimum_rent_best_overall ) ) {
		$minimum_rent_to_update = $minimum_rent_best_overall;
	} else {
		$minimum_rent_to_update = $minimum_rent_base_rent;
	}

	$meta = array(
		'availability_date' => esc_html( $unit['Availability']['AvailableDate'] ),
		'available'         => esc_html( $unit['Availability']['AvailableBit'] ),
		'updated'           => current_time( 'mysql' ),
		'beds'              => floatval( $unit['UnitDetails']['Bedrooms'] ),
		'baths'             => floatval( $unit['UnitDetails']['Bathrooms'] ),
		'maximum_rent'      => floatval( $maximum_rent_to_update ),
		'minimum_rent'      => floatval( $minimum_rent_to_update ),
		'deposit'           => floatval( $unit['DepositAmount'] ),
		'sqrft'             => floatval( $unit['UnitDetails']['RentSqFtCount'] ),
		'api_response'      => $api_response,
		'unit_source'       => 'realpage',
	);

	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_unit_post_id'], $key, $value );
	}
}

/**
 * Look through the units in WordPress and remove any that are in WordPress showing an API connection to 'realpage' but aren't in the list of units.
 *
 * @param array $units_data the units data that we pulled from the API.
 * 
 * @return void.
 */
function rfs_realpage_remove_units_not_in_api( $args, $units_data ) {

	// for each unit in the units data, extract the UnitNumber, and add it to an array.
	$unit_numbers = array();

	foreach ( $units_data as $unit ) {
		$unit_numbers[] = $unit['Address']['UnitNumber'];
	}

	// get all of the units in WordPress have a 'unit_id'' that is NOT one of the unit numbers.
	$units_to_delete = get_posts(
		array(
			'post_type'      => 'units',
			'posts_per_page' => -1,
			'meta_query'     => array( // phpcs:ignore
				array(
					'key'     => 'unit_id',
					'compare' => 'NOT IN',
					'value'   => $unit_numbers,
				),
				array(
					'key'     => 'unit_source',
					'value'   => 'realpage',
					'compare' => '=',
				),
				array(
					'key'     => 'property_id',
					'value'   => $args['property_id'],
					'compare' => '=',
				
				),
			),
		)
	);
	
	// bail if there aren't any units to delete
	if ( ! $units_to_delete ) {
		return;
	}

	// delete each of the units.
	foreach ( $units_to_delete as $unit_to_delete ) {
		wp_delete_post( $unit_to_delete->ID, true );
	}
}
