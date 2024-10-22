<?php

function rfs_get_rentmanager_properties_from_setting() {
	
	$credentials = rfs_get_credentials();
	
	if ( !isset( $credentials['rentmanager']['companycode'] ) || !isset( $credentials['rentmanager']['partner_token'] ) ) {
		update_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames', 'Provide credentials to get properties list.' );
	}
	
	$rentmanager_company_code = $credentials['rentmanager']['companycode'];
	$url = sprintf( 'https://%s.api.rentmanager.com/Properties?fields=Name,PropertyID,ShortName', $rentmanager_company_code );
	
	$partner_token = $credentials['rentmanager']['partner_token'];
	$partner_token_header = sprintf( 'X-RM12API-PartnerToken: %s', $partner_token );
	
	$curl = curl_init();

	curl_setopt_array( $curl, array(
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 0,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'GET',
		CURLOPT_HTTPHEADER => array(
			$partner_token_header,
			'Content-Type: application/json'
		),
	) );

	$response = curl_exec($curl);
	$data = json_decode( $response, true ); // decode the JSON feed
	
	curl_close($curl);
	
	// escape the array.
	$filters = [
		'Name'			=> 'sanitize_text_field',
		'ShortName'		=> 'sanitize_text_field',
		'PropertyID'	=> 'absint'
	];

	// Sanitize the entire array.
	$sanitized_data = array_map(function($value, $key) use ($filters) {
		return isset($filters[$key]) ? call_user_func($filters[$key], $value) : $value;
	}, $data, array_keys($data));
	
	// save the sanitized data.
	update_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames', $sanitized_data );
	
}