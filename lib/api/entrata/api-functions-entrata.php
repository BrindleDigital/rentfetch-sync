<?php
/**
 * The Entrata API sync process, which includes the v1 and v2 API calls.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Do the Yardi sync process.
 *
 * @param   array $args  Necessary credentials and property ID to start syncing in an organized way.
 *
 * @return  void.
 */
function rfs_do_entrata_sync( $args ) {

	// ~ With just the property ID, we can get property data, property images, and the floorplan data.
	// create a new post if needed, adding the post ID to the args if we do (don't need any API calls for this)
	$args = rfs_maybe_create_property( $args );

	//* Main property API (most property data)
	
	// perform the API calls to get the basic data for the property
	$property_data = rfs_entrata_get_property_data( $args );
			
	rfs_entrata_update_property_meta( $args, $property_data );
	
	//* getMitsPropertyUnits API is the only way to get lat/long data and property images
	
	$property_mits_data = rfs_entrata_get_property_mits_data( $args );
	
	rfs_entrata_update_property_mits_meta( $args, $property_mits_data );
	
	// TODO property amenities
	
	//* Get the unit data for this property.
	
	$unit_data = rfs_entrata_get_unit_data( $args );
	if ( is_array( $unit_data ) && isset( $unit_data['response']['result']['ILS_Units']['Unit'] ) ) {
		$units = $unit_data['response']['result']['ILS_Units']['Unit'];
	} else {
		$units = [];
	}
	
	foreach( $units as $unit ) {
		
		// skip if there's no unit id.
		if ( ! isset( $unit['@attributes']['PropertyUnitId'] ) ) {
			continue;
		}
		
		$property_id          = $unit['@attributes']['PropertyId'];
		$args['property_id']  = $property_id;
		
		$floorplan_id         = $unit['@attributes']['FloorplanId'];
		$args['floorplan_id'] = $floorplan_id;

		$unit_id              = $unit['@attributes']['PropertyUnitId'];
		$args['unit_id']      = $unit_id;

		// now that we have the unit ID, we can create that if needed, or just get the post ID if it already exists (returned in $args).
		$args = rfs_maybe_create_unit( $args );
		
		// update the unit meta (this is basic meta, e.g. beds, baths, etc.)
		rfs_entrata_update_unit_meta( $args, $unit, $property_mits_data );
	}
	
	rfs_entrata_remove_units_no_longer_available( $args, $units );
	
	// reset the floorplan_id and unit_id
	$args['floorplan_id'] = null;
	$args['unit_id']      = null;
	
	//* Get the floorplan data for this property.
	
	$floorplan_data = rfs_entrata_get_floorplan_data( $args );
	if ( is_array( $floorplan_data ) && isset( $floorplan_data['response']['result']['FloorPlans']['FloorPlan'] ) ) {
		$floorplans = $floorplan_data['response']['result']['FloorPlans']['FloorPlan'];
	} else {
		$floorplans = [];
	}
	
	//* Update the floorplans.
	
	foreach( $floorplans as $floorplan ) {
		
		// skip if there's no floorplan id.
		if ( ! isset( $floorplan['Identification']['IDValue'] ) ) { // TODO UPDATE THE NAME OF THIS
			continue;
		}

		$floorplan_id         = $floorplan['Identification']['IDValue']; // TODO UPDATE THE NAME OF THIS
		$args['floorplan_id'] = $floorplan_id; // TODO UPDATE THE NAME OF THIS

		// now that we have the floorplan ID, we can create that if needed, or just get the post ID if it already exists (returned in $args).
		$args = rfs_maybe_create_floorplan( $args );

		// update the floorplan meta (this is basic meta, e.g. beds, baths, etc.)
		rfs_entrata_update_floorplan_meta( $args, $floorplan, $units );
		
	}
	
	rfs_entrata_remove_floorplans_no_longer_in_api( $args, $floorplans );
	
}
