<?php

function rfs_do_rentmanager_sync( $args ) {
		
	//~ With just the property ID, we can get property data, property images, and the floorplan data.
	// create a new post if needed, adding the post ID to the args if we do (don't need any API calls for this)
	$args = rfs_maybe_create_property( $args );	
	
	// perform the API calls to get the data
	$property_data = rfs_rentmanager_get_property_data( $args );
	
	// get the data, then update the property post 
	rfs_rentmanager_update_property_meta( $args, $property_data );
	
}


function rfs_rentmanager_get_property_data( $args ) {
	$curl = curl_init();
	
	$rentmanager_company_code = $args['credentials']['rentmanager']['companycode'];
	$url = sprintf( 'https://%s.api.rentmanager.com/Properties?filters=MarketingData.PropertyShortName,eq,%s', $rentmanager_company_code, $args['property_id'] );
	
	$partner_token = $args['credentials']['rentmanager']['partner_token'];
	$partner_token_header = sprintf( 'X-RM12API-PartnerToken: %s', $partner_token );

	curl_setopt_array($curl, array(
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
	));

	$response = curl_exec($curl);
	$property_data = json_decode( $response, true ); // decode the JSON feed
	
	curl_close($curl);
	
	return $property_data[0];
}

function rfs_rentmanager_update_property_meta( $args, $property_data ) {
	// bail if we don't have the wordpress post ID
	if ( !isset( $args['wordpress_property_post_id'] ) || !$args['wordpress_property_post_id'] )
		return;
	
	// bail if we don't have the data to update this, updating the meta to give the error (we're checking for a valie PropertyID)
	if ( !isset( $property_data['PropertyID'] ) ) {
		
		$property_data_string = json_encode( $property_data );
		// $success = update_post_meta( $args['wordpress_property_post_id'], 'updated', current_time('mysql') );
		// $success = update_post_meta( $args['wordpress_property_post_id'], 'api_error', $property_data_string );
		
		$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
		
		if ( !is_array( $api_response ) )
			$api_response = [];
	
		$api_response['properties_api'] = [
			'updated' => current_time('mysql'),
			'api_response' => $property_data_string,
		];
		
		$success = update_post_meta( $args['wordpress_property_post_id'], 'api_response', $api_response );
		
		return;
	}

	$property_id = $args['property_id'];
	$integration = $args['integration'];
	
	//* Update the title
	$post_info = array(
		'ID' => $args['wordpress_property_post_id'],
		'post_title' => $property_data['Name'],
		'post_name' => sanitize_title( $property_data['Name'] ), // update the permalink to match the new title
	);
	
	wp_update_post( $post_info );
	
	$api_response = get_post_meta( $args['wordpress_property_post_id'], 'api_response', true );
	
	if ( !is_array( $api_response ) )
		$api_response = [];
	
	$api_response['properties_api'] = [
		'updated' => current_time('mysql'),
		'api_response' => 'Updated successfully',
	];
		
	//* Update the meta
	$meta = [
		'property_id' => esc_html( $property_id ),
		// 'address' => esc_html( $property_data['address'] ),
		// 'city' => esc_html( $property_data['city'] ),
		// 'state' => esc_html( $property_data['state'] ),
		// 'zipcode' => esc_html( $property_data['zipcode'] ),
		// 'url' => esc_url( $property_data['url'] ),
		// 'description' => esc_attr( $property_data['description'] ),
		'email' => esc_html( $property_data['Email'] ),
		// 'phone' => esc_html( $property_data['phone'] ),
		// 'latitude' => esc_html( $property_data['Latitude'] ),
		// 'longitude' => esc_html( $property_data['Longitude'] ),
		'updated' => current_time('mysql'),
		'api_response' => $api_response,
	];
	
	foreach ( $meta as $key => $value ) { 
		$success = update_post_meta( $args['wordpress_property_post_id'], $key, $value );
	}
	
}