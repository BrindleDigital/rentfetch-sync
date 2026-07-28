<?php
/**
 * Complete Engrain property sync orchestration.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run the complete Engrain sync for one configured asset.
 *
 * This is the Engrain roadmap, analogous to rfs_do_yardi_sync(). It keeps the
 * fetch/update sequence visible while endpoint-specific assembly lives in the
 * named helper functions below.
 *
 * @param array $args Runtime sync args.
 * @return void
 * @throws RuntimeException When a required inventory endpoint fails.
 */
function rfs_do_engrain_sync( $args ) {
	// Validate the requested asset.
	$property_id = isset( $args['property_id'] ) ? trim( (string) $args['property_id'] ) : '';
	if ( '' === $property_id ) {
		return;
	}

	$asset_response = rfs_engrain_get_asset( $args );

	// Do not create placeholder content for an invalid/inaccessible asset.
	if (
		empty( $asset_response['success'] )
		|| empty( $asset_response['data']['id'] )
		|| (string) $asset_response['data']['id'] !== $property_id
	) {
		throw new RuntimeException(
			esc_html( 'Engrain asset request failed: ' . ( $asset_response['reason'] ?? 'unknown error' ) )
		);
	}

	$args = rfs_maybe_create_property( $args );
	if ( empty( $args['wordpress_property_post_id'] ) ) {
		throw new RuntimeException( 'Unable to create or locate the Engrain property post.' );
	}

	// Update the property post.
	rfs_engrain_update_property( $args, $asset_response );

	// Get the authoritative floorplan and unit inventory.
	$inventory = rfs_engrain_get_inventory_sync_data( $args, $property_id );

	// Get process enrichment, then authoritative All-In rent and availability.
	$pricing_contexts = rfs_engrain_get_pricing_contexts_for_sync( $args );
	$pricing_contexts = rfs_engrain_add_all_in_pricing_to_contexts( $args, $pricing_contexts );

	// Get and update the property image gallery.
	$galleries_response = rfs_engrain_get_multifamily_list( $args, 'galleries', 'gallery-resource' );
	rfs_engrain_update_property_galleries( $args, $galleries_response );

	// Get unit amenities and update property amenity terms.
	$unit_description_contexts    = rfs_engrain_get_unit_description_contexts_for_sync( $args );
	$marker_descriptions_response = rfs_engrain_get_multifamily_list(
		$args,
		'marker-descriptions',
		'marker-descriptions-resource'
	);
	rfs_engrain_update_property_marker_descriptions( $args, $marker_descriptions_response );

	// Create or update floorplans first, then their dependent units.
	rfs_engrain_update_floorplan_posts( $args, $inventory, $pricing_contexts );
	rfs_engrain_update_unit_posts(
		$args,
		$inventory,
		$pricing_contexts,
		$unit_description_contexts
	);

	// Get and update expenses, deposits, and pricing disclaimers.
	$expenses = rfs_engrain_get_expense_sync_data( $args );
	rfs_engrain_update_expenses(
		$args,
		$expenses,
		$inventory['floorplans_by_id'],
		$inventory['units_by_id']
	);

	if ( empty( $inventory['response']['success'] ) ) {
		throw new RuntimeException( 'Engrain inventory sync was incomplete. Existing inventory was preserved.' );
	}
}
