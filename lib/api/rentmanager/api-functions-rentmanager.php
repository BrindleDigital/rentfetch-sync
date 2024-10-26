<?php
/**
 * Rent manager api functions.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Rent Manager works a little differently than the other APIs. We don't actually want the user to give use the list of property shortnames for this one,
// but instead we want to grab those from the Rent Manager API. This is because all properties are associated with a location, and we can only look them
// up by location. So we need to get from the user a list of the locations they'd like to sync (there might be more than 1), and we can get the properties from that,
// perhaps populating the setting field in the RFS settings with those properties by location.

/**
 * Sync a particular property from Rent Manager
 *
 * @param   array $args  everything needed to do this.
 *
 * @return  void.
 */
function rfs_do_rentmanager_sync( $args ) {

	// ~ With just the property ID, we can get property data, property images, and the floorplan data.
	// create a new post if needed, adding the post ID to the args if we do (don't need any API calls for this).
	$args = rfs_maybe_create_property( $args );

	// perform the API calls to get the data.
	$property_data = rfs_rentmanager_get_property_data( $args );

	// get the data, then update the property post.
	rfs_rentmanager_update_property_meta( $args, $property_data );

	// get all the floorplans data for this property.
	$unit_types_data = rfs_rentmanager_get_unit_types_data( $args );

	// get all of the units for this property.
	$units_data = rfs_rentmanager_get_units_data( $args );
	
	// create the individual units, ignoring availability.
	foreach( $units_data as $unit ) {
		$args['floorplan_id'] = $args['property_id'] . '-' . $unit['UnitType']['UnitTypeID'];
		$args['unit_id'] = $args['property_id'] . '-' . $unit['UnitType']['UnitTypeID'] . '-' . $unit['UnitID'];
		
		$args = rfs_maybe_create_unit( $args );
	}

	foreach ( $unit_types_data as $floorplan ) {

		$floorplan_id         = $args['property_id'] . '-' . $floorplan['UnitTypeID'];
		$args['floorplan_id'] = $floorplan_id;

		// now that we have the floorplan ID, we can create that if needed, or just get the post ID if it already exists (returned in $args).
		$args = rfs_maybe_create_floorplan( $args );

		// add the meta for this floorplan.
		rfs_rentmanager_update_floorplan_meta( $args, $floorplan );
	}
}

/**
 * Get the property data from Rent Manager.
 *
 * @param   array $args  everything needed to do this.
 *
 * @return  array the property information.
 */
function rfs_rentmanager_get_property_data( $args ) {
	$curl = curl_init();

	$rentmanager_company_code = $args['credentials']['rentmanager']['companycode'];
	$url                      = sprintf( 'https://%s.api.rentmanager.com/Properties?embeds=Images,Images.File,Images.ImageType,Addresses,Addresses.AddressType,PhoneNumbers&filters=ShortName,eq,%s', $rentmanager_company_code, $args['property_id'] );

	$partner_token        = $args['credentials']['rentmanager']['partner_token'];
	$partner_token_header = sprintf( 'X-RM12API-PartnerToken: %s', $partner_token );

	curl_setopt_array(
		$curl,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'GET',
			CURLOPT_HTTPHEADER     => array(
				$partner_token_header,
				'Content-Type: application/json',
			),
		)
	);

	$response      = curl_exec( $curl );
	$property_data = json_decode( $response, true ); // decode the JSON feed.

	curl_close( $curl );

	return $property_data[0];
}

/**
 * Update the WordPress property post using the Rent Manager data.
 *
 * @param   array $args           everything needed to do this.
 * @param   array $property_data  the property data to update.
 *
 * @return  null nothing.
 */
function rfs_rentmanager_update_property_meta( $args, $property_data ) {
	// bail if we don't have the WordPress post ID.
	if ( ! isset( $args['wordpress_property_post_id'] ) || ! $args['wordpress_property_post_id'] ) {
		return;
	}

	// bail if we don't have the data to update this, updating the meta to give the error (we're checking for a valid PropertyID).
	if ( ! isset( $property_data['PropertyID'] ) ) {

		$property_data_string = wp_json_encode( $property_data );

		$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );

		if ( ! is_array( $api_response ) ) {
			$api_response = array();
		}

		$api_response['properties_api'] = array(
			'updated'      => current_time( 'mysql' ),
			'api_response' => $property_data_string,
		);

		$success = update_post_meta( $args['wordpress_property_post_id'], 'api_response', $api_response );

		return;
	}

	$property_id = $args['property_id'];
	$integration = $args['integration'];

	// * Update the title
	$post_info = array(
		'ID'         => $args['wordpress_property_post_id'],
		'post_title' => $property_data['Name'],
		'post_name'  => sanitize_title( $property_data['Name'] ), // update the permalink to match the new title.
	);

	wp_update_post( $post_info );

	$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );

	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	$api_response['properties_api'] = array(
		'updated'      => current_time( 'mysql' ),
		'api_response' => 'Updated successfully',
	);

	$street  = null;
	$city    = null;
	$state   = null;
	$zipcode = null;

	// figure out the addresses.
	if ( isset( $property_data['Addresses'] ) ) {
		foreach ( $property_data['Addresses'] as $address ) {

			// bail on this loop if it's not the primary.
			if ( ! isset( $address['IsPrimary'] ) ) {
				continue;
			}

			$street  = $address['Street'];
			$city    = $address['City'];
			$state   = $address['State'];
			$zipcode = $address['PostalCode'];
		}
	}

	$phone = null;

	if ( isset( $property_data['PhoneNumbers'] ) && is_array( $property_data['PhoneNumbers'] ) ) {
		foreach ( $property_data['PhoneNumbers'] as $phone_number ) {

			// bail on this loop if it's not the primary.
			if ( ! isset( $phone_number['IsPrimary'] ) ) {
				continue;
			}

			$phone = $phone_number['PhoneNumber'];
		}
	}
	
	// handle the images.	
	if ( isset( $property_data['Images'] ) && is_array( $property_data['Images'] ) ) {
		$images = $property_data['Images'];
		$success = update_post_meta( $args['wordpress_property_post_id'], 'synced_property_images', $images );
	}

	// * Update the meta
	$meta = array(
		'property_id'  => esc_html( $property_id ),
		'address'      => esc_html( $street ),
		'city'         => esc_html( $city ),
		'state'        => esc_html( $state ),
		'zipcode'      => esc_html( $zipcode ),
		'phone'        => esc_html( $phone ),
		'email'        => esc_html( $property_data['Email'] ),
		'updated'      => current_time( 'mysql' ),
		'api_response' => $api_response,
	);

	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_property_post_id'], $key, $value );
	}
}

/**
 * Get the unit types (floorplans) from Rent Manager.
 *
 * @param   array $args everything needed to do this.
 *
 * @return  array the unit types data.
 */
function rfs_rentmanager_get_unit_types_data( $args ) {
	$curl = curl_init();

	$rentmanager_company_code = $args['credentials']['rentmanager']['companycode'];
	$url                      = sprintf( 'https://%s.api.rentmanager.com/UnitTypes?filters=Properties.ShortName,eq,%s', $rentmanager_company_code, $args['property_id'] );

	$partner_token        = $args['credentials']['rentmanager']['partner_token'];
	$partner_token_header = sprintf( 'X-RM12API-PartnerToken: %s', $partner_token );

	curl_setopt_array(
		$curl,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'GET',
			CURLOPT_HTTPHEADER     => array(
				$partner_token_header,
				'Content-Type: application/json',
			),
		)
	);

	$response        = curl_exec( $curl );
	$unit_types_data = json_decode( $response, true ); // decode the JSON feed.

	curl_close( $curl );

	return $unit_types_data;
}

/**
 * Update the floorplan meta information.
 *
 * @param   array $args         everything needed to do this.
 * @param   array $floorplan_data  the floorplan data.
 *
 * @return  void.
 */
function rfs_rentmanager_update_floorplan_meta( $args, $floorplan_data ) {

	$property_id  = $args['property_id'];
	$floorplan_id = $args['floorplan_id'];
	$integration  = $args['integration'];

	// * Update the title
	$post_info = array(
		'ID'         => (int) $args['wordpress_floorplan_post_id'],
		'post_title' => esc_html( $floorplan_data['Name'] ),
		'post_name'  => sanitize_title( $floorplan_data['Name'] ), // update the permalink to match the new title.
	);

	wp_update_post( $post_info );

	// * Update the meta
	$meta = array(
		'baths'     => floatval( $floorplan_data['Bathrooms'] ),
		'beds'      => floatval( $floorplan_data['Bedrooms'] ),
		'updated'   => current_time( 'mysql' ),
		'api_error' => 'Updated successfully',
	);

	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_floorplan_post_id'], $key, $value );
	}
}

/**
 * Get all of the unit data (including availability) for a property
 *
 * @param   array $args  everything needed to do this.
 *
 * @return  array  the unit data.
 */
function rfs_rentmanager_get_units_data( $args ) {
	$curl = curl_init();

	$rentmanager_company_code = $args['credentials']['rentmanager']['companycode'];
	$url                      = sprintf( 'https://%s.api.rentmanager.com/Units?embeds=UnitStatuses,UnitStatuses.UnitStatusType,UnitType&filters=Property.ShortName,eq,%s', $rentmanager_company_code, $args['property_id'] );

	$partner_token        = $args['credentials']['rentmanager']['partner_token'];
	$partner_token_header = sprintf( 'X-RM12API-PartnerToken: %s', $partner_token );

	curl_setopt_array(
		$curl,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'GET',
			CURLOPT_HTTPHEADER     => array(
				$partner_token_header,
				'Content-Type: application/json',
			),
		)
	);

	$response      = curl_exec( $curl );
	$units_data = json_decode( $response, true ); // decode the JSON feed.

	curl_close( $curl );

	return $units_data;
}
