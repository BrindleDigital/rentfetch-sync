<?php
/**
 * Remove Entrata properties, floorplans, and units that are no longer configured for sync.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_action( 'rfs_entrata_do_delete_orphans', 'rfs_entrata_delete_orphans_properties_floorplans_units', 10, 1 );

/**
 * Remove any synced Entrata data whose property ID is not in the settings list.
 *
 * @param array $entrata_properties_in_settings_box Entrata property IDs currently configured for sync.
 * @return void
 */
function rfs_entrata_delete_orphans_properties_floorplans_units( $entrata_properties_in_settings_box ) {
	if ( ! is_array( $entrata_properties_in_settings_box ) ) {
		return;
	}

	$entrata_properties_in_settings_box = array_values(
		array_filter(
			array_map( 'strval', $entrata_properties_in_settings_box ),
			static function ( $property_id ) {
				return '' !== trim( $property_id );
			}
		)
	);

	rfs_entrata_delete_orphan_posts_for_type(
		'properties',
		'property_source',
		$entrata_properties_in_settings_box
	);

	rfs_entrata_delete_orphan_posts_for_type(
		'floorplans',
		'floorplan_source',
		$entrata_properties_in_settings_box
	);

	rfs_entrata_delete_orphan_posts_for_type(
		'units',
		'unit_source',
		$entrata_properties_in_settings_box
	);
}

/**
 * Delete posts of one synced post type whose property ID is no longer configured.
 *
 * @param string $post_type Post type to clean.
 * @param string $source_meta_key Meta key that stores the integration source for the post type.
 * @param array  $property_ids_to_keep Entrata property IDs currently configured for sync.
 * @return void
 */
function rfs_entrata_delete_orphan_posts_for_type( $post_type, $source_meta_key, $property_ids_to_keep ) {
	$meta_query = array(
		'relation' => 'AND',
		array(
			'key'   => $source_meta_key,
			'value' => 'entrata',
		),
	);

	if ( empty( $property_ids_to_keep ) ) {
		$meta_query[] = array(
			'key'     => 'property_id',
			'compare' => 'EXISTS',
		);
	} else {
		$meta_query[] = array(
			'key'     => 'property_id',
			'value'   => $property_ids_to_keep,
			'compare' => 'NOT IN',
		);
	}

	$posts_to_delete = get_posts(
		array(
			'post_type'      => $post_type,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'meta_query'     => $meta_query,
		)
	);

	foreach ( $posts_to_delete as $post_id ) {
		wp_delete_post( $post_id, true );
	}
}
