<?php
/**
 * Sync status helpers and endpoint registry.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Check whether a post participates in sync status tracking.
 *
 * @param int $post_id The post ID.
 * @return bool
 */
function rfs_is_sync_content_post_type( $post_id ) {
	$post_type = get_post_type( $post_id );

	return in_array( $post_type, array( 'properties', 'floorplans', 'units' ), true );
}

/**
 * Parse a sync timestamp into a unix timestamp.
 *
 * @param mixed $raw_value The stored timestamp value.
 * @return int
 */
function rfs_parse_sync_timestamp( $raw_value ) {
	if ( '' === (string) $raw_value || null === $raw_value ) {
		return 0;
	}

	if ( is_numeric( $raw_value ) ) {
		return (int) $raw_value;
	}

	$timestamp = strtotime( (string) $raw_value );

	return false === $timestamp ? 0 : $timestamp;
}

/**
 * Update post meta only when the value has changed.
 *
 * @param int    $object_id The post ID.
 * @param string $meta_key  The meta key.
 * @param mixed  $value     The new value.
 * @return void
 */
function rfs_update_post_meta_if_changed( $object_id, $meta_key, $value ) {
	$current_value = get_post_meta( $object_id, $meta_key, true );

	if ( $current_value === $value ) {
		return;
	}

	update_post_meta( $object_id, $meta_key, $value );
}

/**
 * Delete post meta only when it exists.
 *
 * @param int    $object_id The post ID.
 * @param string $meta_key  The meta key.
 * @return void
 */
function rfs_delete_post_meta_if_present( $object_id, $meta_key ) {
	if ( ! metadata_exists( 'post', $object_id, $meta_key ) ) {
		return;
	}

	delete_post_meta( $object_id, $meta_key );
}

/**
 * Get the structured sync status map for a record.
 *
 * @param int $object_id The post ID.
 * @return array
 */
function rfs_get_sync_status_map( $object_id ) {
	$sync_status = get_post_meta( $object_id, 'sync_status', true );

	return is_array( $sync_status ) ? $sync_status : array();
}

/**
 * Get the source meta key for a post type.
 *
 * @param string $post_type The post type.
 * @return string
 */
function rfs_get_sync_source_meta_key( $post_type ) {
	switch ( $post_type ) {
		case 'properties':
			return 'property_source';
		case 'floorplans':
			return 'floorplan_source';
		case 'units':
			return 'unit_source';
		default:
			return '';
	}
}

/**
 * Get the current sync source for a record.
 *
 * Normalizes legacy unit records that still use `floorplan_source`.
 *
 * @param int $object_id The post ID.
 * @return string
 */
function rfs_get_sync_source_for_object( $object_id ) {
	$post_type = get_post_type( $object_id );
	$meta_key  = rfs_get_sync_source_meta_key( $post_type );

	if ( '' === $meta_key ) {
		return '';
	}

	$source = trim( (string) get_post_meta( $object_id, $meta_key, true ) );
	$legacy_source = 'units' === $post_type
		? trim( (string) get_post_meta( $object_id, 'floorplan_source', true ) )
		: '';

	if ( 'units' !== $post_type ) {
		return $source;
	}

	if ( '' !== $source ) {
		if ( '' !== $legacy_source ) {
			delete_post_meta( $object_id, 'floorplan_source' );
		}

		return $source;
	}

	if ( '' === $legacy_source ) {
		return '';
	}

	update_post_meta( $object_id, 'unit_source', $legacy_source );
	delete_post_meta( $object_id, 'floorplan_source' );

	return $legacy_source;
}

/**
 * Get the expected endpoint registry for a post type and source.
 *
 * Empty/manual sources intentionally map to no endpoints so stale sync state is
 * cleared when content is switched to manual.
 *
 * @param string $post_type The post type.
 * @param string $source    The integration/source.
 * @return array|null
 */
function rfs_get_expected_sync_endpoints( $post_type, $source ) {
	$source = strtolower( trim( (string) $source ) );

	if ( '' === $source || 'manual' === $source ) {
		return array();
	}

	$registry = array(
		'properties' => array(
			'yardi'       => array( 'properties_api', 'property_images_api' ),
			'entrata'     => array( 'properties_api', 'getMitsPropertyUnits' ),
			'rentmanager' => array( 'properties_api' ),
		),
		'floorplans' => array(
			'yardi'       => array( 'floorplans_api' ),
			'entrata'     => array( 'floorplans_api' ),
			'rentmanager' => array( 'unit_types_api' ),
		),
		'units' => array(
			'yardi'       => array( 'apartmentavailability_api' ),
			'entrata'     => array( 'getUnitsAvailabilityAndPricing' ),
			'rentmanager' => array( 'units_api' ),
		),
	);

	if ( ! isset( $registry[ $post_type ] ) ) {
		return null;
	}

	if ( ! isset( $registry[ $post_type ][ $source ] ) ) {
		return null;
	}

	return $registry[ $post_type ][ $source ];
}

/**
 * Clear the derived sync rollup meta for a record.
 *
 * @param int $object_id The post ID.
 * @return void
 */
function rfs_clear_sync_rollup( $object_id ) {
	rfs_delete_post_meta_if_present( $object_id, 'sync_status' );
	rfs_delete_post_meta_if_present( $object_id, 'last_sync_state' );
	rfs_delete_post_meta_if_present( $object_id, 'last_sync_attempt_at' );
	rfs_delete_post_meta_if_present( $object_id, 'last_synced_at' );
	rfs_delete_post_meta_if_present( $object_id, 'updated' );
}

/**
 * Build the aggregate sync rollup from endpoint state.
 *
 * @param array $sync_status The endpoint sync state map.
 * @return array{state:string,last_attempt_at:string,last_success_at:string}
 */
function rfs_build_sync_rollup( $sync_status ) {
	$has_success      = false;
	$has_failed       = false;
	$last_attempt_at  = '';
	$last_attempt_ts  = 0;
	$last_success_at  = '';
	$last_success_ts  = 0;

	foreach ( $sync_status as $endpoint_state ) {
		if ( ! is_array( $endpoint_state ) ) {
			continue;
		}

		$state = isset( $endpoint_state['state'] ) ? (string) $endpoint_state['state'] : '';

		if ( 'success' === $state ) {
			$has_success = true;
		} elseif ( 'failed' === $state ) {
			$has_failed = true;
		}

		if ( isset( $endpoint_state['last_attempt_at'] ) ) {
			$attempt_ts = rfs_parse_sync_timestamp( $endpoint_state['last_attempt_at'] );

			if ( $attempt_ts > $last_attempt_ts ) {
				$last_attempt_ts = $attempt_ts;
				$last_attempt_at = (string) $endpoint_state['last_attempt_at'];
			}
		}

		if ( isset( $endpoint_state['last_success_at'] ) ) {
			$success_ts = rfs_parse_sync_timestamp( $endpoint_state['last_success_at'] );

			if ( $success_ts > $last_success_ts ) {
				$last_success_ts = $success_ts;
				$last_success_at = (string) $endpoint_state['last_success_at'];
			}
		}
	}

	if ( $has_success && $has_failed ) {
		$state = 'partial';
	} elseif ( $has_failed ) {
		$state = 'failed';
	} elseif ( $has_success ) {
		$state = 'success';
	} else {
		$state = 'never';
	}

	return array(
		'state'           => $state,
		'last_attempt_at' => $last_attempt_at,
		'last_success_at' => $last_success_at,
	);
}

/**
 * Persist the aggregate sync rollup meta.
 *
 * @param int   $object_id   The post ID.
 * @param array $sync_status The endpoint sync state map.
 * @return void
 */
function rfs_persist_sync_rollup( $object_id, $sync_status ) {
	$sync_status = is_array( $sync_status ) ? $sync_status : array();
	$rollup      = rfs_build_sync_rollup( $sync_status );

	if ( empty( $sync_status ) ) {
		rfs_delete_post_meta_if_present( $object_id, 'sync_status' );
	} else {
		rfs_update_post_meta_if_changed( $object_id, 'sync_status', $sync_status );
	}

	rfs_update_post_meta_if_changed( $object_id, 'last_sync_state', $rollup['state'] );

	if ( '' === $rollup['last_attempt_at'] ) {
		rfs_delete_post_meta_if_present( $object_id, 'last_sync_attempt_at' );
		rfs_delete_post_meta_if_present( $object_id, 'updated' );
	} else {
		rfs_update_post_meta_if_changed( $object_id, 'last_sync_attempt_at', $rollup['last_attempt_at'] );
		rfs_update_post_meta_if_changed( $object_id, 'updated', $rollup['last_attempt_at'] );
	}

	if ( '' === $rollup['last_success_at'] ) {
		rfs_delete_post_meta_if_present( $object_id, 'last_synced_at' );
	} else {
		rfs_update_post_meta_if_changed( $object_id, 'last_synced_at', $rollup['last_success_at'] );
	}
}

/**
 * Prune sync status keys to the current registry for a record.
 *
 * @param int $object_id The post ID.
 * @return array
 */
function rfs_prune_sync_status_to_registry( $object_id ) {
	if ( ! rfs_is_sync_content_post_type( $object_id ) ) {
		return array();
	}

	$post_type = get_post_type( $object_id );
	$source    = rfs_get_sync_source_for_object( $object_id );
	$expected  = rfs_get_expected_sync_endpoints( $post_type, $source );

	if ( array() === $expected ) {
		rfs_clear_sync_rollup( $object_id );
		return array();
	}

	$sync_status = rfs_get_sync_status_map( $object_id );

	if ( null === $expected ) {
		rfs_persist_sync_rollup( $object_id, $sync_status );
		return $sync_status;
	}

	$pruned_sync_status = array();

	foreach ( $sync_status as $endpoint => $endpoint_state ) {
		if ( in_array( $endpoint, $expected, true ) ) {
			$pruned_sync_status[ $endpoint ] = $endpoint_state;
		}
	}

	rfs_persist_sync_rollup( $object_id, $pruned_sync_status );

	return $pruned_sync_status;
}

/**
 * Set the sync state for a specific endpoint on a record.
 *
 * @param int         $object_id The post ID.
 * @param string      $endpoint  The endpoint key.
 * @param string      $state     The state (`success` or `failed`).
 * @param string|int  $timestamp Optional timestamp override.
 * @return void
 */
function rfs_set_endpoint_sync_state( $object_id, $endpoint, $state, $timestamp = null ) {
	if ( ! rfs_is_sync_content_post_type( $object_id ) || '' === trim( (string) $endpoint ) ) {
		return;
	}

	$timestamp   = null === $timestamp ? current_time( 'mysql' ) : $timestamp;
	$post_type   = get_post_type( $object_id );
	$source      = rfs_get_sync_source_for_object( $object_id );
	$expected    = rfs_get_expected_sync_endpoints( $post_type, $source );
	$sync_status = rfs_prune_sync_status_to_registry( $object_id );

	if ( is_array( $expected ) && ! in_array( $endpoint, $expected, true ) ) {
		return;
	}

	$current_state = isset( $sync_status[ $endpoint ] ) && is_array( $sync_status[ $endpoint ] )
		? $sync_status[ $endpoint ]
		: array();

	$current_state['state']           = $state;
	$current_state['last_attempt_at'] = $timestamp;

	if ( 'success' === $state ) {
		$current_state['last_success_at'] = $timestamp;
	} elseif ( 'failed' === $state ) {
		$current_state['last_failure_at'] = $timestamp;
	}

	$sync_status[ $endpoint ] = $current_state;

	rfs_persist_sync_rollup( $object_id, $sync_status );
}

/**
 * Mark an endpoint sync as failed.
 *
 * @param int        $object_id The post ID.
 * @param string     $endpoint  The endpoint key.
 * @param string|int $timestamp Optional timestamp override.
 * @return void
 */
function rfs_mark_sync_failed( $object_id, $endpoint, $timestamp = null ) {
	rfs_set_endpoint_sync_state( $object_id, $endpoint, 'failed', $timestamp );
}

/**
 * Mark an endpoint sync as succeeded.
 *
 * @param int        $object_id The post ID.
 * @param string     $endpoint  The endpoint key.
 * @param string|int $timestamp Optional timestamp override.
 * @return void
 */
function rfs_mark_sync_succeeded( $object_id, $endpoint, $timestamp = null ) {
	rfs_set_endpoint_sync_state( $object_id, $endpoint, 'success', $timestamp );
}

/**
 * Prune sync state when a source meta key changes.
 *
 * @param int    $meta_id    The meta ID.
 * @param int    $object_id  The post ID.
 * @param string $meta_key   The meta key.
 * @param mixed  $meta_value The meta value.
 * @return void
 */
function rfs_prune_sync_state_on_source_meta_change( $meta_id, $object_id, $meta_key, $meta_value ) {
	unset( $meta_id, $meta_value );

	if ( ! rfs_is_sync_content_post_type( $object_id ) ) {
		return;
	}

	$expected_key = rfs_get_sync_source_meta_key( get_post_type( $object_id ) );

	if ( '' === $expected_key || $meta_key !== $expected_key ) {
		return;
	}

	rfs_prune_sync_status_to_registry( $object_id );
}

add_action( 'added_post_meta', 'rfs_prune_sync_state_on_source_meta_change', 10, 4 );
add_action( 'updated_post_meta', 'rfs_prune_sync_state_on_source_meta_change', 10, 4 );

/**
 * Clear sync state when the active source meta key is deleted.
 *
 * @param array  $meta_ids            The deleted meta IDs.
 * @param int    $object_id           The post ID.
 * @param string $meta_key            The meta key.
 * @param mixed  $deleted_meta_values The deleted values.
 * @return void
 */
function rfs_clear_sync_state_on_source_meta_delete( $meta_ids, $object_id, $meta_key, $deleted_meta_values ) {
	unset( $meta_ids, $deleted_meta_values );

	if ( ! rfs_is_sync_content_post_type( $object_id ) ) {
		return;
	}

	$expected_key = rfs_get_sync_source_meta_key( get_post_type( $object_id ) );

	if ( '' === $expected_key || $meta_key !== $expected_key ) {
		return;
	}

	rfs_clear_sync_rollup( $object_id );
}

add_action( 'deleted_post_meta', 'rfs_clear_sync_state_on_source_meta_delete', 10, 4 );
