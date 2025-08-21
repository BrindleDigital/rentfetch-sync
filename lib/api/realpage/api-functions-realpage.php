<?php
/**
 * Realpage functions.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Do the RealPage sync.
 *
 * @param   array $args The arguments passed to the function, including the property ID and the credentials.
 *
 * @return  void.
 */
function rfs_do_realpage_sync( $args ) {

	// ~ With just the property ID, we can figure out if we need to make a new post.
	// create a new post if needed, adding the post ID to the args if we do (don't need any API calls for this).
	$args = rfs_maybe_create_property( $args );

	// progress: property prepared
	rfs_set_sync_progress( $args['integration'], $args['property_id'], 1, 6, 'Property prepared' );

	// Realpage doesn't have a property data API call, so we don't need to update any property stuff.

	// Get all the floorplan data for this property.
	rfs_set_sync_progress( $args['integration'], $args['property_id'], 2, 6, 'Fetching floorplans' );
	$floorplans_data = rfs_realpage_get_floorplan_data( $args );

	// We have a floorplans API that returns all of the floorplans for a property, so we can just cycle through those.
	foreach ( $floorplans_data as $floorplan ) {

		// skip if there's no floorplan id.
		if ( ! isset( $floorplan['FloorPlanID'] ) ) {
			continue;
		}

		$floorplan_id         = $floorplan['FloorPlanID'];
		$args['floorplan_id'] = $floorplan_id;

		// now that we have the floorplan ID, we can create that if needed, or just get the post ID if it already exists (returned in $args).
		$args = rfs_maybe_create_floorplan( $args );

		rfs_realpage_update_floorplan_meta( $args, $floorplan );

	}
	
	// progress: fetching units (by property)
	rfs_set_sync_progress( $args['integration'], $args['property_id'], 3, 6, 'Fetching units (by property)' );
	$units_by_property_data = rfs_realpage_get_unit_by_property_data( $args );
	$old_units			  = $units_by_property_data;

	foreach ( $units_by_property_data as $key => $unit_by_property ) {

		// check if $unit_by_property['AvailableBit'] is true. If it is not, then remove this unit from the $units_by_property_data array.
		if ( 'true' !== $unit_by_property['AvailableBit'] ) {
			unset( $units_by_property_data[ $key ] );
			continue;
		}

		if ( ! isset( $unit_by_property['FloorplanID'] ) ) {
			continue;
		}

		$args['floorplan_id'] = intval( $unit_by_property['FloorplanID'] );

		// skip if there's no unit id.
		if ( ! isset( $unit_by_property['UnitNumber'] ) ) {
			continue;
		}

		$unit_by_property_id   = $unit_by_property['UnitNumber'];
		$args['unit_id']       = $unit_by_property_id;

		$args = rfs_maybe_create_unit( $args );

		// update the unit meta.
		rfs_realpage_update_unit_by_property_meta( $args, $unit_by_property );

	}
	
	// progress: fetching units (list)
	rfs_set_sync_progress( $args['integration'], $args['property_id'], 4, 6, 'Fetching units (list)' );
	$units_list_data = rfs_realpage_get_unit_list_data( $args );

	foreach ( $units_list_data as $key => $unit ) {
		
		// check if $unit_by_property['AvailableBit'] is true. If it is not, then remove this unit from the $units_by_property_data array.
		if ( 'true' !== $unit['Availability']['AvailableBit'] ) {
			unset( $unit[ $key ] );
			continue;
		}

		if ( ! isset( $unit['FloorPlan']['FloorPlanID'] ) ) {
			continue;
		}

		$args['floorplan_id'] = intval( $unit['FloorPlan']['FloorPlanID'] );

		// skip if there's no unit id.
		if ( ! isset( $unit['Address']['UnitNumber'] ) ) {
			continue;
		}

		$unit_id         = $unit['Address']['UnitNumber'];
		$args['unit_id'] = $unit_id;

		$args = rfs_maybe_create_unit( $args );

		// update the unit meta.
		rfs_realpage_update_unit_from_list_meta( $args, $unit );

	}

	// TODO we need to update this without regard to the units_data, because that's going to be coming from two separate API calls. We need to update this 
	// TODO based on the WordPress database alone, I think.
	// progress: updating floorplans & availability
	rfs_set_sync_progress( $args['integration'], $args['property_id'], 5, 6, 'Updating floorplans & availability' );
	rfs_realpage_update_floorplan_availability_from_units( $args, $units_by_property_data );

	// TODO make sure that this functionality still works right with two APIs
	// progress: updating pricing & finalizing
	rfs_set_sync_progress( $args['integration'], $args['property_id'], 6, 6, 'Finalizing updates' );
	rfs_realpage_update_floorplan_pricing_from_units( $args );
	
	// TODO we need to use the GetUnitsByProperty API call do do this, because the List API call isn't pulling all of them.
	// we now need to remove any units that are in WordPress showing an API connection to 'realpage' but aren't in the list of units.
	rfs_realpage_remove_units_not_in_api( $args, $units_by_property_data );
}

/**
 * Get the floorplan data
 *
 * @param   array $args the arguments, including the property ID and the credentials.
 *
 * @return  array the floorplan data.
 */
function rfs_realpage_get_floorplan_data( $args ) {

	$realpage_user    = $args['credentials']['realpage']['user'];
	$realpage_pass    = $args['credentials']['realpage']['password'];
	$realpage_pmc_id  = $args['credentials']['realpage']['pmc_id'];
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

	curl_setopt_array(
		$curl,
		array(
			CURLOPT_URL            => 'https://Onesite.RealPage.com/WebServices/CrossFire/AvailabilityAndPricing/Floorplan.asmx',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => $xml,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/soap+xml; charset=utf-8',
			),
		)
	);

	$response = curl_exec( $curl );

	// SimpleXML seems to have problems with the colon ":" in the <xxx:yyy> response tags, so take them out.
	$xml             = preg_replace( '/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $response );
	$xml             = simplexml_load_string( $xml );
	$json            = json_encode( $xml );
	$response_array  = json_decode( $json, true );
	$floorplans_data = $response_array['soapBody']['ListResponse']['ListResult']['FloorPlanObject'];

	return $floorplans_data;
}

/**
 * Get the unit list data (this API call gets all of the units for all dates, but doesn't get the RentMatrix data).
 *
 * @param   array $args the arguments, including the property ID and the credentials.
 *
 * @return array the unit data.
 */
function rfs_realpage_get_unit_by_property_data( $args ) {

	$realpage_user    = $args['credentials']['realpage']['user'];
	$realpage_pass    = $args['credentials']['realpage']['password'];
	$realpage_pmc_id  = $args['credentials']['realpage']['pmc_id'];
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
				<GetUnitsByProperty xmlns="http://realpage.com/webservices">
				<!-- <listCriteria>
					<ListCriterion>
					<Name>string</Name>
					<SingleValue>string</SingleValue>
					<MinValue>string</MinValue>
					<MaxValue>string</MaxValue>
					</ListCriterion>
					<ListCriterion>
					<Name>string</Name>
					<SingleValue>string</SingleValue>
					<MinValue>string</MinValue>
					<MaxValue>string</MaxValue>
					</ListCriterion>
				</listCriteria> -->
				</GetUnitsByProperty>
			</soap12:Body>
		</soap12:Envelope>',
		$realpage_user,
		$realpage_pass,
		$realpage_site_id,
		$realpage_pmc_id,
	);

	curl_setopt_array(
		$curl,
		array(
			CURLOPT_URL            => 'https://onesite.realpage.com/WebServices/CrossFire/AvailabilityAndPricing/Unit.asmx',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => $xml,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/soap+xml; charset=utf-8',
			),
		)
	);

	$response = curl_exec( $curl );

	// SimpleXML seems to have problems with the colon ":" in the <xxx:yyy> response tags, so take them out.
	$xml            = preg_replace( '/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $response );
	$xml            = simplexml_load_string( $xml );
	$json           = json_encode( $xml );
	$response_array = json_decode( $json, true );
	$units_data     = $response_array['soapBody']['GetUnitsByPropertyResponse']['GetUnitsByPropertyResult']['UnitObject'];

	// the API returns a single unit as an array, but multiple units as an array of arrays, so we need to make sure we're always working with an array of arrays.
	// So if it's giving us a singe unit, turn it into an array of one unit.
	if ( isset( $units_data['PropertyNumberID'] ) ) {
		$units_data = array( $units_data );
	}

	return $units_data;
}

/**
 * Get the unit data
 *
 * @param   array $args the arguments, including the property ID and the credentials.
 *
 * @return array the unit data.
 */
function rfs_realpage_get_unit_list_data( $args ) {

	$realpage_user    = $args['credentials']['realpage']['user'];
	$realpage_pass    = $args['credentials']['realpage']['password'];
	$realpage_pmc_id  = $args['credentials']['realpage']['pmc_id'];
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

	curl_setopt_array(
		$curl,
		array(
			CURLOPT_URL            => 'https://onesite.realpage.com/WebServices/CrossFire/AvailabilityAndPricing/Unit.asmx',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => $xml,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/soap+xml; charset=utf-8',
			),
		)
	);

	$response = curl_exec( $curl );

	// SimpleXML seems to have problems with the colon ":" in the <xxx:yyy> response tags, so take them out.
	$xml            = preg_replace( '/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $response );
	$xml            = simplexml_load_string( $xml );
	$json           = json_encode( $xml );
	$response_array = json_decode( $json, true );
	$units_data     = $response_array['soapBody']['ListResponse']['ListResult']['UnitObject'];

	// the API returns a single unit as an array, but multiple units as an array of arrays, so we need to make sure we're always working with an array of arrays.
	// So if it's giving us a singe unit, turn it into an array of one unit.
	if ( isset( $units_data['PropertyNumberID'] ) ) {
		$units_data = array( $units_data );
	}

	return $units_data;
}
