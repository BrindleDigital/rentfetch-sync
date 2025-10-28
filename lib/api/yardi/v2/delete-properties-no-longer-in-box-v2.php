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
function rfs_yardi_delete_orphans_properties_floorplans_units( $yardi_properties_in_settings_box ) {
		
	if ( !is_array( $yardi_properties_in_settings_box ) )
		return;
	
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
				array(
					'key'   => 'property_id',
					'value' => $yardi_properties_in_settings_box,
					'compare' => 'NOT IN',
				),
			),
		),
	);
	
	// delete all properties found
	$properties_in_wordpress_not_in_settings = get_posts( $property_deletion_query_args );
	
	foreach ( $properties_in_wordpress_not_in_settings as $post_in_wordpress ) {
		wp_delete_post( $post_in_wordpress->ID, true );
	}
	
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
				array(
					'key'   => 'property_id',
					'value' => $yardi_properties_in_settings_box,
					'compare' => 'NOT IN',
				),
			),
		),
	);
	
	// delete all floorplans found
	$floorplans_in_wordpress_not_in_settings = get_posts( $floorplan_deletion_query_args );
	
	foreach ( $floorplans_in_wordpress_not_in_settings as $post_in_wordpress ) {
		wp_delete_post( $post_in_wordpress->ID, true );
	}
	
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
				array(
					'key'   => 'property_id',
					'value' => $yardi_properties_in_settings_box,
					'compare' => 'NOT IN',
				),
			),
		),
	);
	
	// delete all units found
	$units_in_wordpress_not_in_settings = get_posts( $unit_deletion_query_args );
	
	foreach ( $units_in_wordpress_not_in_settings as $post_in_wordpress ) {
		wp_delete_post( $post_in_wordpress->ID, true );
	}
	
}