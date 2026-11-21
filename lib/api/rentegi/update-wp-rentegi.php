<?php
/** Update only fields supplied by RentEGI; retain media and manual enhancements. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rfs_rentegi_existing_posts( $type, $singular, $property_code, $record_id = null ) {
	$args = array(
		'post_type'      => $type,
		'post_status'    => array( 'publish', 'draft', 'private' ),
		'posts_per_page' => null === $record_id ? -1 : 2,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array( 'key' => $singular . '_source', 'value' => 'rentegi' ),
			array( 'key' => 'property_id', 'value' => $property_code ),
		),
	);
	if ( null !== $record_id ) {
		$args['meta_query'][] = array( 'key' => $singular . '_id', 'value' => (string) $record_id, 'type' => 'BINARY' );
	}
	return get_posts( $args );
}

function rfs_rentegi_upsert( $type, $singular, $property_code, $record ) {
	rfs_assert_property_sync_lock( 'rentegi', $property_code );
	$matches = rfs_rentegi_existing_posts( $type, $singular, $property_code, $record['id'] );
	if ( count( $matches ) > 1 ) {
		throw new RuntimeException( 'Duplicate RentEGI records must be resolved before syncing.' );
	}
	$id = $matches[0] ?? 0;

	$post = array(
		'post_type'   => $type,
		'post_title'  => sanitize_text_field( $record['name'] ),
		'post_status' => 'publish',
	);
	if ( $id ) {
		$post['ID'] = $id;
	}
	$created = ! $id;
	$id = $id ? wp_update_post( $post, true ) : wp_insert_post( $post, true );
	if ( is_wp_error( $id ) || ! $id ) {
		throw new RuntimeException( 'Unable to save a RentEGI record. Inventory cleanup was skipped.' );
	}
	try {
		$meta = array_merge(
			$record['meta'],
			array(
				'property_id'          => $property_code,
				$singular . '_source' => 'rentegi',
				$singular . '_id'     => $record['id'],
				'updated'             => current_time( 'mysql' ),
			)
		);
		if ( isset( $record['floorplan_id'] ) ) {
			$meta['floorplan_id'] = $record['floorplan_id'];
		}
		foreach ( $meta as $key => $value ) {
			rfs_rentegi_update_meta( $id, $key, $value );
		}
		if ( 'units' === $type ) {
			rfs_rentegi_record_result( $id, null, $record );
		}
	} catch ( Throwable $exception ) {
		// Roll back only records created by this failed save; existing enhancements stay intact.
		if ( $created ) {
			wp_delete_post( $id, true );
		}
		throw $exception;
	}
	return $id;
}

function rfs_rentegi_record_result( $post_id, $error = null, $payload = array() ) {
	$history                     = get_post_meta( $post_id, 'api_response', true );
	$history                     = is_array( $history ) ? $history : array();
	$history['rentegi_feed_api'] = array(
		'updated'       => current_time( 'mysql' ),
		'status_code'   => is_wp_error( $error ) ? (int) ( $error->get_error_data()['status'] ?? 0 ) : 200,
		'error_message' => is_wp_error( $error ) ? $error->get_error_message() : '',
		'api_response'  => wp_json_encode( $payload ),
	);
	rfs_rentegi_update_meta( $post_id, 'api_response', $history );
	// Persist the normal endpoint rollup through checked writes as well.
	$timestamp   = $history['rentegi_feed_api']['updated'];
	$state       = is_wp_error( $error ) ? 'failed' : 'success';
	$sync_status = rfs_get_sync_status_map( $post_id );
	$endpoint    = $sync_status['rentegi_feed_api'] ?? array();
	$endpoint['state']           = $state;
	$endpoint['last_attempt_at'] = $timestamp;
	$endpoint[ is_wp_error( $error ) ? 'last_failure_at' : 'last_success_at' ] = $timestamp;
	$sync_status = array( 'rentegi_feed_api' => $endpoint );
	$rollup      = rfs_build_sync_rollup( $sync_status );

	rfs_rentegi_update_meta( $post_id, 'sync_status', $sync_status );
	rfs_rentegi_update_meta( $post_id, 'last_sync_state', $rollup['state'] );
	rfs_rentegi_update_meta( $post_id, 'last_sync_attempt_at', $rollup['last_attempt_at'] );
	rfs_rentegi_update_meta( $post_id, 'updated', $rollup['last_attempt_at'] );
	if ( '' !== $rollup['last_success_at'] ) {
		rfs_rentegi_update_meta( $post_id, 'last_synced_at', $rollup['last_success_at'] );
	}
}

/** WordPress returns false both for unchanged metadata and for failed writes. */
function rfs_rentegi_update_meta( $post_id, $key, $value ) {
	$expected = is_scalar( $value ) || null === $value ? (string) $value : $value;
	if ( metadata_exists( 'post', $post_id, $key ) && get_post_meta( $post_id, $key, true ) === $expected ) {
		return;
	}

	update_post_meta( $post_id, $key, wp_slash( $value ) );
	if ( ! metadata_exists( 'post', $post_id, $key ) || get_post_meta( $post_id, $key, true ) !== $expected ) {
		throw new RuntimeException( 'Unable to save RentEGI metadata (' . $key . ').' );
	}
}
