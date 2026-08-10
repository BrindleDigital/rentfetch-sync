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
 * Normalize every video or virtual-tour field included in a Yardi payload.
 *
 * @param array $data Yardi property, floorplan, or unit data.
 * @return array[] Normalized tour records.
 */
function rfs_yardi_v2_get_synced_tours( $data ) {
	if ( ! is_array( $data ) ) {
		return array();
	}

	$fields = array(
		'tour360EmbedCode'         => 'tour_360',
		'fpVideoEmbedCode'         => 'video',
		'floorplanVirtualTourUrl'  => 'virtual_tour',
		'unitEmbedVideo'           => 'video',
		'unitVirtualTourUrl'       => 'virtual_tour',
		'propertyVideoEmbedCode'   => 'video',
		'propertyVirtualTourUrl'   => 'virtual_tour',
	);
	$tours  = array();

	foreach ( $fields as $field => $type ) {
		$value = html_entity_decode( trim( (string) ( $data[ $field ] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( ! $value || ! preg_match_all( '~https?://[^\s,"\'<>]+~i', $value, $matches ) ) {
			continue;
		}

		foreach ( $matches[0] as $match ) {
			$url = esc_url_raw( $match, array( 'http', 'https' ) );

			if ( ! $url || isset( $tours[ $url ] ) ) {
				continue;
			}

			$tours[ $url ] = array(
				'url'          => $url,
				'type'         => $type,
				'source'       => 'yardi',
				'source_field' => $field,
			);
		}
	}

	return array_values( $tours );
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

	// perform the API calls to get the data.
	$property_data_v2 = rfs_yardi_v2_get_property_data( $args );

	// get the data, then update the property post.
	rfs_yardi_v2_update_property_meta( $args, $property_data_v2 );

	// get the property images.
	$property_images_v2 = rfs_yardi_v2_get_property_images( $args );

	// update the images for this property.
	rfs_yardi_v2_update_property_images( $args, $property_images_v2 );

	// get the property lease fees.
	$property_lease_fees_v2 = rfs_yardi_v2_get_property_lease_fees( $args, $property_data_v2 );

	// update the lease fees for this property.
	rfs_yardi_v2_update_property_lease_fees( $args, $property_lease_fees_v2 );

	// add the amenities.
	rfs_yardi_v2_update_property_amenities( $args, $property_data_v2 );
	
	// get the floorplans data for this property.
	$floorplans_response_v2 = rfs_yardi_v2_get_floorplan_data( $args );
	$floorplans_data_v2     = isset( $floorplans_response_v2['floorplans'] ) && is_array( $floorplans_response_v2['floorplans'] )
		? $floorplans_response_v2['floorplans']
		: array();
	$unit_data_v2           = rfs_yardi_v2_get_unit_data( $args );

	$floorplans_cleanup_response_v2 = $floorplans_response_v2;
	$floorplans_from_units          = false;

	if ( 204 === (int) ( $floorplans_response_v2['status_code'] ?? 0 ) ) {
		list( $floorplans_response_v2, $floorplans_cleanup_response_v2, $floorplans_from_units ) =
			rfs_yardi_v2_reconcile_floorplan_204_response( $floorplans_response_v2, $unit_data_v2 );
	}

	if (
		is_array( $floorplans_response_v2 )
		&& (
			empty( $floorplans_response_v2['success'] )
			|| 204 === (int) ( $floorplans_response_v2['status_code'] ?? 0 )
			|| ( ! empty( $floorplans_response_v2['delete_safe'] ) && empty( $floorplans_data_v2 ) )
		)
	) {
		rfs_yardi_v2_update_property_floorplans_api_response( $args, $floorplans_response_v2 );
	}
	
	// delete floorplans that are no longer in the API at all.
	rfs_yardi_v2_delete_orphan_floorplans( $args, $floorplans_cleanup_response_v2 );
	
	// delete orphan units (orphaned by their floorplans being deleted).
	rfs_yardi_v2_delete_orphan_units( $args, $floorplans_cleanup_response_v2 );
						
	// ~ We'll need the floorplan ID to update that.
	if ( is_array( $floorplans_data_v2 ) ) {
		foreach ( $floorplans_data_v2 as $floorplan ) {
			
			// pause on $floorplan['floorplanId'] if it's '5537054'
			if ( $floorplan['floorplanId'] === '5537054' ) {
				// Do nothing, just a breakpoint.
			}

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
	}
			
	if ( $unit_data_v2 === '304' ) {
		
		rfs_yardi_v2_handle_304_response_for_units( $args );
		
	} elseif ( is_array( $unit_data_v2 ) ) {
		
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

			if ( $floorplans_from_units ) {
				$args['floorplan_name'] = $unit['floorplanName'] ?? $unit['floorplanId'];
				$args                   = rfs_maybe_create_floorplan( $args );
				unset( $args['floorplan_name'] );
			}

			// now that we have the unit ID, we can create that if needed, or just get the post ID if it already exists (returned in $args).
			$args = rfs_maybe_create_unit( $args );

			// update the unit meta for this property.
			rfs_yardi_v2_update_unit_meta( $args, $unit );

		}
		
		// Remove all units that don't specifically exist in the API response.
		rfs_yardi_v2_check_each_unit_and_delete_if_not_still_in_api( $unit_data_v2, $args );

	}
	
	// // update the number of available units for each floorplan, because the floorplans API sometimes lies to us and doesn't give us the right number.
	// rfs_yardi_v2_update_floorplan_units_available_number( $args, $floorplans_data_v2 );
		
	// // remove availability for floorplans that no longer are found in the API (we don't delete these because Yardi sometimes doesn't show floorplans with zero availability).
	// rfs_yardi_v2_remove_availability_orphan_floorplans( $args, $floorplans_data_v2 );
	
	
	// else {

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

/**
 * Reconcile an empty Yardi floorplan response with apartment availability.
 *
 * @param array $floorplans_response_v2 Structured floorplan response.
 * @param mixed $unit_data_v2 Apartment availability data.
 * @return array Updated response, cleanup response, and whether floorplans came from units.
 */
function rfs_yardi_v2_reconcile_floorplan_204_response( $floorplans_response_v2, $unit_data_v2 ) {
	$floorplans_cleanup_response_v2 = $floorplans_response_v2;
	$floorplans_from_units          = false;

	if ( ! empty( $unit_data_v2 ) && is_array( $unit_data_v2 ) ) {
		$unit_floorplan_ids = array();

		foreach ( $unit_data_v2 as $unit ) {
			if ( is_array( $unit ) && ! empty( $unit['floorplanId'] ) ) {
				$unit_floorplan_ids[] = (string) $unit['floorplanId'];
			}
		}

		$unit_floorplan_ids = array_values( array_unique( $unit_floorplan_ids ) );

		if ( $unit_floorplan_ids ) {
			$floorplans_cleanup_response_v2['floorplans'] = array_map(
				static function( $floorplan_id ) {
					return array( 'floorplanId' => $floorplan_id );
				},
				$unit_floorplan_ids
			);
			$floorplans_response_v2['reason'] = 'blank_response_with_available_units';
			$floorplans_from_units            = true;
		} else {
			$floorplans_cleanup_response_v2['delete_safe'] = false;
			$floorplans_response_v2['delete_safe']         = false;
			$floorplans_response_v2['reason']              = 'blank_response_with_unusable_units';
		}
	} elseif ( ! is_array( $unit_data_v2 ) ) {
		$floorplans_cleanup_response_v2['delete_safe'] = false;
		$floorplans_response_v2['delete_safe']         = false;
		$floorplans_response_v2['reason']              = 'blank_response_with_unconfirmed_units';
	}

	return array( $floorplans_response_v2, $floorplans_cleanup_response_v2, $floorplans_from_units );
}
