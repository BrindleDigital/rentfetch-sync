<?php

function rfs_do_realpage_sync( $args ) {
	
	//~ With just the property ID, we can figure out if we need to make a new post
	// create a new post if needed, adding the post ID to the args if we do (don't need any API calls for this)
	$args = rfs_maybe_create_property( $args );
	
	// Realpage doesn't have a property data API call, so we don't need to update any property stuff
	
	// Get all the floorplan data for this property
	$floorplans_data = rfs_realpage_get_floorplan_data( $args );
		
	// We have a floorplans API that returns all of the floorplans for a property, so we can just cycle through those
	foreach( $floorplans_data as $floorplan ) {
				
		// skip if there's no floorplan id
		if ( !isset( $floorplan['FloorPlanID'] ) )
			continue;
		
		$floorplan_id = $floorplan['FloorPlanID'];
		$args['floorplan_id'] = $floorplan_id;
				
		// now that we have the floorplan ID, we can create that if needed, or just get the post ID if it already exists (returned in $args)
		$args = rfs_maybe_create_floorplan( $args );
		
		rfs_realpage_update_floorplan_meta( $args, $floorplan );
		
	}
	
	// We have a units API that returns all of the units for a property, so we can just cycle through those
	$units_data = rfs_realpage_get_unit_data( $args );
		
	foreach ( $units_data as $unit ) {
				
		if ( !isset( $unit['FloorPlan']['FloorPlanID'] ) )
			continue;
				
		$args['floorplan_id'] = intval( $unit['FloorPlan']['FloorPlanID'] );
				
		// skip if there's no unit id
		if ( !isset( $unit['Address']['UnitID'] ) )
			continue;
			
		$unit_id = $unit['Address']['UnitID'];
		$args['unit_id'] = $unit_id;
		
		$args = rfs_maybe_create_unit( $args );
		
		// TODO update the unit meta
		rfs_realpage_update_unit_meta( $args, $unit );
		
	}
	
	// we've already updated the units, but we need to update the floorplan availability based on the units
	rfs_realpage_update_floorplan_availability_from_units( $args, $units_data );
	
	
}

function rfs_realpage_get_floorplan_data( $args ) {
	
	$realpage_user = $args['credentials']['realpage']['user'];
	$realpage_pass = $args['credentials']['realpage']['password'];
	$realpage_pmc_id = $args['credentials']['realpage']['pmc_id'];
	$realpage_site_id = $args['property_id'];
	
	$curl = curl_init();
	
	$xml = sprintf(
		'<?xml version="1.0" encoding="utf-8"?>
		<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
			<soap12:Header>
				<UserAuthInfo xmlns="http://realpage.com/webservices">
				<UserName>%s</UserName>
				<Password>%s</Password>
				<SiteID>%s</SiteID>
				<PmcID>%s</PmcID>
				<InternalUser>1</InternalUser>
				</UserAuthInfo>
			</soap12:Header>
			<soap12:Body>
				<List xmlns="http://realpage.com/webservices">
					<!-- removed information here from the sample request -->
				</List>
			</soap12:Body>
		</soap12:Envelope>',
		$realpage_user,
		$realpage_pass,
		$realpage_site_id,
		$realpage_pmc_id,
	);

	curl_setopt_array($curl, array(
		CURLOPT_URL => 'https://Onesite.RealPage.com/WebServices/CrossFire/AvailabilityAndPricing/Floorplan.asmx',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 0,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_POSTFIELDS => $xml,
		CURLOPT_HTTPHEADER => array(
			'Content-Type: application/soap+xml; charset=utf-8'
		),
	));

	$response = curl_exec($curl);
	
	// SimpleXML seems to have problems with the colon ":" in the <xxx:yyy> response tags, so take them out
	$xml = preg_replace('/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $response);
	$xml = simplexml_load_string($xml);
	$json = json_encode($xml);
	$responseArray = json_decode($json,true);
	$floorplans_data = $responseArray['soapBody']['ListResponse']['ListResult']['FloorPlanObject'];
	
	return $floorplans_data;
	
}

function rfs_realpage_get_unit_data( $args ) {
	
	$realpage_user = $args['credentials']['realpage']['user'];
	$realpage_pass = $args['credentials']['realpage']['password'];
	$realpage_pmc_id = $args['credentials']['realpage']['pmc_id'];
	$realpage_site_id = $args['property_id'];
	
	$curl = curl_init();
	
	$xml = sprintf(
		'<?xml version="1.0" encoding="utf-8"?>
		<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
			<soap12:Header>
				<UserAuthInfo xmlns="http://realpage.com/webservices">
				<UserName>%s</UserName>
				<Password>%s</Password>
				<SiteID>%s</SiteID>
				<PmcID>%s</PmcID>
				<InternalUser>1</InternalUser>
				</UserAuthInfo>
			</soap12:Header>
			<soap12:Body>
				<List xmlns="http://realpage.com/webservices">
					<!-- removed information here from the sample request -->
				</List>
			</soap12:Body>
		</soap12:Envelope>',
		$realpage_user,
		$realpage_pass,
		$realpage_site_id,
		$realpage_pmc_id,
	);

	curl_setopt_array($curl, array(
		CURLOPT_URL => 'https://onesite.realpage.com/WebServices/CrossFire/AvailabilityAndPricing/Unit.asmx',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 0,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_POSTFIELDS => $xml,
		CURLOPT_HTTPHEADER => array(
			'Content-Type: application/soap+xml; charset=utf-8'
		),
	));

	$response = curl_exec($curl);
	
	// SimpleXML seems to have problems with the colon ":" in the <xxx:yyy> response tags, so take them out
	$xml = preg_replace('/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $response);
	$xml = simplexml_load_string($xml);
	$json = json_encode($xml);
	$responseArray = json_decode($json,true);
	$units_data = $responseArray['soapBody']['ListResponse']['ListResult']['UnitObject'];
	
	// the API returns a single unit as an array, but multiple units as an array of arrays, so we need to make sure we're always working with an array of arrays
	// So if it's giving us a singe unit, turn it into an array of one unit
	if ( isset( $units_data['PropertyNumberID'] ) )
		$units_data = array( $units_data );
		
	return $units_data;

}