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
	
	wp_enqueue_script( 'rentfetch-ajax-property-sync' );

	// Localize script to pass AJAX URL and nonce
	wp_localize_script(
		'rentfetch-ajax-property-sync',
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

	// Visible status/debug area for the sync UX
	echo '<div class="rfs-sync-status" style="margin-top:8px;font-size:12px;color:#666">';
	echo '<strong>Status:</strong> <span class="rfs-sync-status-message">Ready</span>';
	echo ' <span class="rfs-sync-status-meta" style="display:block;margin-top:4px;color:#999;font-size:11px"></span>';
	echo '</div>';

	// Simple progress indicator
	echo '<div class="rfs-sync-progress" style="margin-top:8px;">
		<div class="rfs-sync-progress-track" style="background:#eee;border:1px solid #ddd;height:8px;border-radius:4px;overflow:hidden;">
			<div class="rfs-sync-progress-fill" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="width:0%;height:100%;background:#eb6836;transition:width 300ms ease;"></div>
		</div>
	</div>';
}

/**
 * Ajax handler for the sync button
 *
 * @return void.
 */
function rfs_sync_single_property_ajax_handler() {
	check_ajax_referer('rfs_ajax_nonce', '_ajax_nonce');

	if (isset($_POST['property_id']) && isset($_POST['integration'])) {
		$property_id = sanitize_text_field( wp_unslash( $_POST['property_id'] ) );
		$integration = sanitize_text_field( wp_unslash( $_POST['integration'] ) );

		// Prepare args for the scheduled action
		$args = array(
			'integration' => $integration,
			'property_id' => $property_id,
			'credentials' => rfs_get_credentials(),
		);

		// Run synchronously (do not schedule). The sync function handles its own progress updates.
		rfs_sync_single_property( $property_id, $integration );

		// After completion attempt to return the progress payload so the client can pick up final state immediately
		$key = 'rfs_sync_progress_' . md5( $integration . '_' . $property_id );
		$data = get_transient( $key );

		if ( $data && is_array( $data ) ) {
			wp_send_json_success( $data );
		} else {
			// Fallback: return a minimal completed payload
			wp_send_json_success( array(
				'integration' => $integration,
				'property_id' => $property_id,
				'step' => 1,
				'total' => 1,
				'message' => 'Completed',
			) );
		}
	} else {
		wp_die('Invalid request');
	}
}
add_action('wp_ajax_rfs_sync_single_property', 'rfs_sync_single_property_ajax_handler');
