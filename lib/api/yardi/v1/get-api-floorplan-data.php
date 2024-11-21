<?php

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