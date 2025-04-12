<?php
/**
 * This file sets up the metaboxes for the properties post type
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Register the sync metabox
 * 
 * @return void
 */
function rfs_sync_properties_sync_metabox() {
	
	global $post;
	
	// get the post type of the current post
	$post_type = get_post_type( $post->ID );
	
	switch ( $post_type ) {
		case 'properties':
			
			$property_id = get_post_meta( $post->ID, 'property_id', true );
			$integration = get_post_meta( $post->ID, 'property_source', true );
			$label = 'property';
			$text = 'Perform a one-time sync: property, floorplan, and unit information.';
			
			break;
		case 'floorplans':
			
			$property_id = get_post_meta( $post->ID, 'property_id', true );
			$integration = get_post_meta( $post->ID, 'floorplan_source', true );			
			$label = 'floorplan';
			$text = 'Perform a one-time sync, including everything from the property to which this floorplan belongs (property, floorplan, and unit information).';
			
			break;
		case 'units':
			
			$property_id = get_post_meta( $post->ID, 'property_id', true );
			$integration = get_post_meta( $post->ID, 'unit_source', true );			
			$label = 'unit';
			$text = 'Perform a one-time sync, including everything from the property to which this unit belongs (property, floorplan, and unit information).';
			
			break;
		default:
			return;
	}

	// bail if there's no property id or integration
	if ( !$property_id || !$integration ) {
		return;
	}
	
	add_meta_box(
		'rfs_sync_metabox', // ID of the metabox.
		'Syncing', // Title of the metabox.
		'rfs_sync_metabox', // Callback function to render the metabox.
		array( 'properties', 'floorplans', 'units' ), // Post type to add the metabox to.
		'side', // Context
		'high' // Priority
	);
}
add_action( 'add_meta_boxes', 'rfs_sync_properties_sync_metabox' );

/**
 * Metabox callback function that includes the button
 *
 * @param   object $post The post object.
 *
 * @return  void.
 */
function rfs_sync_metabox( $post ) {
	
	wp_enqueue_script( 'ajax-property-sync' );

	// Localize script to pass AJAX URL and nonce
	wp_localize_script(
		'ajax-property-sync',
		'rfs_ajax_object',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'rfs_ajax_nonce' ),
		)
	);
	
	// get the post type of the current post
	$post_type = get_post_type( $post->ID );
	
	switch ( $post_type ) {
		case 'properties':
			
			$property_id = get_post_meta( $post->ID, 'property_id', true );
			$integration = get_post_meta( $post->ID, 'property_source', true );
			$label = 'property';
			$text = 'Perform a one-time sync: property, floorplan, and unit information.';
			
			break;
		case 'floorplans':
			
			$property_id = get_post_meta( $post->ID, 'property_id', true );
			$integration = get_post_meta( $post->ID, 'floorplan_source', true );			
			$label = 'floorplan';
			$text = 'Perform a one-time sync, including everything from the property to which this floorplan belongs (property, floorplan, and unit information).';
			
			break;
		case 'units':
			
			$property_id = get_post_meta( $post->ID, 'property_id', true );
			$integration = get_post_meta( $post->ID, 'unit_source', true );			
			$label = 'unit';
			$text = 'Perform a one-time sync, including everything from the property to which this unit belongs (property, floorplan, and unit information).';
			
			break;
		default:
			return;
	}

	// bail if there's no property id or integration
	if ( !$property_id || !$integration ) {
		return;
	}
	
	printf( '<p class="post-attributes-label-wrapper menu-order-label-wrapper">%s</p>', $text );
	printf( '<a href="#" data-property-id="%s" data-integration="%s" class="sync-property button button-large" >Sync this %s</a>', esc_attr( $property_id ), esc_attr( $integration ), $label );
}

/**
 * Ajax handler for the sync button
 *
 * @return void.
 */
function rfs_sync_single_property_ajax_handler() {
	check_ajax_referer('rfs_ajax_nonce', '_ajax_nonce');

	if (isset($_POST['property_id']) && isset($_POST['integration'])) {
		$property_id = sanitize_text_field($_POST['property_id']);
		$integration = sanitize_text_field($_POST['integration']);

		// Call your function
		rfs_sync_single_property( $property_id, $integration );

		wp_die('Sync successful');
	} else {
		wp_die('Invalid request');
	}
}
add_action('wp_ajax_rfs_sync_single_property', 'rfs_sync_single_property_ajax_handler');
