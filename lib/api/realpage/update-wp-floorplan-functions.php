<?php

/**
 * Update an individual Realpage floorplan
 *
 * @param  array $args                the arguments passed to the function.
 * @param  array $floorplan_data      the floorplan data.
 */
function rfs_realpage_update_floorplan_meta( $args, $floorplan_data ) {

	// bail if we don't have the WordPress post ID.
	if ( ! isset( $args['wordpress_floorplan_post_id'] ) || ! $args['wordpress_floorplan_post_id'] ) {
		return;
	}

	// bail if we don't have the data to update this, updating the meta to give the error.
	if ( ! $floorplan_data['FloorPlanID'] ) {
		$floorplan_data_string = wp_json_encode( $floorplan_data );

		$api_response = get_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', true );

		if ( ! is_array( $api_response ) ) {
			$api_response = array();
		}

		$api_response['floorplans_api'] = array(
			'updated'      => current_time( 'mysql' ),
			'api_response' => $floorplan_data_string,
		);

		$success = update_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', $api_response );

		return;
	}

	$property_id  = $args['property_id'];
	$floorplan_id = $args['floorplan_id'];
	$integration  = $args['integration'];

	// * Update the title
	$post_info = array(
		'ID'         => $args['wordpress_floorplan_post_id'],
		'post_title' => $floorplan_data['FloorPlanNameMarketing'],
		'post_name'  => sanitize_title( $floorplan_data['FloorPlanNameMarketing'] ), // update the permalink to match the new title.
	);

	wp_update_post( $post_info );

	$api_response = get_post_meta( $args['wordpress_floorplan_post_id'], 'api_response', true );

	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	$api_response['floorplans_api'] = array(
		'updated'      => current_time( 'mysql' ),
		'api_response' => 'Updated successfully',
	);

	// * Update the meta
	$meta = array(
		'baths'           => floatval( $floorplan_data['Bathrooms'] ),
		'beds'            => floatval( $floorplan_data['Bedrooms'] ),
		'minimum_rent'    => floatval( $floorplan_data['RentMin'] ),
		'maximum_rent'    => floatval( $floorplan_data['RentMax'] ),
		'maximum_sqft'    => floatval( $floorplan_data['GrossSquareFootage'] ),
		'minimum_sqft'    => floatval( $floorplan_data['RentableSquareFootage'] ),
		'available_units' => 0,
		'updated'         => current_time( 'mysql' ),
		'api_error'       => 'Updated successfully',
		'api_response'    => $api_response,
	);

	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], $key, $value );
	}
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
 * Update the floorplan availability from the units we just updated
 *
 * @param   array $args the arguments, including the property ID and the credentials.
 * @param   array $units_data the units data that we pulled from the API.
 *
 * @return  void.
 */
function rfs_realpage_update_floorplan_availability_from_units( $args, $units_data ) {

	// we need to loop through the array and get (for each floorplan) the number of available units and the availability date.
	// then we need to update the floorplan post with that information.

	// set the date and units to be an array.
	$floorplan_data = array();

	foreach ( $units_data as $unit ) {

		if ( 'true' === $unit['Availability']['AvailableBit'] ) {
			$floorplan_id = $unit['FloorPlan']['FloorPlanID'];

			// check if $floorplan_data[ $floorplan_id ]['available_units'][] is already set. If it is, just add 1. If not, set it to 1.
			if ( ! isset( $floorplan_data[ $floorplan_id ]['available_units'] ) ) {
				$floorplan_data[ $floorplan_id ]['available_units'] = 1;
			} else {
				++$floorplan_data[ $floorplan_id ]['available_units'];
			}

			// check if $floorplan_data[ $floorplan_id ]['availability_date'][] is already set. If it is, compare the dates and set it to the value of the one that's earlier. if it's not set, just set it to the date.
			if ( ! isset( $floorplan_data[ $floorplan_id ]['availability_date'] ) ) {
				$floorplan_data[ $floorplan_id ]['availability_date'] = $unit['Availability']['AvailableDate'];
			} else {
				$floorplan_data[ $floorplan_id ]['availability_date'] = min( $floorplan_data[ $floorplan_id ]['availability_date'], $unit['Availability']['AvailableDate'] );
			}

			// if $floorplan_data[ $floorplan_id ]['availability_date'] is in the past, set it to today instead.
			if ( strtotime( $floorplan_data[ $floorplan_id ]['availability_date'] ) < strtotime( 'today' ) ) {
				$floorplan_data[ $floorplan_id ]['availability_date'] = gmdate( 'Y-m-d' );
			}
		}
	}

	// let's first set the availability and date of all of the floorplans for this property to have null availability and date.
	$floorplans_args = array(
		'post_type'      => 'floorplans',
		'posts_per_page' => -1,
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'   => 'floorplan_source',
				'value' => 'realpage',
			),
			array(
				'key'   => 'property_id',
				'value' => $args['property_id'],
			),
		),
	);

	$floorplans_query = new WP_Query( $floorplans_args );

	if ( $floorplans_query->have_posts() ) {

		while ( $floorplans_query->have_posts() ) :
			$floorplans_query->the_post();

			// get the floorplan ID.
			$floorplan_id = get_post_meta( get_the_ID(), 'floorplan_id', true );

			// look at the array of floorplan data and see if there's a match.
			if ( isset( $floorplan_data[ $floorplan_id ] ) ) {
				$available_units   = $floorplan_data[ $floorplan_id ]['available_units'];
				$availability_date = $floorplan_data[ $floorplan_id ]['availability_date'];

				// if there is, update the post meta with the availability and date.
				$success = update_post_meta( get_the_ID(), 'available_units', $available_units );
				$success = update_post_meta( get_the_ID(), 'availability_date', $availability_date );

			} else {

				// if there isn't, set the availability and date to null.
				$success = update_post_meta( get_the_ID(), 'available_units', 0 );
				$success = update_post_meta( get_the_ID(), 'availability_date', null );

			}

		endwhile;

		wp_reset_postdata();

	}
}

/**
 * Update the floorplan pricing based on the unit pricing we just updated.
 *
 * @param   array $args the $args including the property ID, the floorplan ID, and the credentials.
 *
 * @return  void.
 */
function rfs_realpage_update_floorplan_pricing_from_units( $args ) {

	$property_id = $args['property_id'];

	// loop through all of the floorplans for this property.
	$floorplans_args = array(
		'post_type'      => 'floorplans',
		'posts_per_page' => -1,
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'   => 'floorplan_source',
				'value' => 'realpage',
			),
			array(
				'key'   => 'property_id',
				'value' => $property_id,
			),
		),
	);

	$floorplans_query = new WP_Query( $floorplans_args );

	// loop through each.
	if ( $floorplans_query->have_posts() ) {

		while ( $floorplans_query->have_posts() ) :
			$floorplans_query->the_post();

			// get the floorplan ID.
			$floorplan_id = get_post_meta( get_the_ID(), 'floorplan_id', true );

			// loop through the units and pull the min and max numbers for rent from those.
			$args = array(
				'post_type'      => 'units',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => 'floorplan_id',
						'value' => $floorplan_id,
					),
					array(
						'key'   => 'property_id',
						'value' => $property_id,
					),
					array(
						'key'   => 'unit_source',
						'value' => 'realpage',
					),
				),
			);

			$floorplan_min_rent = array();
			$floorplan_max_rent = array();

			$units = get_posts( $args );

			foreach ( $units as $unit ) {
				$minimum_rent = floatval( get_post_meta( $unit->ID, 'minimum_rent', true ) );
				$maximum_rent = floatval( get_post_meta( $unit->ID, 'maximum_rent', true ) );

				if ( $minimum_rent > 100 ) {
					$floorplan_min_rent[] = $minimum_rent;
				}

				if ( $maximum_rent > 100 ) {
					$floorplan_max_rent[] = $maximum_rent;
				}
			}

			// if $floorplan_min_rent is an array with at least one element.
			if ( is_array( $floorplan_min_rent ) && count( $floorplan_min_rent ) > 0 ) {

				$min_to_update = floatval( min( $floorplan_min_rent ) );

				if ( $min_to_update > 100 ) {
					$success = update_post_meta( get_the_ID(), 'minimum_rent', $min_to_update );
				}
			}

			// if $floorplan_max_rent is an array with at least one element.
			if ( is_array( $floorplan_max_rent ) && count( $floorplan_max_rent ) > 0 ) {

				$max_to_update = floatval( max( $floorplan_max_rent ) );

				if ( $max_to_update > 100 ) {
					$success = update_post_meta( get_the_ID(), 'maximum_rent', $max_to_update );
				}
			}

		endwhile;

		wp_reset_postdata();

	}
}
