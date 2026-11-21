<?php
/** Authorize a whole-property manual sync from a concrete WordPress record. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once dirname( __DIR__ ) . '/api/rentegi/compatibility-rentegi.php';

function rfs_get_manual_sync_target( $post_id ) {
	$type = get_post_type_object( 'properties' );
	if ( ! $type || ! current_user_can( $type->cap->edit_others_posts ) ) {
		return new WP_Error( 'rfs_sync_forbidden', 'You do not have permission to sync properties.', array( 'status' => 403 ) );
	}
	$post = $post_id > 0 ? get_post( $post_id ) : null;
	$source_keys = array( 'properties' => 'property_source', 'floorplans' => 'floorplan_source', 'units' => 'unit_source' );
	if ( ! $post || ! isset( $source_keys[ $post->post_type ] ) || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
		return new WP_Error( 'rfs_sync_invalid_target', 'Select an existing synced property, floor plan or unit.', array( 'status' => 400 ) );
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return new WP_Error( 'rfs_sync_forbidden', 'You do not have permission to edit this record.', array( 'status' => 403 ) );
	}
	$source = (string) get_post_meta( $post->ID, $source_keys[ $post->post_type ], true );
	if ( 'units' === $post->post_type && '' === $source && ! metadata_exists( 'post', $post->ID, 'unit_source' ) ) {
		$source = (string) get_post_meta( $post->ID, 'floorplan_source', true );
	}
	$property_id = (string) get_post_meta( $post->ID, 'property_id', true );
	if ( ! in_array( $source, array( 'yardi', 'rentmanager', 'entrata', 'engrain', 'rentegi' ), true ) || '' === $property_id ) {
		return new WP_Error( 'rfs_sync_invalid_target', 'This record does not belong to an active sync source.', array( 'status' => 400 ) );
	}
	if ( 'rentegi' === $source && ! rfs_rentegi_core_is_compatible() ) {
		return new WP_Error( 'rfs_rentegi_core_update_required', rfs_rentegi_core_update_message(), array( 'status' => 409 ) );
	}
	$lookup = static function( $post_type, $source_key, $extra = array() ) use ( $source, $property_id ) {
		return get_posts( array(
			'post_type' => $post_type, 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'posts_per_page' => 2, 'fields' => 'ids', 'suppress_filters' => true,
			'meta_query' => array_merge( array(
				array( 'key' => $source_key, 'value' => $source ),
				array( 'key' => 'property_id', 'value' => $property_id ),
			), $extra ),
		) );
	};
	$parents = $lookup( 'properties', 'property_source' );
	if ( 1 !== count( $parents ) || ( 'properties' === $post->post_type && (int) $parents[0] !== (int) $post->ID ) ) {
		return new WP_Error( 'rfs_sync_invalid_target', 'The parent property is missing or ambiguous.', array( 'status' => 400 ) );
	}
	if ( ! current_user_can( 'edit_post', $parents[0] ) ) {
		return new WP_Error( 'rfs_sync_forbidden', 'You do not have permission to sync the parent property.', array( 'status' => 403 ) );
	}
	if ( 'units' === $post->post_type ) {
		$plan_id = (string) get_post_meta( $post->ID, 'floorplan_id', true );
		$plans = '' !== $plan_id ? $lookup( 'floorplans', 'floorplan_source', array( array( 'key' => 'floorplan_id', 'value' => $plan_id ) ) ) : array();
		if ( 1 !== count( $plans ) || ! current_user_can( 'edit_post', $plans[0] ) ) {
			return new WP_Error( 'rfs_sync_invalid_target', 'The unit floor plan is missing, ambiguous or inaccessible.', array( 'status' => 400 ) );
		}
	}
	if ( ! rfs_is_property_configured_for_sync( $source, $property_id ) ) {
		return new WP_Error( 'rfs_sync_invalid_target', 'This property is no longer configured for syncing.', array( 'status' => 400 ) );
	}
	return array( 'post_id' => (int) $post->ID, 'property_post_id' => (int) $parents[0], 'property_id' => $property_id, 'integration' => $source );
}

/** Both sync and progress requests use the same capability and target contract. */
function rfs_authorize_manual_sync_request() {
	foreach ( array( 'post_id', 'property_id', 'integration' ) as $key ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return new WP_Error( 'rfs_sync_invalid_target', 'A synced WordPress record is required.', array( 'status' => 400 ) );
		}
	}
	$target = rfs_get_manual_sync_target( absint( $_POST['post_id'] ) );
	if ( is_wp_error( $target ) ) { return $target; }
	if ( (string) wp_unslash( $_POST['property_id'] ) !== $target['property_id'] || (string) wp_unslash( $_POST['integration'] ) !== $target['integration'] ) {
		return new WP_Error( 'rfs_sync_invalid_target', 'The requested property and source do not match this record.', array( 'status' => 400 ) );
	}
	return $target;
}
