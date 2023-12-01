<?php

// let's convert the Voyager Codes to property codes for Yardi
add_action( 'admin_footer', 'rfs_convert_voyager_codes' );
function rfs_convert_voyager_codes() {
	
	$voyagers = get_option( 'rentfetch_options_yardi_integration_creds_yardi_voyager_code' );
		
	if ( !$voyagers )
		return;
		
	// remove spaces
	$voyagers = str_replace( ' ', '', $voyagers );
	
	// split into array
	$voyagers = explode( ',', $voyagers );
		
	$property_codes = [];
	
	foreach( $voyagers as $voyager ) {
		
		$credentials = rfs_get_credentials();
		
		if ( !isset( $credentials['yardi']['apikey'] ) )
			return;
		
		$yardi_api_key = $credentials['yardi']['apikey'];
		
		// Do the API request
		$url = sprintf( 
			'https://api.rentcafe.com/rentcafeapi.aspx?requestType=property&type=marketingData&apiToken=%s&VoyagerPropertyCode=%s',
			$yardi_api_key, 
			$voyager
		);
		
		$data = file_get_contents( $url ); // put the contents of the file into a variable        
		$propertydata = json_decode( $data, true ); // decode the JSON feed
		
		$property_codes[] = $propertydata[0]['PropertyData']['PropertyCode'];
	}
	
	// get the previous property codes
	$previous_property_codes = get_option( 'rentfetch_options_yardi_integration_creds_yardi_property_code' );
	
	// make the previous property codes into an array
	$previous_property_codes_array = explode( ',', $previous_property_codes );
	
	// merge the previous property codes with the new ones
	$property_codes = array_merge( $property_codes, $previous_property_codes_array );
	
	// remove duplicates
	$property_codes = array_unique( $property_codes );
	
	// convert back to string
	$property_codes = implode( ', ', $property_codes );
	
	// update the option
	update_option( 'rentfetch_options_yardi_integration_creds_yardi_property_code', $property_codes );
	
	// remove the voyager codes
	update_option( 'rentfetch_options_yardi_integration_creds_yardi_voyager_code', null );
	
}