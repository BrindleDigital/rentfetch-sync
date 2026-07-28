<?php
/**
 * Supporting helpers for the Engrain sync roadmap.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch and validate the structural inventory for an asset.
 *
 * @param array  $args Runtime sync args.
 * @param string $property_id Engrain asset ID.
 * @return array
 */
function rfs_engrain_get_inventory_sync_data( $args, $property_id ) {
	$floorplans_response = rfs_engrain_validate_inventory_response(
		rfs_engrain_get_multifamily_list( $args, 'floor-plans' ),
		$property_id,
		'floorplan'
	);
	$units_response      = rfs_engrain_validate_inventory_response(
		rfs_engrain_get_multifamily_list( $args, 'units' ),
		$property_id,
		'unit'
	);

	$inventory_response = rfs_engrain_combine_responses(
		array(
			'floorplans' => $floorplans_response,
			'units'      => $units_response,
		),
		'valid_inventory'
	);
	rfs_engrain_record_api_response(
		$args['wordpress_property_post_id'],
		'engrain_inventory_api',
		$inventory_response,
		array(
			'floorplan_count' => ! empty( $floorplans_response['success'] ) ? count( $floorplans_response['data'] ) : null,
			'unit_count'      => ! empty( $units_response['success'] ) ? count( $units_response['data'] ) : null,
		)
	);

	$floorplans = ! empty( $floorplans_response['success'] ) ? $floorplans_response['data'] : array();
	$units      = ! empty( $units_response['success'] ) ? $units_response['data'] : array();

	return array(
		'response'            => $inventory_response,
		'floorplans_response' => $floorplans_response,
		'units_response'      => $units_response,
		'floorplans'          => $floorplans,
		'units'               => $units,
		'floorplans_by_id'    => rfs_engrain_index_rows_by_id( $floorplans ),
		'units_by_id'         => rfs_engrain_index_rows_by_id( $units ),
	);
}

/**
 * Return a successful empty response for optional pricing endpoints.
 *
 * @return array
 */
function rfs_engrain_get_empty_pricing_response() {
	return array(
		'success'       => true,
		'delete_safe'   => true,
		'data'          => array(),
		'reason'        => 'valid_empty_pricing_data',
		'status_code'   => 200,
		'error_message' => '',
	);
}

/**
 * Fetch unit pricing and build normalized unit pricing contexts.
 *
 * @param array $args Runtime sync args.
 * @return array
 */
function rfs_engrain_get_pricing_contexts_for_sync( $args ) {
	$pricing_processes_response = rfs_engrain_get_pricing_processes( $args );
	$pricing_process            = ! empty( $pricing_processes_response['success'] )
		? rfs_engrain_select_pricing_process( $pricing_processes_response['data'] )
		: null;
	$pricing_process_id         = is_array( $pricing_process ) ? (string) $pricing_process['id'] : '';
	$empty_pricing_response     = rfs_engrain_get_empty_pricing_response();

	if ( '' !== $pricing_process_id ) {
		$pricing_units_response   = rfs_engrain_get_pricing_units( $args, $pricing_process_id );
		$pricing_entries_response = rfs_engrain_get_pricing_entries( $args, $pricing_process_id );
	} elseif ( ! empty( $pricing_processes_response['success'] ) ) {
		$pricing_units_response   = $empty_pricing_response;
		$pricing_entries_response = $empty_pricing_response;
	} else {
		$pricing_units_response   = rfs_engrain_failed_response(
			'pricing_processes_unavailable',
			$pricing_processes_response['status_code'] ?? 0
		);
		$pricing_entries_response = $pricing_units_response;
	}

	$pricing_response = rfs_engrain_combine_responses(
		array(
			'processes' => $pricing_processes_response,
			'units'     => $pricing_units_response,
			'entries'   => $pricing_entries_response,
		),
		'valid_pricing'
	);
	$pricing_contexts = ! empty( $pricing_units_response['success'] )
		? rfs_engrain_build_unit_pricing_contexts(
			$pricing_units_response['data'],
			! empty( $pricing_entries_response['success'] ) ? $pricing_entries_response['data'] : array()
		)
		: null;

	rfs_engrain_record_api_response(
		$args['wordpress_property_post_id'],
		'engrain_pricing_api',
		$pricing_response,
		array(
			'process_count'       => ! empty( $pricing_processes_response['success'] ) ? count( $pricing_processes_response['data'] ) : null,
			'selected_process_id' => $pricing_process_id,
			'pricing_unit_count'  => ! empty( $pricing_units_response['success'] ) ? count( $pricing_units_response['data'] ) : null,
			'pricing_entry_count' => ! empty( $pricing_entries_response['success'] ) ? count( rfs_engrain_flatten_pricing_entries( $pricing_entries_response['data'] ) ) : null,
			'required_permission' => 'sightmap.pricing.read',
		),
		true
	);

	return $pricing_contexts;
}

/**
 * Fetch All-In Pricing and merge it into unit pricing contexts.
 *
 * @param array      $args Runtime sync args.
 * @param array|null $pricing_contexts Existing normalized pricing contexts.
 * @return array|null
 */
function rfs_engrain_add_all_in_pricing_to_contexts( $args, $pricing_contexts ) {
	$sightmaps_response = rfs_engrain_get_sightmaps( $args );
	$sightmap           = ! empty( $sightmaps_response['success'] )
		? rfs_engrain_select_sightmap( $sightmaps_response['data'] )
		: null;
	$sightmap_id        = is_array( $sightmap ) ? (string) $sightmap['id'] : '';

	if ( '' !== $sightmap_id ) {
		$all_in_pricing_response = rfs_engrain_get_all_in_pricing( $args, $sightmap_id );
	} elseif ( ! empty( $sightmaps_response['success'] ) ) {
		$all_in_pricing_response = rfs_engrain_get_empty_pricing_response();
	} else {
		$all_in_pricing_response = rfs_engrain_failed_response(
			'sightmaps_unavailable',
			$sightmaps_response['status_code'] ?? 0,
			$sightmaps_response['error_message'] ?? ''
		);
	}

	if ( ! empty( $all_in_pricing_response['success'] ) ) {
		$pricing_contexts = rfs_engrain_merge_all_in_pricing_contexts(
			$pricing_contexts,
			$all_in_pricing_response['data']
		);
	}

	$all_in_response = rfs_engrain_combine_responses(
		array(
			'sightmaps' => $sightmaps_response,
			'all_in'    => $all_in_pricing_response,
		),
		'valid_all_in_pricing'
	);
	rfs_engrain_record_api_response(
		$args['wordpress_property_post_id'],
		'engrain_all_in_pricing_api',
		$all_in_response,
		array(
			'sightmap_count'       => ! empty( $sightmaps_response['success'] ) ? count( $sightmaps_response['data'] ) : null,
			'selected_sightmap_id' => $sightmap_id,
			'all_in_unit_count'    => ! empty( $all_in_pricing_response['success'] ) ? count( $all_in_pricing_response['data'] ) : null,
			'required_permissions' => array( 'sightmap.instances.read', 'sightmap.instances.read-pricing' ),
		),
		true
	);

	return $pricing_contexts;
}

/**
 * Fetch unit descriptions and build shared amenity contexts.
 *
 * @param array $args Runtime sync args.
 * @return array|null
 */
function rfs_engrain_get_unit_description_contexts_for_sync( $args ) {
	$unit_descriptions_response = rfs_engrain_get_unit_description_bundle( $args );
	$unit_description_contexts  = ! empty( $unit_descriptions_response['success'] )
		? rfs_engrain_build_unit_description_contexts( $unit_descriptions_response['data'] )
		: null;
	$description_count          = 0;
	$assignment_count           = 0;

	if ( ! empty( $unit_descriptions_response['success'] ) ) {
		foreach ( (array) ( $unit_descriptions_response['data']['descriptions'] ?? array() ) as $description_rows ) {
			$description_count += count( (array) $description_rows );
		}
		foreach ( (array) ( $unit_descriptions_response['data']['assignments'] ?? array() ) as $assignment_rows ) {
			$assignment_count += count( (array) $assignment_rows );
		}
	}

	rfs_engrain_record_api_response(
		$args['wordpress_property_post_id'],
		'engrain_unit_descriptions_api',
		$unit_descriptions_response,
		array(
			'group_count'         => ! empty( $unit_descriptions_response['success'] ) ? count( (array) ( $unit_descriptions_response['data']['groups'] ?? array() ) ) : null,
			'description_count'   => ! empty( $unit_descriptions_response['success'] ) ? $description_count : null,
			'assignment_count'    => ! empty( $unit_descriptions_response['success'] ) ? $assignment_count : null,
			'required_permission' => 'sightmap.unit-descriptions.read',
		),
		true
	);

	return $unit_description_contexts;
}

/**
 * Create or update all floorplan posts from structural inventory.
 *
 * @param array      $args Runtime sync args.
 * @param array      $inventory Structural inventory bundle.
 * @param array|null $pricing_contexts Unit pricing contexts.
 * @return void
 */
function rfs_engrain_update_floorplan_posts( $args, $inventory, $pricing_contexts ) {
	if ( empty( $inventory['floorplans_response']['success'] ) ) {
		return;
	}

	foreach ( $inventory['floorplans'] as $floorplan ) {
		if ( ! is_array( $floorplan ) || empty( $floorplan['id'] ) ) {
			continue;
		}

		$args['floorplan_id'] = (string) $floorplan['id'];
		$args                 = rfs_maybe_create_floorplan( $args );
		if ( ! empty( $args['wordpress_floorplan_post_id'] ) ) {
			rfs_engrain_update_floorplan(
				$args,
				$floorplan,
				! empty( $inventory['units_response']['success'] ) ? $inventory['units'] : null,
				$pricing_contexts
			);
		}
	}

	if ( ! empty( $inventory['floorplans_response']['delete_safe'] ) ) {
		rfs_engrain_delete_orphan_records(
			'floorplans',
			'floorplan_source',
			'floorplan_id',
			$args['property_id'],
			array_keys( $inventory['floorplans_by_id'] )
		);
	}
}

/**
 * Create or update available unit posts from structural inventory.
 *
 * The structural endpoint includes occupied units, so pricing must identify a
 * unit as available before it becomes a WordPress post. A failed/denied pricing
 * response is represented by null; in that case existing unit posts remain
 * untouched and structural-only posts are not created. The first cleanup of
 * the previously imported structural inventory therefore waits for a complete
 * authoritative pricing response.
 *
 * @param array      $args Runtime sync args.
 * @param array      $inventory Structural inventory bundle.
 * @param array|null $pricing_contexts Unit pricing contexts.
 * @param array|null $description_contexts Unit amenity contexts.
 * @return void
 */
function rfs_engrain_update_unit_posts( $args, $inventory, $pricing_contexts, $description_contexts ) {
	if (
		empty( $inventory['floorplans_response']['success'] )
		|| empty( $inventory['units_response']['success'] )
		|| ! is_array( $pricing_contexts )
	) {
		return;
	}

	$synced_unit_ids = array();

	foreach ( $inventory['units'] as $unit ) {
		$unit_id                = is_array( $unit ) && ! empty( $unit['id'] ) ? (string) $unit['id'] : '';
		$unit_pricing_context   = '' !== $unit_id ? ( $pricing_contexts[ $unit_id ] ?? null ) : null;
		$has_known_availability = (
			is_array( $unit_pricing_context )
			&& ! empty( $unit_pricing_context['authoritative'] )
			&& ! empty( $unit_pricing_context['is_available'] )
		);

		if (
			! is_array( $unit )
			|| '' === $unit_id
			|| empty( $unit['floor_plan_id'] )
			|| ! isset( $inventory['floorplans_by_id'][ (string) $unit['floor_plan_id'] ] )
			|| ! $has_known_availability
		) {
			continue;
		}

		$args['unit_id']      = $unit_id;
		$args['floorplan_id'] = (string) $unit['floor_plan_id'];
		$args                 = rfs_maybe_create_unit( $args );

		if ( ! empty( $args['wordpress_unit_post_id'] ) ) {
			$unit_description_context = is_array( $description_contexts )
				? ( $description_contexts[ $unit_id ] ?? rfs_engrain_empty_unit_description_context() )
				: null;
			rfs_engrain_update_unit(
				$args,
				$unit,
				$inventory['floorplans_by_id'][ (string) $unit['floor_plan_id'] ],
				$unit_pricing_context,
				$unit_description_context
			);
			$synced_unit_ids[] = $unit_id;
		}
	}

	if ( ! empty( $inventory['units_response']['delete_safe'] ) ) {
		rfs_engrain_delete_orphan_records(
			'units',
			'unit_source',
			'unit_id',
			$args['property_id'],
			$synced_unit_ids
		);
	}
}

/**
 * Fetch property, floorplan, and unit expense data.
 *
 * @param array $args Runtime sync args.
 * @return array
 */
function rfs_engrain_get_expense_sync_data( $args ) {
	$property_expenses_response  = rfs_engrain_get_multifamily_list( $args, 'expenses', 'expenses' );
	$floorplan_expenses_response = rfs_engrain_get_multifamily_list( $args, 'floor-plans/expenses', 'expenses' );
	$unit_expenses_response      = rfs_engrain_get_multifamily_list( $args, 'units/expenses', 'expenses' );

	$floorplan_entries_response = ! empty( $floorplan_expenses_response['success'] )
		? rfs_engrain_get_expense_entries( $args, $floorplan_expenses_response['data'], 'floor-plans' )
		: rfs_engrain_failed_response( 'floorplan_expenses_unavailable' );
	$unit_entries_response      = ! empty( $unit_expenses_response['success'] )
		? rfs_engrain_get_expense_entries( $args, $unit_expenses_response['data'], 'units' )
		: rfs_engrain_failed_response( 'unit_expenses_unavailable' );

	return array(
		'property_expenses'   => $property_expenses_response,
		'floorplan_expenses'  => $floorplan_expenses_response,
		'floorplan_entries'   => $floorplan_entries_response,
		'unit_expenses'       => $unit_expenses_response,
		'unit_entries'        => $unit_entries_response,
		'pricing_disclaimers' => rfs_engrain_get_multifamily_list(
			$args,
			'pricing-disclaimers',
			'pricing-disclaimers-resource'
		),
	);
}

/**
 * Combine multiple API responses into one diagnostic status.
 *
 * @param array  $responses Responses keyed by label.
 * @param string $success_reason Success reason.
 * @return array
 */
function rfs_engrain_combine_responses( $responses, $success_reason ) {
	$success        = true;
	$data           = array();
	$reasons        = array();
	$status_code    = 200;
	$error_messages = array();

	foreach ( $responses as $key => $response ) {
		if ( empty( $response['success'] ) ) {
			$success = false;
			if ( ! empty( $response['status_code'] ) ) {
				$status_code = (int) $response['status_code'];
			}
			if ( ! empty( $response['error_message'] ) ) {
				$error_messages[] = (string) $response['error_message'];
			}
		}
		$data[ $key ] = isset( $response['data'] ) ? $response['data'] : null;
		$reasons[]    = isset( $response['reason'] ) ? $response['reason'] : 'unknown';
	}

	return array(
		'success'       => $success,
		'delete_safe'   => $success,
		'data'          => $data,
		'reason'        => $success ? $success_reason : 'partial_' . implode( '_', array_map( 'sanitize_key', $reasons ) ),
		'status_code'   => $success ? 200 : $status_code,
		'error_message' => implode( '; ', array_unique( $error_messages ) ),
	);
}

/**
 * Validate IDs and asset ownership before an inventory response can delete data.
 *
 * @param array  $response Structured list response.
 * @param string $asset_id Requested asset ID.
 * @param string $resource_name Resource label for diagnostics.
 * @return array
 */
function rfs_engrain_validate_inventory_response( $response, $asset_id, $resource_name ) {
	if ( empty( $response['success'] ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return $response;
	}

	foreach ( $response['data'] as $row ) {
		if (
			! is_array( $row )
			|| ! isset( $row['id'] )
			|| '' === (string) $row['id']
			|| ! isset( $row['asset_id'] )
			|| (string) $row['asset_id'] !== (string) $asset_id
		) {
			return rfs_engrain_failed_response( 'invalid_' . sanitize_key( $resource_name ) . '_row', $response['status_code'] ?? 0 );
		}
	}

	return $response;
}
