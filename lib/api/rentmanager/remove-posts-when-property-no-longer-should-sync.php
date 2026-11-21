<?php
/** Rent Manager configured-property cleanup shares the property's execution lock. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rfs_rentmanager_remove_records_that_shouldnt_be_synced_property_deleted( $types = array( 'properties', 'floorplans', 'units' ) ) {
	$properties = get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames' );
	if ( ! is_array( $properties ) || ! isset( $properties[0]['ShortName'] ) || rfs_is_sync_locked() ) {
		return;
	}
	$keep = rfs_get_configured_property_ids( 'rentmanager' );
	$orphans = array();
	$keys = array( 'properties' => 'property_source', 'floorplans' => 'floorplan_source', 'units' => 'unit_source' );
	foreach ( $types as $type ) {
		$posts = get_posts( array(
			'post_type' => $type,
			'posts_per_page' => -1,
			'meta_query' => array( array( 'key' => $keys[ $type ], 'value' => 'rentmanager' ) ),
		) );
		foreach ( $posts as $post ) {
			if ( ! in_array( get_post_meta( $post->ID, 'property_id', true ), $keep, true ) ) {
				$orphans[] = $post;
			}
		}
	}
	rfs_delete_sync_posts( 'rentmanager', $orphans, true );
}

// Keep the existing single-type cleanup entry points for callers outside the plugin.
function rfs_rentmanager_remove_properties_that_shouldnt_be_synced_property_deleted() {
	rfs_rentmanager_remove_records_that_shouldnt_be_synced_property_deleted( array( 'properties' ) );
}
function rfs_rentmanager_remove_floorplans_that_shouldnt_be_synced_property_deleted() {
	rfs_rentmanager_remove_records_that_shouldnt_be_synced_property_deleted( array( 'floorplans' ) );
}
function rfs_rentmanager_remove_units_that_shouldnt_be_synced_property_deleted() {
	rfs_rentmanager_remove_records_that_shouldnt_be_synced_property_deleted( array( 'units' ) );
}
