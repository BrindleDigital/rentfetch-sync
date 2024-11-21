<?php

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
function rfs_yardi_get_property_images( $args ) {
	
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