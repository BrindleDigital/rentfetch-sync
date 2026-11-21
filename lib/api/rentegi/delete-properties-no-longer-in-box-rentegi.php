<?php
/** Remove RentEGI content whose property code was removed from sync settings. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delete unconfigured source-owned content, matching Entrata/Engrain cleanup.
 *
 * @return void
 */
function rfs_rentegi_delete_unconfigured_properties() {
	if ( rfs_is_sync_locked() || ! rfs_rentegi_core_is_compatible() ) {
		return;
	}

	// Read current settings so an old queued job cannot delete newly configured properties.
	$configured_codes = rfs_rentegi_get_property_codes();
	$orphan_posts     = array();
	foreach ( array( 'properties' => 'property_source', 'floorplans' => 'floorplan_source', 'units' => 'unit_source' ) as $post_type => $source_key ) {
		$meta_query = array(
			array( 'key' => $source_key, 'value' => 'rentegi' ),
		);
		$meta_query[] = empty( $configured_codes )
			? array( 'key' => 'property_id', 'compare' => 'EXISTS' )
			: array( 'key' => 'property_id', 'value' => $configured_codes, 'compare' => 'NOT IN' );

		$post_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
			)
		);
		foreach ( $post_ids as $post_id ) {
			$property_code = (string) get_post_meta( $post_id, 'property_id', true );
			$orphan_posts[ $property_code ][] = $post_id;
		}
	}

	foreach ( $orphan_posts as $property_code => $post_ids ) {
		$lock = rfs_acquire_property_sync_lock( 'rentegi', (string) $property_code );
		try {
			if ( in_array( (string) $property_code, rfs_rentegi_get_property_codes(), true ) || rfs_is_sync_locked( true ) ) {
				continue;
			}
			foreach ( $post_ids as $post_id ) {
				rfs_assert_property_sync_lock( 'rentegi', (string) $property_code );
				wp_cache_delete( $post_id, 'post_meta' );
				$source_key = array( 'properties' => 'property_source', 'floorplans' => 'floorplan_source', 'units' => 'unit_source' )[ get_post_type( $post_id ) ] ?? '';
				if ( ! $source_key || 'rentegi' !== get_post_meta( $post_id, $source_key, true ) || (string) $property_code !== (string) get_post_meta( $post_id, 'property_id', true ) ) {
					continue;
				}
				if ( ! wp_delete_post( $post_id, true ) ) {
					throw new RuntimeException( 'Unable to delete an unconfigured RentEGI record.' );
				}
			}
		} finally {
			rfs_release_property_sync_lock( $lock );
		}
	}
}
add_action( 'rfs_rentegi_do_delete_orphans', 'rfs_rentegi_delete_unconfigured_properties' );
