<?php

/**
 * Update an individual Yardi floorplan
 *
 * @param  array $args                the arguments passed to the function
 * @param  object $unit      the floorplan data.
 */
function rfs_yardi_update_unit_meta( $args, $unit ) {

	// bail if we don't have the WordPress post ID
	if ( ! isset( $args['wordpress_unit_post_id'] ) || ! $args['wordpress_unit_post_id'] ) {
		return;
	}

	// bail if we don't have the data to update this, updating the meta to give the error
	if ( ! $unit->ApartmentId ) {
		$unit_data_string = json_encode( $unit );
		$success          = update_post_meta( $args['wordpress_unit_post_id'], 'updated', current_time( 'mysql' ) );
		$success          = update_post_meta( $args['wordpress_unit_post_id'], 'api_error', $unit_data_string );

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

	$unit_id      = $args['unit_id'];
	$floorplan_id = $args['floorplan_id'];
	$property_id  = $args['property_id'];
	$integration  = $args['integration'];

	// * Update the title
	$post_info = array(
		'ID'         => $args['wordpress_unit_post_id'],
		'post_title' => $unit->ApartmentName,
		'post_name'  => sanitize_title( $unit->ApartmentName ), // update the permalink to match the new title
	);

	wp_update_post( $post_info );

	// escape the array of image urls
	if ( isset( $unit->UnitImageURLs ) && is_array( $unit->UnitImageURLs ) ) {
		$UnitImageURLs = array();

		foreach ( $unit->UnitImageURLs as $url ) {
			$UnitImageURLs[] = esc_url( $url );
		}
	}

	$api_response = get_post_meta( $args['wordpress_unit_post_id'], 'api_response', true );

	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	$api_response['apartmentavailability_api'] = array(
		'updated'      => current_time( 'mysql' ),
		'api_response' => 'Updated successfully',
	);

	// further processing the amenities.
	$amenities_string = $unit->Amenities;
	$amenities_array  = explode( '^', $amenities_string );

	// * Update the meta
	$meta = array(
		'amenities'                 => array_map( 'sanitize_text_field', $amenities_array ),
		'unit_id'                   => esc_html( $args['unit_id'] ),
		'floorplan_id'              => esc_html( $args['floorplan_id'] ),
		'property_id'               => esc_html( $args['property_id'] ),
		'apply_online_url'          => esc_html( $unit->ApplyOnlineURL ),
		'availability_date'         => esc_html( $unit->AvailableDate ),
		'baths'                     => floatval( $unit->Baths ),
		'beds'                      => floatval( $unit->Beds ),
		'deposit'                   => esc_html( $unit->Deposit ),
		'minimum_rent'              => esc_html( $unit->MinimumRent ),
		'maximum_rent'              => esc_html( $unit->MaximumRent ),
		'sqrft'                     => esc_html( $unit->SQFT ),
		'specials'                  => esc_html( $unit->Specials ),
		'yardi_unit_image_alt_text' => esc_html( $unit->UnitImageAltText ),
		'yardi_unit_image_urls'     => $UnitImageURLs,
		'unit_source'               => 'yardi',
		'updated'                   => current_time( 'mysql' ),
		'api_error'                 => 'Updated successfully',
		'api_response'              => $api_response,
	);

	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_unit_post_id'], $key, $value );
	}
}
