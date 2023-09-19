<?php

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
	
	console_log( $args );
	
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
	
    $data = file_get_contents( $url );
	$property_images = json_decode( $data, true );	
	return $property_images;
	
}