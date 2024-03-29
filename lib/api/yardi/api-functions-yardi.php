<?php

function rfs_do_yardi_sync( $args ) {
		
	//~ With just the property ID, we can get property data, property images, and the floorplan data.
	// create a new post if needed, adding the post ID to the args if we do (don't need any API calls for this)
	$args = rfs_maybe_create_property( $args );	
	
	// perform the API calls to get the data
	$property_data = rfs_yardi_get_property_data( $args );
	
	// get the data, then update the property post 
	rfs_yardi_update_property_meta( $args, $property_data );
	
	$property_images = rfs_yard_get_property_images( $args );
	
	// get the images, then update the images for this property
	rfs_yardi_update_property_images( $args, $property_images );
	
	// add the amenities
	rfs_yardi_update_property_amenities( $args, $property_data );
	
	// get all the floorplan data for this property
	$floorplans_data = rfs_yardi_get_floorplan_data( $args );
	
	// remove availability for floorplans that no longer are found in the API (we don't delete these because Yardi sometimes doesn't show floorplans with zero availability)
	rfs_remove_availability_orphan_yardi_floorplans( $floorplans_data, $property_data );
					
	//~ We'll need the floorplan ID to get the availablility information
	foreach ( $floorplans_data as $floorplan ) {
		
		// skip if there's no floorplan id
		if ( !isset( $floorplan['FloorplanId'] ) )
			continue;
			
		$floorplan_id = $floorplan['FloorplanId'];
		$args['floorplan_id'] = $floorplan_id;
		
		// now that we have the floorplan ID, we can create that if needed, or just get the post ID if it already exists (returned in $args)
		$args = rfs_maybe_create_floorplan( $args );
		
		rfs_yardi_update_floorplan_meta( $args, $floorplan );
		
		$availability_data = rfs_yardi_get_floorplan_availability( $args );
		
		rfs_yardi_update_floorplan_availability( $args, $availability_data );
		
		// Remove the units that aren't in the API for this floorplan
		rfs_remove_units_no_longer_available( $availability_data, $args );
		
		//~ The availability data includes the units, so we can update the units for this floorplan
		foreach( $availability_data as $unit ) {
			
			// skip if there's no floorplan id
			if ( !property_exists( $unit, 'ApartmentId' ) || !$unit->ApartmentId )
				continue;
			
			$unit_id = $unit->ApartmentId;
			$args['unit_id'] = $unit_id;
			
			$args = rfs_maybe_create_unit( $args );
			
			rfs_yardi_update_unit_meta( $args, $unit );
			
		}
		
						
	}
}

/**
 * Get the property meta information
 *
 */
function rfs_yardi_get_property_data( $args ) {
	
	$yardi_api_key = $args['credentials']['yardi']['apikey'];
	$property_id = $args['property_id']; // Add this line to get the property_id

	// Do the API request
	$url = sprintf( 
		'https://api.rentcafe.com/rentcafeapi.aspx?requestType=property&type=marketingData&apiToken=%s&PropertyCode=%s',
		$yardi_api_key, 
		$property_id
	);
	
	$data = file_get_contents( $url ); // put the contents of the file into a variable        
	$propertydata = json_decode( $data, true ); // decode the JSON feed

	// we only want the first item in the array because this always returns everything inside an array
	return $propertydata[0];
	
}

/**
 * Get the property images (separate API from the other property data)
 *
 */
function rfs_yard_get_property_images( $args ) {
	
	$yardi_api_key = $args['credentials']['yardi']['apikey'];
	$property_id = $args['property_id']; 
	
	// Do the API request
	$url = sprintf( 
		'https://api.rentcafe.com/rentcafeapi.aspx?requestType=images&type=propertyImages&apiToken=%s&PropertyCode=%s', 
		$yardi_api_key, 
		$property_id 
	);
	
	$property_images = file_get_contents( $url );
	return $property_images;
	
}

/**
 * Get the floorplan data
 *
 */
function rfs_yardi_get_floorplan_data( $args ) {
	
	$yardi_api_key = $args['credentials']['yardi']['apikey'];
	$property_id = $args['property_id']; // Add this line to get the property_id

	// Do the API request
	$url = sprintf( 
		'https://api.rentcafe.com/rentcafeapi.aspx?requestType=floorplan&apiToken=%s&PropertyCode=%s', 
		$yardi_api_key, 
		$property_id 
	);
	
	$data = file_get_contents( $url ); // put the contents of the file into a variable        
	$floorplandata = json_decode( $data, true ); // decode the JSON feed

	// we only want the first item in the array because this always returns everything inside an array
	return $floorplandata;
	
}

/**
 * Get availability information for the floorplan
 *
 */
function rfs_yardi_get_floorplan_availability( $args ) {
	
	$yardi_api_key = $args['credentials']['yardi']['apikey'];
	$property_id = $args['property_id'];
	$floorplan_id = $args['floorplan_id'];
		
	// Do the API request
	$url = sprintf( 
		'https://api.rentcafe.com/rentcafeapi.aspx?requestType=apartmentavailability&floorplanId=%s&apiToken=%s&PropertyCode=%s', 
		$floorplan_id, 
		$yardi_api_key, 
		$property_id 
	); // path to your JSON file
	
	$data = file_get_contents( $url ); // put the contents of the file into a variable        
	
	// process the data to get the date in yardi's format
	$availability = json_decode( $data );  
	
	return $availability;
	
}

function rfs_yardi_update_property_amenities( $args, $property_data ) {
		
	// bail if we don't have Amenities in the data	
	if ( !isset( $property_data['Amenities'] ) )
		return;
		
	$amenities = $property_data['Amenities'];
		
	// bail if we don't have the property ID
	if ( !isset( $args['wordpress_property_post_id'] ) )
		return;
	
	$property_id = $args['wordpress_property_post_id'];
	$taxonomy = 'amenities';
	
	// remove all of the current terms from $property_id
	$term_ids = wp_get_object_terms( $property_id, $taxonomy, array( 'fields' => 'ids' ) );
	$term_ids = array_map( 'intval', $term_ids );
	wp_remove_object_terms( $property_id, $term_ids, $taxonomy );
	
	// wp_delete_object_term_relationships( $property_id, array( 'amenities' ) );
	
	// for each of those amenities, grab the name, then add it
	foreach ( $amenities as $amenity ) {
		
		if ( !isset( $amenity['CustomAmenityName'] ) )
			continue;
		
		// get the name
		$name = $amenity['CustomAmenityName'];
				
		// this function checks if the amenity exists, creates it if not, then adds it to the post
		rentfetch_set_post_term( $property_id, $name, 'amenities' );
	}
	
}

function rfs_remove_units_no_longer_available( $availability_data, $args ) {
	
	// get the units that show up in the API
	$unit_ids_from_api = array();
	foreach( $availability_data as $unit ) {
		
		// skip if there's no ApartmentID
		if ( !property_exists( $unit, 'ApartmentId' ) || !$unit->ApartmentId )
			continue;
		
		$unit_ids_from_api[] = $unit->ApartmentId;

	}
	
	// get the units that are in the database from this property and floorplan
	$units = get_posts( array(
		'post_type' => 'units',
		'posts_per_page' => -1,
		'meta_query' => array(
			'relation' => 'AND',
			array(
				'key' => 'property_id',
				'value' => $args['property_id'],
			),
			array(
				'key' => 'floorplan_id',
				'value' => $args['floorplan_id'],
			),
			array(
				'key' => 'unit_source',
				'value' => 'yardi',
			),
		),
	) );
	
	// if we don't have any units, bail
	if ( !$units )
		return;
	
	// get the unit IDs from the database
	$unit_ids_from_db = array();
	foreach( $units as $unit ) {
		$unit_ids_from_db[] = get_post_meta( $unit->ID, 'yardi_unit_id', true );
	}
	
	// get the unit IDs that are in the database but not in the API
	$unit_ids_to_remove = array_diff( $unit_ids_from_db, $unit_ids_from_api );
	
	// remove the units that are in the database but not in the API
	foreach( $units as $unit ) {
		if ( in_array( get_post_meta( $unit->ID, 'yardi_unit_id', true ), $unit_ids_to_remove ) ) {
			wp_delete_post( $unit->ID, true );
		}
	}
	
}