<?php
/** Delete unavailable or missing provider records after successful inventory saves. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rfs_rentegi_retire_missing_records( $inventory ) {
	$property_code = $inventory['property']['id'];
	foreach ( array( 'floorplans' => 'floorplan', 'units' => 'unit' ) as $type => $singular ) {
		foreach ( rfs_rentegi_existing_posts( $type, $singular, $property_code ) as $post_id ) {
			rfs_assert_property_sync_lock( 'rentegi', $property_code );
			$id = (string) get_post_meta( $post_id, $singular . '_id', true );
			// RentEGI supplies complete inventory while other APIs do not; follow the
			// same deletion policy for unavailable/missing provider records. Manual units stay.
			$record = $inventory[ $type ][ $id ] ?? null;
			if ( null === $record || ( 'units' === $type && ! $record['available'] ) ) {
				$result = wp_delete_post( $post_id, true );
				if ( is_wp_error( $result ) || ! $result ) {
					throw new RuntimeException( 'Unable to delete an old RentEGI record.' );
				}
			}
		}
	}
}
