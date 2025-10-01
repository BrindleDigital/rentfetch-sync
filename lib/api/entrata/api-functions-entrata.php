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

	// // validate that we're getting legitimate data from the v2 API. If we are, we'll use that data instead of the v1 data.
	// if ( isset( $property_data_v2['properties'][0] ) && is_array( $property_data_v2['properties'][0] ) ) {

	// 	// ! V2 API (preferred)

	// 	// get the data, then update the property post.
	// 	rfs_yardi_v2_update_property_meta( $args, $property_data_v2 );

	// 	// get the property images.
	// 	$property_images_v2 = rfs_yardi_v2_get_property_images( $args );

	// 	// update the images for this property.
	// 	rfs_yardi_v2_update_property_images( $args, $property_images_v2 );

	// 	// add the amenities.
	// 	rfs_yardi_v2_update_property_amenities( $args, $property_data_v2 );
		
	// 	// get the floorplans data for this property.
	// 	$floorplans_data_v2 = rfs_yardi_v2_get_floorplan_data( $args );
				
	// 	// remove availability for floorplans that no longer are found in the API (we don't delete these because Yardi sometimes doesn't show floorplans with zero availability).
	// 	// rfs_remove_availability_orphan_yardi_v2__floorplans( $floorplans_data, $property_data );
		
	// 	// ~ We'll need the floorplan ID to update that.
	// 	foreach ( $floorplans_data_v2 as $floorplan ) {

	// 		// skip if there's no floorplan id.
	// 		if ( ! isset( $floorplan['floorplanId'] ) ) {
	// 			continue;
	// 		}

	// 		$floorplan_id         = $floorplan['floorplanId'];
	// 		$args['floorplan_id'] = $floorplan_id;

	// 		// now that we have the floorplan ID, we can create that if needed, or just get the post ID if it already exists (returned in $args).
	// 		$args = rfs_maybe_create_floorplan( $args );

	// 		// update the floorplan meta (this is basic meta, e.g. beds, baths, etc.)
	// 		rfs_yardi_v2_update_floorplan_meta( $args, $floorplan );
			
	// 	}
		
	// 	//TODO need to remove floorplan availability information for floorplans that are no longer available in the API.
		
	// 	// get the availability data (this should be the units)
	// 	$unit_data_v2 = rfs_yardi_v2_get_unit_data( $args );
		
	// 	// We'll need the unit ID to get the unit information.
	// 	foreach( $unit_data_v2 as $unit ) {
			
	// 		// skip if there's no unit id.
	// 		if ( ! isset( $unit['apartmentId'] ) ) {
	// 			continue;
	// 		}

	// 		$unit_id         = $unit['apartmentId'];
	// 		$args['unit_id'] = $unit_id;
			
	// 		if ( ! isset( $unit['floorplanId'] ) ) {
	// 			continue;
	// 		}
			
	// 		$args['floorplan_id'] = $unit['floorplanId'];

	// 		// now that we have the unit ID, we can create that if needed, or just get the post ID if it already exists (returned in $args).
	// 		$args = rfs_maybe_create_unit( $args );

	// 		// update the unit meta for this property.
	// 		rfs_yardi_v2_update_unit_meta( $args, $unit );

	// 	}
		
	// 	//TODO need to remove units that are no longer available in the API.
		

	// } else {

	// 	// ! V1 API (fallback)
	// 	$property_data = rfs_yardi_get_property_data( $args );

	// 	// get the data, then update the property post.
	// 	rfs_yardi_update_property_meta( $args, $property_data );

	// 	$property_images = rfs_yardi_get_property_images( $args );

	// 	// get the images, then update the images for this property.
	// 	rfs_yardi_update_property_images( $args, $property_images );

	// 	// add the amenities.
	// 	rfs_yardi_update_property_amenities( $args, $property_data );

	// 	// get all the floorplan data for this property.
	// 	$floorplans_data = rfs_yardi_get_floorplan_data( $args );

	// 	// remove availability for floorplans that no longer are found in the API (we don't delete these because Yardi sometimes doesn't show floorplans with zero availability).
	// 	rfs_remove_availability_orphan_yardi_floorplans( $floorplans_data, $property_data );

	// 	// ~ We'll need the floorplan ID to get the availablility information.
	// 	foreach ( $floorplans_data as $floorplan ) {

	// 		// skip if there's no floorplan id.
	// 		if ( ! isset( $floorplan['FloorplanId'] ) ) {
	// 			continue;
	// 		}

	// 		$floorplan_id         = $floorplan['FloorplanId'];
	// 		$args['floorplan_id'] = $floorplan_id;

	// 		// now that we have the floorplan ID, we can create that if needed, or just get the post ID if it already exists (returned in $args).
	// 		$args = rfs_maybe_create_floorplan( $args );

	// 		rfs_yardi_update_floorplan_meta( $args, $floorplan );

	// 		$availability_data = rfs_yardi_get_floorplan_availability( $args );

	// 		rfs_yardi_update_floorplan_availability( $args, $availability_data );

	// 		// Remove the units that aren't in the API for this floorplan.
	// 		rfs_remove_units_no_longer_available( $availability_data, $args );

	// 		// ~ The availability data includes the units, so we can update the units for this floorplan.
	// 		foreach ( $availability_data as $unit ) {

	// 			// skip if there's no floorplan id.
	// 			if ( ! property_exists( $unit, 'ApartmentId' ) || ! $unit->ApartmentId ) { // phpcs:ignore
	// 				continue;
	// 			}

	// 			$unit_id         = $unit->ApartmentId; // phpcs:ignore
	// 			$args['unit_id'] = $unit_id;

	// 			$args = rfs_maybe_create_unit( $args );

	// 			rfs_yardi_update_unit_meta( $args, $unit );

	// 		}
	// 	}
	// }
	
}
