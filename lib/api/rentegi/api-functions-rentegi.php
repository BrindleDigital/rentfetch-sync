<?php
/** Complete RentEGI property sync orchestration. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync one configured property's complete RentEGI inventory.
 *
 * @param array $args Sync arguments, including property_id.
 * @return void
 * @throws RuntimeException When the feed cannot be fetched, parsed, or saved.
 */
function rfs_do_rentegi_sync( $args ) {
	if ( ! rfs_rentegi_core_is_compatible() ) {
		throw new RuntimeException( rfs_rentegi_core_update_message() );
	}
	$property_code = (string) ( $args['property_id'] ?? '' );
	if ( ! in_array( $property_code, rfs_rentegi_get_property_codes(), true ) || rfs_is_sync_locked() ) {
		return;
	}

	$borrowed = isset( $args['property_sync_lock'] );
	$lock = $borrowed ? $args['property_sync_lock'] : rfs_acquire_property_sync_lock( 'rentegi', $property_code );
	try {
		if ( $lock !== rfs_property_sync_lock_name( 'rentegi', $property_code ) ) {
			throw new RuntimeException( 'Invalid property execution lock.' );
		}
		rfs_assert_property_sync_lock( 'rentegi', $property_code );
		// Fetch and validate the complete inventory before changing any records.
		$xml   = rfs_rentegi_get_feed( $property_code );
		$error = is_wp_error( $xml ) ? $xml : null;
		if ( ! $error ) {
			try {
				$inventory = rfs_rentegi_parse_feed( $xml, $property_code );
			} catch ( RuntimeException $exception ) {
				$error = new WP_Error( 'rentegi_invalid_xml', $exception->getMessage(), array( 'status' => 200 ) );
			}
		}
		if ( $error ) {
			foreach ( array( 'properties' => 'property', 'floorplans' => 'floorplan', 'units' => 'unit' ) as $type => $singular ) {
				foreach ( rfs_rentegi_existing_posts( $type, $singular, $property_code ) as $post_id ) {
					rfs_rentegi_record_result( $post_id, $error );
				}
			}
			throw new RuntimeException( $error->get_error_message() );
		}
		if ( rfs_is_sync_locked( true ) ) {
			return;
		}

		// Save the property, then its floor plans and their dependent units.
		$property_id = rfs_rentegi_update_property( $inventory );
		$floorplan_posts = rfs_rentegi_update_floorplans( $inventory );
		rfs_rentegi_update_units( $inventory );

		// Only a fully parsed and saved inventory may delete missing provider records.
		rfs_rentegi_retire_missing_records( $inventory );

		// Public availability follows the saved, published units, including zero.
		rfs_rentegi_finalize_floorplan_availability( $inventory, $floorplan_posts );

		// A property is successful only after every dependent record has been saved.
		rfs_rentegi_record_result( $property_id, null, $inventory['property'] );
	} catch ( Throwable $exception ) {
		$error = $error ?? new WP_Error( 'rentegi_sync_failed', $exception->getMessage(), array( 'status' => 0 ) );
		foreach ( rfs_rentegi_existing_posts( 'properties', 'property', $property_code ) as $post_id ) {
			rfs_rentegi_record_result( $post_id, $error );
		}
		throw $exception;
	} finally {
		if ( ! $borrowed ) {
			rfs_release_property_sync_lock( $lock );
		}
	}
}
