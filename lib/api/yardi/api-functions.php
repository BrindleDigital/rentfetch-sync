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
	
	// TODO add the amenities
	
	// get all the floorplan data for this property
	$floorplans_data = rfs_yardi_get_floorplan_data( $args );
					
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
		
		//~ The availability data includes the units, so we can update the units for this floorplan
		foreach( $availability_data as $unit ) {
			
			// TODO add the units based on the availability data
			
			// continue if we don't have a unit ID
			
			// get the unit ID
			
			// maybe create unit
			
			// update the unit/unit availability
			
		}
		
		// TODO remove the orphan floorplans (need to fix the rfs_delete_orphan_yardi_floorplans function)
						
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
