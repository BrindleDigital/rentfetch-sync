<?php
/**
 * The Yardi API sync process, which includes the v1 and v2 API calls.
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
function rfs_do_yardi_sync( $args ) {

	// ~ With just the property ID, we can get property data, property images, and the floorplan data.
	// create a new post if needed, adding the post ID to the args if we do (don't need any API calls for this)
	$args = rfs_maybe_create_property( $args );
	// step 1: property record ensured
	rfs_set_sync_progress( $args['integration'], $args['property_id'], 1, 6, 'Property record ready' );

	// perform the API calls to get the data.
	// step 2: fetching property data
	rfs_set_sync_progress( $args['integration'], $args['property_id'], 2, 6, 'Fetching property data' );
	$property_data_v2 = rfs_yardi_v2_get_property_data( $args );

	// validate that we're getting legitimate data from the v2 API. If we are, we'll use that data instead of the v1 data.
	if ( isset( $property_data_v2['properties'][0] ) && is_array( $property_data_v2['properties'][0] ) ) {

		// ! V2 API (preferred)

		// get the data, then update the property post.
		rfs_yardi_v2_update_property_meta( $args, $property_data_v2 );
		// step 3: property meta updated
		rfs_set_sync_progress( $args['integration'], $args['property_id'], 3, 6, 'Property meta updated' );

		// get the property images.
		// step 4: fetching images
		rfs_set_sync_progress( $args['integration'], $args['property_id'], 4, 6, 'Fetching property images' );
		$property_images_v2 = rfs_yardi_v2_get_property_images( $args );

		// update the images for this property.
		rfs_yardi_v2_update_property_images( $args, $property_images_v2 );
		// step 5: images updated
		rfs_set_sync_progress( $args['integration'], $args['property_id'], 5, 6, 'Property images updated' );

		// add the amenities.
		rfs_yardi_v2_update_property_amenities( $args, $property_data_v2 );
		
		// get the floorplans data for this property.
		// step 6: fetching floorplans and units
		rfs_set_sync_progress( $args['integration'], $args['property_id'], 6, 6, 'Fetching floorplans and units' );
		$floorplans_data_v2 = rfs_yardi_v2_get_floorplan_data( $args );
		
		// get the availability data (this should be the units), which we'll need both for the floorplan and the unit.
		$unit_data_v2 = rfs_yardi_v2_get_unit_data( $args );
						
		// ~ We'll need the floorplan ID to update that.
		foreach ( $floorplans_data_v2 as $floorplan ) {

			// skip if there's no floorplan id.
			if ( ! isset( $floorplan['floorplanId'] ) ) {
				continue;
			}

			$floorplan_id         = $floorplan['floorplanId'];
			$args['floorplan_id'] = $floorplan_id;

			// now that we have the floorplan ID, we can create that if needed, or just get the post ID if it already exists (returned in $args).
			$args = rfs_maybe_create_floorplan( $args );

			// update the floorplan meta (this is basic meta, e.g. beds, baths, etc.)
			rfs_yardi_v2_update_floorplan_meta( $args, $floorplan, $unit_data_v2 );
			
		}
				
		if ( $unit_data_v2 && is_array( $unit_data_v2 ) ) {
			
			// We'll need the unit ID to get the unit information.
			foreach( $unit_data_v2 as $unit ) {
				
				// skip if there's no unit id.
				if ( ! isset( $unit['apartmentId'] ) ) {
					continue;
				}

				$unit_id         = $unit['apartmentId'];
				$args['unit_id'] = $unit_id;
				
				if ( ! isset( $unit['floorplanId'] ) ) {
					continue;
				}
				
				$args['floorplan_id'] = $unit['floorplanId'];

				// now that we have the unit ID, we can create that if needed, or just get the post ID if it already exists (returned in $args).
				$args = rfs_maybe_create_unit( $args );

				// update the unit meta for this property.
				rfs_yardi_v2_update_unit_meta( $args, $unit );

			}

			// Remove the units that aren't in the API for this property.
			rfs_yardi_v2_remove_orphan_units( $unit_data_v2, $args );

		}
		
		// remove availability for floorplans that no longer are found in the API (we don't delete these because Yardi sometimes doesn't show floorplans with zero availability).
		rfs_yardi_v2_remove_availability_orphan_floorplans( $args, $floorplans_data_v2 );

	} else {

		// ! V1 API (fallback)
		$property_data = rfs_yardi_get_property_data( $args );

		// step 2: fetching property data (v1)
		rfs_set_sync_progress( $args['integration'], $args['property_id'], 2, 6, 'Fetching property data (v1)' );

		// get the data, then update the property post.
		rfs_yardi_update_property_meta( $args, $property_data );

		// step 3: property meta updated (v1)
		rfs_set_sync_progress( $args['integration'], $args['property_id'], 3, 6, 'Property meta updated (v1)' );

		$property_images = rfs_yardi_get_property_images( $args );

		// step 4: fetching images (v1)
		rfs_set_sync_progress( $args['integration'], $args['property_id'], 4, 6, 'Fetching property images (v1)' );

		// get the images, then update the images for this property.
		rfs_yardi_update_property_images( $args, $property_images );

		// step 5: images updated (v1)
		rfs_set_sync_progress( $args['integration'], $args['property_id'], 5, 6, 'Property images updated (v1)' );

		// add the amenities.
		rfs_yardi_update_property_amenities( $args, $property_data );

		// get all the floorplan data for this property.
		// step 6: fetching floorplans and units (v1)
		rfs_set_sync_progress( $args['integration'], $args['property_id'], 6, 6, 'Fetching floorplans and units (v1)' );
		$floorplans_data = rfs_yardi_get_floorplan_data( $args );

		// remove availability for floorplans that no longer are found in the API (we don't delete these because Yardi sometimes doesn't show floorplans with zero availability).
		rfs_remove_availability_orphan_yardi_floorplans( $floorplans_data, $property_data );

		// ~ We'll need the floorplan ID to get the availablility information.
		foreach ( $floorplans_data as $floorplan ) {

			// skip if there's no floorplan id.
			if ( ! isset( $floorplan['FloorplanId'] ) ) {
				continue;
			}

			$floorplan_id         = $floorplan['FloorplanId'];
			$args['floorplan_id'] = $floorplan_id;

			// now that we have the floorplan ID, we can create that if needed, or just get the post ID if it already exists (returned in $args).
			$args = rfs_maybe_create_floorplan( $args );

			rfs_yardi_update_floorplan_meta( $args, $floorplan );

			$availability_data = rfs_yardi_get_floorplan_availability( $args );

			rfs_yardi_update_floorplan_availability( $args, $availability_data );

			// Remove the units that aren't in the API for this floorplan.
			rfs_remove_units_no_longer_available( $availability_data, $args );

			// ~ The availability data includes the units, so we can update the units for this floorplan.
			foreach ( $availability_data as $unit ) {

				// skip if there's no floorplan id.
				if ( ! property_exists( $unit, 'ApartmentId' ) || ! $unit->ApartmentId ) { // phpcs:ignore
					continue;
				}

				$unit_id         = $unit->ApartmentId; // phpcs:ignore
				$args['unit_id'] = $unit_id;

				$args = rfs_maybe_create_unit( $args );

				rfs_yardi_update_unit_meta( $args, $unit );

			}
		}
	}
	
}
