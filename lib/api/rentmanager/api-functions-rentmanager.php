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
	
	// remove various data that shouldn't still exist if the property should no longer be synced.
	rfs_remove_properties_that_shouldnt_be_synced();
	rfs_remove_floorplans_that_shouldnt_be_synced();
	rfs_remove_units_that_shouldnt_be_synced();

	// ~ With just the property ID, we can get property data, property images, and the floorplan data.
	// create a new post if needed, adding the post ID to the args if we do (don't need any API calls for this).
	$args = rfs_maybe_create_property( $args );

	// perform the API calls to get the data.
	$property_data = rfs_rentmanager_get_property_data( $args );
	
	// update $args with the numerical rentmanager property ID, found in $property_data['PropertyID']. This is to allow for using a different API while their normal one gets fixed.
	if ( isset( $property_data['PropertyID'] ) ) {
		$args['rentmanager_property_id'] = $property_data['PropertyID'];
	}

	// get the data, then update the property post.
	rfs_rentmanager_update_property_meta( $args, $property_data );

	$unit_types_data = rfs_rentmanager_get_unit_types_data( $args );

	$units_data = rfs_rentmanager_get_units_data( $args );
		
	if ( is_array( $units_data ) ) {

		// create the individual units, ignoring availability.
		foreach( $units_data as $unit ) {
			
			// skip this one if there's no attached unit type.
			if ( !isset( $unit['UnitTypeID'] ) ) {
				continue;
			}
			
			// skip this one if there's no valid unit ID.
			if ( !isset( $unit['UnitID'] ) ) {
				continue;
			}
			
			// skip this one if there's no market rent being set.
			if ( !isset( $unit['MarketRent'][0]['Amount'] ) ) {
				continue;
			}

			$args['floorplan_id'] = $args['property_id'] . '-' . $unit['UnitTypeID'];
			$args['unit_id'] = $args['property_id'] . '-' . $unit['UnitTypeID'] . '-' . $unit['UnitID'];
			
			$args = rfs_maybe_create_unit( $args );
			
			rfs_rentmanager_update_unit_meta( $args, $unit );
		}
	}

	// create the floorplans (we actually want to do this after the units, because if there are images attached to the unit_type, that should override unit images).
	foreach ( $unit_types_data as $floorplan ) {
		
		// continue if we don't have a $floorplan['Bedrooms'] and we don't have a valid $floorplan['Bathrooms']. We'd like to do this for price as well, but there are actually *none* of these in the API that have a price set.
		if ( !isset( $floorplan['Bedrooms'] ) && !isset( $floorplan['Bathrooms'] ) ) {
			continue;
		}
		
		// only create the floorplan if we have a valid UnitTypeID.
		if ( !isset( $floorplan['UnitTypeID'] ) ) {
			continue;
		}

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
			CURLOPT_TIMEOUT        => 30, // Set a reasonable timeout (30 seconds)
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'GET',
			CURLOPT_HTTPHEADER     => array(
				$partner_token_header,
				'Content-Type: application/json',
			),
			CURLOPT_SSL_VERIFYPEER => true, // Enable SSL verification
			CURLOPT_SSL_VERIFYHOST => 2,
		)
	);

	$response = curl_exec( $curl );
	$error    = curl_error( $curl );
	curl_close( $curl );

	if ( $error ) {
		error_log( 'cURL error in rfs_rentmanager_get_property_data: ' . $error );
		return array(); // Return empty array to prevent errors
	}

	$property_data = json_decode( $response, true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		error_log( 'JSON decode error in rfs_rentmanager_get_property_data: ' . json_last_error_msg() );
		return array(); // Return empty array
	}

	return $property_data[0] ?? array(); // Ensure it returns an array
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

		// Clean the JSON string
		$property_data_string = rentfetch_clean_json_string( $property_data_string );

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

	$property_data_string = wp_json_encode( $property_data );

	$api_response['properties_api'] = array(
		'updated'      => current_time( 'mysql' ),
		'api_response' => $property_data_string,
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
		'property_id'  => sanitize_text_field( $property_id ),
		'address'      => sanitize_text_field( $street ),
		'city'         => sanitize_text_field( $city ),
		'state'        => sanitize_text_field( $state ),
		'zipcode'      => sanitize_text_field( $zipcode ),
		'phone'        => sanitize_text_field( $phone ),
		'email'        => sanitize_email( $property_data['Email'] ),
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
	$url                      = sprintf( 'https://%s.api.rentmanager.com/Properties/%s/UnitTypes?embeds=Images,Images.File,Images.ImageType', $rentmanager_company_code, $args['rentmanager_property_id'] );

	$partner_token        = $args['credentials']['rentmanager']['partner_token'];
	$partner_token_header = sprintf( 'X-RM12API-PartnerToken: %s', $partner_token );

	curl_setopt_array(
		$curl,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 30, // Set a reasonable timeout (30 seconds)
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'GET',
			CURLOPT_HTTPHEADER     => array(
				$partner_token_header,
				'Content-Type: application/json',
			),
			CURLOPT_SSL_VERIFYPEER => true, // Enable SSL verification
			CURLOPT_SSL_VERIFYHOST => 2,
		)
	);

	$response = curl_exec( $curl );
	$error    = curl_error( $curl );
	curl_close( $curl );

	if ( $error ) {
		error_log( 'cURL error in rfs_rentmanager_get_unit_types_data: ' . $error );
		return array(); // Return empty array to prevent errors
	}

	$unit_types_data = json_decode( $response, true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		error_log( 'JSON decode error in rfs_rentmanager_get_unit_types_data: ' . json_last_error_msg() );
		return array(); // Return empty array
	}

	return $unit_types_data ?: array(); // Ensure it returns an array
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
	
	$images = null;
	
	if ( isset( $floorplan_data['Images'] ) && is_array( $floorplan_data['Images'] ) ) {
		$images = $floorplan_data['Images'];
	}
	
	// * Updates that involve querying the units
	$unit_query_args = array(
		'post_type'      => 'units',
		'posts_per_page' => -1, // Retrieve all matching units.
		'orderby'        => 'title',
		'order'          => 'ASC',
		'fields'         => 'ids', // Only retrieve post IDs.
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
		),
	);

	$unit_ids = get_posts( $unit_query_args );
	
	$floorplan_units_available_array = array();
	$floorplan_availability_date_array = array();
	$floorplan_rent_array = array();
	$floorplan_square_footage_array = array();

	if ( ! empty( $unit_ids ) ) {
		foreach ( $unit_ids as $unit_id ) {
			
			$unit_availability_date = get_post_meta( $unit_id, 'availability_date', true );
			$unit_minimum_rent = get_post_meta( $unit_id, 'minimum_rent', true );
			$unit_maximum_rent = get_post_meta( $unit_id, 'maximum_rent', true );
			$unit_square_footage = get_post_meta( $unit_id, 'sqrft', true );
			
			$floorplan_availability_date_array[] = $unit_availability_date;
			$floorplan_rent_array[] = $unit_minimum_rent;
			$floorplan_rent_array[] = $unit_maximum_rent;
			$floorplan_square_footage_array[] = $unit_square_footage;
			
		}
	} else {
		// silence is golden.
	}
	
	// get the floorplan min rent from the min value in the floorplan_rent_array, discarding all values below 100
	$floorplan_minimum_rent = null;
	if ( ! empty( $floorplan_rent_array ) ) {
		$floorplan_rent_array = array_filter( $floorplan_rent_array, function( $value ) {
			return $value >= 100;
		} );
		if ( ! empty( $floorplan_rent_array ) ) {
			$floorplan_minimum_rent = min( $floorplan_rent_array );
		}
	}

	// get the floorplan max rent from the max value in the floorplan_rent_array, discarding all values below 100
	$floorplan_maximum_rent = null;
	if ( ! empty( $floorplan_rent_array ) ) {
		$floorplan_rent_array = array_filter( $floorplan_rent_array, function( $value ) {
			return $value >= 100;
		} );
		if ( ! empty( $floorplan_rent_array ) ) {
			$floorplan_maximum_rent = max( $floorplan_rent_array );
		}
	}
	
	// get the minimum square footage from the floorplan_square_footage_array, discarding all values below 100
	$floorplan_minimum_square_footage = null;
	if ( ! empty( $floorplan_square_footage_array ) ) {
		$floorplan_square_footage_array = array_filter( $floorplan_square_footage_array, function( $value ) {
			return $value >= 100;
		} );
		if ( ! empty( $floorplan_square_footage_array ) ) {
			$floorplan_minimum_square_footage = min( $floorplan_square_footage_array );
		}
	}
	
	// get the maximum square footage from the floorplan_square_footage_array, discarding all values below 100
	$floorplan_maximum_square_footage = null;
	if ( ! empty( $floorplan_square_footage_array ) ) {
		$floorplan_square_footage_array = array_filter( $floorplan_square_footage_array, function( $value ) {
			return $value >= 100;
		} );
		if ( ! empty( $floorplan_square_footage_array ) ) {
			$floorplan_maximum_square_footage = max( $floorplan_square_footage_array );
		}
	}
	
	// get the number of avilable by units by counting the number of non-empty values in the floorplan_availability_date_array
	$floorplan_available_units = count( array_filter( $floorplan_availability_date_array ) );
	
	// get the floorplan availability date by looking at the date in the floorplan_availability_date_array that is closest to today. discard all empty values and values more than a week in the past.
	// $floorplan_availability_date = null;
	// if ( ! empty( $floorplan_availability_date_array ) ) {
	// 	$floorplan_availability_date_array = array_filter( $floorplan_availability_date_array, function( $value ) {
	// 		return strtotime( $value ) >= strtotime( '-1 week' );
	// 	} );
	// 	$floorplan_availability_date = min( $floorplan_availability_date_array );
	// }
	
	if ( isset( $floorplan_data['Bedrooms'] ) ) {
		// silence is golden.
	}

	// * Update the meta
	$meta = array(
		'baths'     => floatval( $floorplan_data['Bathrooms'] ?? 0 ),
		'beds'      => floatval( $floorplan_data['Bedrooms'] ?? 0 ),
		'available_units' => floatval( $floorplan_available_units ?? 0 ),
		'floorplan_id' => $floorplan_id ?? '',
		'property_id' => $property_id ?? '',
		'maximum_rent' => $floorplan_maximum_rent ?? 0,
		'maximum_sqft' => $floorplan_maximum_square_footage ?? 0,
		'minimum_rent' => $floorplan_minimum_rent ?? 0,
		'minimum_sqft' => $floorplan_minimum_square_footage ?? 0,
		'floorplan_image_url' => $images ?? '',
		'availability_date' => null,
		'updated'   => current_time( 'mysql' ),
		'api_error' => wp_json_encode( $floorplan_data ),
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
	$url                      = sprintf( 'https://%s.api.rentmanager.com/Units?embeds=CurrentOccupancyStatus,CurrentOccupancyStatus.UnitStatus,IsVacant,MarketingValues,MarketingValues.Images,MarketRent,UnitAmenities,UnitStatuses,UnitStatuses.UnitStatusType&filters=Property.ShortName,eq,%s', $rentmanager_company_code, $args['property_id'] );

	$partner_token        = $args['credentials']['rentmanager']['partner_token'];
	$partner_token_header = sprintf( 'X-RM12API-PartnerToken: %s', $partner_token );

	curl_setopt_array(
		$curl,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 30, // Set a reasonable timeout (30 seconds)
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'GET',
			CURLOPT_HTTPHEADER     => array(
				$partner_token_header,
				'Content-Type: application/json',
			),
			CURLOPT_SSL_VERIFYPEER => true, // Enable SSL verification
			CURLOPT_SSL_VERIFYHOST => 2,
		)
	);

	$response = curl_exec( $curl );
	$error    = curl_error( $curl );
	curl_close( $curl );

	if ( $error ) {
		error_log( 'cURL error in rfs_rentmanager_get_units_data: ' . $error );
		return array(); // Return empty array to prevent errors
	}

	$units_data = json_decode( $response, true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		error_log( 'JSON decode error in rfs_rentmanager_get_units_data: ' . json_last_error_msg() );
		return array(); // Return empty array
	}

	return $units_data ?: array(); // Ensure it returns an array
}

function rfs_rentmanager_update_unit_meta( $args, $unit ) {
	
	if ( !isset( $unit['Name'] ) ) {
		return;
	}
	
	// update the post title from $unit['Name']
	$post_info = array(
		'ID'         => $args['wordpress_unit_post_id'],
		'post_title' => esc_html( $unit['Name'] ),
		'post_name'  => sanitize_title( $unit['Name'] ), // update the permalink to match the new title.
	);
	
	wp_update_post( $post_info );
	
	$availability_date = null;
	if ( isset( $unit['IsVacant'] ) && $unit['IsVacant'] === true ) {
		// set the availability date to today.
		$availability_date = current_time( 'mysql' );
	} else {
		// bail if the unit is not vacant. 
		//! NOTE: THIS MEANS WE ARE NOT SYNCING OCCUPIED UNITS.
		//! FOR THIS INFORMATION, WE NEED TO PULL A REPORT ON UNIT AVAILABILITY SPECIFICALLY FROM ANOTHER API.
		// delete the post if it exists.
		if ( isset( $args['wordpress_unit_post_id'] ) && $args['wordpress_unit_post_id'] ) {
			wp_delete_post( $args['wordpress_unit_post_id'], true );
		}
		return;
	}
	
	// get the rent
	$minimum_rent = null;
	$maximum_rent = null;
	if ( isset( $unit['MarketRent'] ) && is_array( $unit['MarketRent'] ) ) {
		foreach( $unit['MarketRent'] as $rent ) {
			if ( null === $minimum_rent ) {
				$minimum_rent = $rent['Amount'];
			}
			
			if ( null === $maximum_rent ) {
				$maximum_rent = $rent['Amount'];
			}
			
			if ( $minimum_rent > $rent['Amount'] ) {
				$minimum_rent = $rent['Amount'];
			}
			
			if ( $maximum_rent < $rent['Amount'] ) {
				$maximum_rent = $rent['Amount'];
			}
		}
	}
	
	if ( empty( $minimum_rent ) ) {
		$minimum_rent = 0;
	}
	
	if ( empty( $maximum_rent ) ) {
		$maximum_rent = 0;
	}
	
	// * Update the meta
	$meta = array(
		'floor'                     => (int) ($unit['FloorID'] ?? 0),
		'maxoccupancy'              => (int) ($unit['MaxOccupancy'] ?? 0),
		'amenities'                 => null,
		'unit_id'                   => $args['property_id'] . '-' . ($unit['UnitTypeID'] ?? '') . '-' . ($unit['UnitID'] ?? ''),
		'floorplan_id'              => $args['property_id'] . '-' . ($unit['UnitTypeID'] ?? ''),
		'property_id'               => $args['property_id'],
		'apply_online_url'          => null,
		'availability_date'         => sanitize_text_field( $availability_date ),
		'baths'                     => floatval( $unit['Bathrooms'] ?? 0),
		'beds'                      => (int) ($unit['Bedrooms'] ?? 0),
		'deposit'                   => null,
		'minimum_rent'              => $minimum_rent,
		'maximum_rent'              => $maximum_rent,
		'sqrft'                     => (int) ($unit['SquareFootage'] ?? 0),
		'specials'                  => null,
		'unit_source'               => 'rentmanager',
		'updated'                   => current_time( 'mysql' ),
		'api_error'                 => wp_json_encode( $unit ),
		// 'api_response'              => $api_response,
	);

	foreach ( $meta as $key => $value ) {
		$success = update_post_meta( $args['wordpress_unit_post_id'], $key, $value );
	}
}