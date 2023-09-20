<?php

function rfs_do_yardi_sync( $args ) {
	
	// create a new post if needed, adding the post ID to the args if we do
	$args = rfs_maybe_create_property( $args );
	
	//~ With just the property ID, we can get property data, property images, and the floorplan data.
	$property_data = rfs_yardi_get_property_data( $args );
		
	// update the property post 
	rfs_yardi_update_property_meta( $args, $property_data );
	
	// get the images for this property
	$property_images = rfs_yard_get_property_images( $args );
	
	// update the images for this property
	rfs_yardi_update_property_images( $args, $property_images );
	
	$floorplandata = rfs_yardi_get_floorplan_data( $args );	

	// TODO process the floorplan data
				
	//~ We'll need the floorplan ID to get the availablility information
	foreach ( $floorplandata as $floorplan ) {
		
		// skip if there's no floorplan id
		if ( !isset( $floorplan['FloorplanId'] ) )
			continue;
			
		$floorplan_id = $floorplan['FloorplanId'];
		
		$args['floorplan_id'] = $floorplan_id;
		
		$availabilitydata = rfs_yardi_get_floorplan_availability( $args );
		
		// TODO add the units based on the availability data
		
		// TODO process the availability data and add that to the floorplans
						
	}
}

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