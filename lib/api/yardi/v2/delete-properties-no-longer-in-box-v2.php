<?php
/**
 * Functions to get floorplan data from the Yardi API (v2)
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Remove any orphaned properties, floorplans, and units from WordPress (which aren't in the settings)
 */
add_action( 'rfs_yardi_do_delete_orphans', 'rfs_yardi_delete_orphans_properties_floorplans_units', 10, 1 );
function rfs_yardi_delete_orphans_properties_floorplans_units( $yardi_properties_in_settings_box = null ) {
	if ( rfs_is_sync_locked() ) {
		return;
	}
		
	$yardi_properties_in_settings_box = rfs_get_configured_property_ids( 'yardi' );

	$orphans = array();

	//* Properties
	$property_deletion_query_args = array(
		'post_type' => 'properties',
		'posts_per_page' => -1,
		'post_status' => 'publish',
		'meta_query' => array(
			array(
				'relation' => 'AND',
				array(
					'key' => 'property_source',
					'value' => 'yardi',
				),
				empty( $yardi_properties_in_settings_box )
					? array( 'key' => 'property_id', 'compare' => 'EXISTS' )
					: array( 'key' => 'property_id', 'value' => $yardi_properties_in_settings_box, 'compare' => 'NOT IN' ),
			),
		),
	);
	
	// delete all properties found
	$properties_in_wordpress_not_in_settings = get_posts( $property_deletion_query_args );
	
	$orphans = array_merge( $orphans, $properties_in_wordpress_not_in_settings );
	
	//* Floorplans
	$floorplan_deletion_query_args = array(
		'post_type' => 'floorplans',
		'posts_per_page' => -1,
		'post_status' => 'publish',
		'meta_query' => array(
			array(
				'relation' => 'AND',
				array(
					'key' => 'floorplan_source',
					'value' => 'yardi',
				),
				empty( $yardi_properties_in_settings_box )
					? array( 'key' => 'property_id', 'compare' => 'EXISTS' )
					: array( 'key' => 'property_id', 'value' => $yardi_properties_in_settings_box, 'compare' => 'NOT IN' ),
			),
		),
	);
	
	// delete all floorplans found
	$floorplans_in_wordpress_not_in_settings = get_posts( $floorplan_deletion_query_args );
	
	$orphans = array_merge( $orphans, $floorplans_in_wordpress_not_in_settings );
	
	//* Units
	$unit_deletion_query_args = array(
		'post_type' => 'units',
		'posts_per_page' => -1,
		'post_status' => 'publish',
		'meta_query' => array(
			array(
				'relation' => 'AND',
				array(
					'key' => 'unit_source',
					'value' => 'yardi',
				),
				empty( $yardi_properties_in_settings_box )
					? array( 'key' => 'property_id', 'compare' => 'EXISTS' )
					: array( 'key' => 'property_id', 'value' => $yardi_properties_in_settings_box, 'compare' => 'NOT IN' ),
			),
		),
	);
	
	// delete all units found
	$units_in_wordpress_not_in_settings = get_posts( $unit_deletion_query_args );
	
	$orphans = array_merge( $orphans, $units_in_wordpress_not_in_settings );
	rfs_delete_sync_posts( 'yardi', $orphans, true );
}