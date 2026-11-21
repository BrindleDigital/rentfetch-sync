<?php
/** Serialize sync and cleanup writes for one integration/property across PHP workers. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rfs_property_sync_lock_name( $integration, $property_code ) {
	global $wpdb;

	return 'rfs_property_' . substr( hash( 'sha256', wp_json_encode( array( DB_NAME, $wpdb->prefix, (string) $integration, (string) $property_code ) ) ), 0, 51 );
}

/** Connection-scoped locks do not expire while a slow provider request is running. */
function rfs_acquire_property_sync_lock( $integration, $property_code ) {
	global $wpdb, $rfs_property_sync_locks;

	$name = rfs_property_sync_lock_name( $integration, $property_code );
	if ( ! empty( $rfs_property_sync_locks[ $name ] ) || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
		throw new RuntimeException( 'A sync or cleanup is already running for this integration/property, or its execution lock could not be acquired. Please retry after it completes.' );
	}

	$rfs_property_sync_locks[ $name ] = true;
	return $name;
}

/** Stop writing if a dropped/reconnected database session has lost the lock. */
function rfs_assert_property_sync_lock( $integration, $property_code ) {
	global $wpdb, $rfs_property_sync_locks;

	$name = rfs_property_sync_lock_name( $integration, $property_code );
	if ( empty( $rfs_property_sync_locks[ $name ] ) || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name ) ) ) {
		throw new RuntimeException( 'The property execution lock was lost. Further sync writes and cleanup were stopped.' );
	}
}

function rfs_release_property_sync_lock( $name ) {
	global $wpdb, $rfs_property_sync_locks;

	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	unset( $rfs_property_sync_locks[ $name ] );
}

/** Delete candidates under the same per-property lock used by the dispatcher. */
function rfs_delete_sync_posts( $integration, $posts, $recheck_configuration = false ) {
	global $rfs_property_sync_locks;
	$groups = array();
	foreach ( $posts as $post ) {
		$id = is_object( $post ) ? $post->ID : $post;
		$groups[ (string) get_post_meta( $id, 'property_id', true ) ][] = $id;
	}
	foreach ( $groups as $property_id => $ids ) {
		$name = rfs_property_sync_lock_name( $integration, (string) $property_id );
		// Rent Manager also runs configured-property cleanup from inside its own sync.
		$borrowed = ! empty( $rfs_property_sync_locks[ $name ] );
		$lock = $borrowed ? $name : rfs_acquire_property_sync_lock( $integration, (string) $property_id );
		try {
			if ( $recheck_configuration && in_array( (string) $property_id, rfs_get_configured_property_ids( $integration ), true ) ) {
				continue;
			}
			foreach ( $ids as $id ) {
				rfs_assert_property_sync_lock( $integration, (string) $property_id );
				wp_cache_delete( $id, 'post_meta' );
				$source_key = array( 'properties' => 'property_source', 'floorplans' => 'floorplan_source', 'units' => 'unit_source' )[ get_post_type( $id ) ] ?? '';
				if ( ! $source_key || $integration !== get_post_meta( $id, $source_key, true ) || (string) $property_id !== (string) get_post_meta( $id, 'property_id', true ) ) { continue; }
				if ( ! wp_delete_post( $id, true ) ) {
					throw new RuntimeException( 'Unable to delete an unconfigured synced record.' );
				}
			}
		} finally {
			if ( ! $borrowed ) {
				rfs_release_property_sync_lock( $lock );
			}
		}
	}
}
