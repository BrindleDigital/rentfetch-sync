<?php
/** Save RentEGI floor plans and finalize availability from published units. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rfs_rentegi_update_floorplans( $inventory ) {
	$property_code = $inventory['property']['id'];
	$posts         = array();
	foreach ( $inventory['floorplans'] as $plan ) {
		// Keep reported availability in diagnostics; publish the saved-unit count last.
		unset( $plan['meta']['available_units'] );
		$posts[ $plan['id'] ] = rfs_rentegi_upsert( 'floorplans', 'floorplan', $property_code, $plan );
	}
	return $posts;
}

/** Finalize after saves and retirement so stale or leased units cannot inflate counts. */
function rfs_rentegi_finalize_floorplan_availability( $inventory, $floorplan_posts ) {
	$property_code = $inventory['property']['id'];
	foreach ( $inventory['floorplans'] as $plan ) {
		rfs_assert_property_sync_lock( 'rentegi', $property_code );
		$post_id = $floorplan_posts[ $plan['id'] ];
		$args = array(
			'post_type' => 'units', 'post_status' => 'publish', 'posts_per_page' => -1,
			'fields' => 'ids', 'no_found_rows' => true,
			'meta_query' => array(
				array( 'key' => 'unit_source', 'value' => 'rentegi' ),
				array( 'key' => 'property_id', 'value' => $property_code ),
				array( 'key' => 'floorplan_id', 'value' => $plan['id'] ),
			),
		);
		if ( function_exists( 'rentfetch_get_floorplan_unit_query_args' ) ) {
			unset( $args['meta_query'] );
			$args = rentfetch_get_floorplan_unit_query_args( $post_id, $args );
		}
		$unit_ids = get_posts( $args );
		$dates = array();
		$provider_count = 0;
		foreach ( $unit_ids as $unit_id ) {
			$source = (string) get_post_meta( $unit_id, 'unit_source', true );
			$source = '' !== $source ? $source : (string) get_post_meta( $unit_id, 'floorplan_source', true );
			if ( 'rentegi' === $source ) { ++$provider_count; }
			$date = get_post_meta( $unit_id, 'availability_date', true );
			$timestamp = $date ? strtotime( $date ) : false;
			if ( false !== $timestamp ) { $dates[] = gmdate( 'Ymd', $timestamp ); }
		}
		$plan['meta']['available_units'] = count( $unit_ids );
		$plan['meta']['availability_date'] = $dates ? min( $dates ) : '';
		$plan['source_context']['published_provider_units'] = $provider_count;
		$plan['source_context']['published_manual_units'] = count( $unit_ids ) - $provider_count;
		if ( $plan['source_context']['reported_available_units'] !== $provider_count ) {
			$plan['warnings'][] = 'Reported availability differs from published provider units; public availability uses published units.';
		}
		$plan['warnings'] = array_values( array_unique( $plan['warnings'] ?? array() ) );
		try {
			rfs_rentegi_update_meta( $post_id, 'available_units', $plan['meta']['available_units'] );
			rfs_rentegi_update_meta( $post_id, 'availability_date', $plan['meta']['availability_date'] );
			rfs_rentegi_record_result( $post_id, null, $plan );
		} catch ( Throwable $exception ) {
			rfs_rentegi_record_result( $post_id, new WP_Error( 'rentegi_availability_save_failed', $exception->getMessage() ), $plan );
			throw $exception;
		}
	}
}
