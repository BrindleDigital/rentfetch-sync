<?php
/**
 * Accelerated sync controls and queue runner.
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once dirname( __DIR__ ) . '/api/configured-properties.php';

/**
 * Get the option key used to store accelerated sync state.
 *
 * @return string
 */
function rfs_get_accelerated_sync_state_key() {
	return 'rfs_accelerated_sync_state';
}

/**
 * Get accelerated sync state.
 *
 * @return array
 */
function rfs_get_accelerated_sync_state() {
	$state = rfs_get_fresh_option( rfs_get_accelerated_sync_state_key(), array() );

	return is_array( $state ) ? $state : array();
}

/**
 * Persist accelerated sync state.
 *
 * @param array $state State to persist.
 * @return void
 */
function rfs_update_accelerated_sync_state( $state ) {
	$state = is_array( $state ) ? $state : array();
	if ( ! update_option( rfs_get_accelerated_sync_state_key(), $state, false ) && rfs_get_accelerated_sync_state() !== $state ) {
		throw new RuntimeException( 'Unable to save accelerated sync state.' );
	}
}

/**
 * Get the option key used to request cancellation for a run.
 *
 * @param string $run_id Run ID.
 * @return string
 */
function rfs_get_accelerated_sync_cancel_key( $run_id ) {
	return 'rfs_accelerated_sync_cancel_' . md5( (string) $run_id );
}

/**
 * Mark an accelerated sync run as cancellation-requested.
 *
 * @param string $run_id Run ID.
 * @return void
 */
function rfs_request_accelerated_sync_cancel( $run_id ) {
	if ( '' === (string) $run_id ) {
		return;
	}

	update_option( rfs_get_accelerated_sync_cancel_key( $run_id ), time(), false );
}

/**
 * Check whether cancellation has been requested for a run.
 *
 * @param string $run_id Run ID.
 * @return bool
 */
function rfs_is_accelerated_sync_cancel_requested( $run_id ) {
	if ( '' === (string) $run_id ) {
		return false;
	}

	return (int) rfs_get_fresh_option( rfs_get_accelerated_sync_cancel_key( $run_id ), 0 ) > 0;
}

/**
 * Remove the cancellation marker for a run.
 *
 * @param string $run_id Run ID.
 * @return void
 */
function rfs_clear_accelerated_sync_cancel_request( $run_id ) {
	if ( '' === (string) $run_id ) {
		return;
	}

	delete_option( rfs_get_accelerated_sync_cancel_key( $run_id ) );
}

/**
 * Unschedule pending accelerated sync actions.
 *
 * @return void
 */
function rfs_unschedule_accelerated_sync_actions() {
	wp_unschedule_hook( 'rfs_accelerated_sync_step' );
	wp_unschedule_hook( 'rfs_finalize_accelerated_sync_item' );
	wp_unschedule_hook( 'rfs_queue_accelerated_sync_run' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'rfs_accelerated_sync_step' );
		as_unschedule_all_actions( 'rfs_finalize_accelerated_sync_item' );
		as_unschedule_all_actions( 'rfs_queue_accelerated_sync_run' );
	}
}

/**
 * Persist a cancelled accelerated sync state.
 *
 * @param array|null $state Optional state.
 * @return array
 */
function rfs_mark_accelerated_sync_cancelled( $state = null ) {
	$state = is_array( $state ) ? $state : rfs_get_accelerated_sync_state();

	$state['status']        = 'cancelled';
	$state['current_label'] = '';
	$state['active_items']  = array();
	$state['updated_at']    = time();
	$state['completed_at']  = time();

	rfs_update_accelerated_sync_state( $state );

	return $state;
}

/**
 * Get the accelerated sync concurrency limit.
 *
 * @return int
 */
function rfs_get_accelerated_sync_concurrency() {
	return max( 1, min( 10, (int) apply_filters( 'rfs_accelerated_sync_concurrency', 5 ) ) );
}

/**
 * Acquire a short lock while updating accelerated sync state.
 *
 * @param string $run_id Run ID.
 * @return string|false
 */
function rfs_acquire_accelerated_sync_lock( $run_id ) {
	global $wpdb, $rfs_accelerated_state_lock;
	$key = 'rfs_accelerated_' . md5( DB_NAME . ':' . $wpdb->options );
	if ( ! empty( $rfs_accelerated_state_lock ) || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) ) ) {
		return false;
	}
	$rfs_accelerated_state_lock = $key;
	return $key;
}

/** Release only the connection-owned state mutex acquired by this request. */
function rfs_release_accelerated_sync_lock( $lock_key ) {
	global $wpdb, $rfs_accelerated_state_lock;
	if ( $lock_key && $rfs_accelerated_state_lock === $lock_key ) {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
		$rfs_accelerated_state_lock = null;
	}
}

/** Retain terminal results before waiting for the shared state mutex. */
function rfs_accelerated_sync_result_key( $run_id, $item_index ) {
	return 'rfs_accelerated_result_' . md5( (string) $run_id ) . '_' . (int) $item_index;
}

function rfs_retry_accelerated_sync_action( $hook, $args ) {
	if ( rfs_get_fresh_option( 'rfs_sync_deactivated', false ) ) { return; }
	if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' ) ) {
		// The current in-progress retry must not suppress its replacement.
		$pending = as_get_scheduled_actions( array( 'hook' => $hook, 'args' => $args, 'group' => 'rentfetch-accelerated-sync', 'status' => 'pending', 'per_page' => 1 ), 'ids' );
		if ( $pending || as_schedule_single_action( time() + 10, $hook, $args, 'rentfetch-accelerated-sync' ) ) { return; }
	}
	if ( ! wp_next_scheduled( $hook, $args ) ) {
		wp_schedule_single_event( time() + 10, $hook, $args );
	}
}

function rfs_record_accelerated_sync_result( $run_id, $item_index, $success, $message = '', $queue_next = true ) {
	if ( rfs_get_fresh_option( 'rfs_sync_deactivated', false ) ) { return; }
	$key = rfs_accelerated_sync_result_key( $run_id, $item_index );
	$result = array( 'success' => (bool) $success, 'message' => (string) $message, 'queue_next' => (bool) $queue_next );
	if ( ! add_option( $key, $result, '', false ) && ! is_array( rfs_get_fresh_option( $key ) ) ) {
		throw new RuntimeException( 'Unable to retain an accelerated sync result.' );
	}
	rfs_finalize_accelerated_sync_item( $run_id, $item_index );
}

/** Apply each durable terminal result once, even when workers finish together. */
function rfs_finalize_accelerated_sync_item( $run_id, $item_index ) {
	$key = rfs_accelerated_sync_result_key( $run_id, $item_index );
	$result = rfs_get_fresh_option( $key );
	if ( ! is_array( $result ) ) { return; }
	$lock = rfs_acquire_accelerated_sync_lock( $run_id );
	if ( ! $lock ) {
		rfs_retry_accelerated_sync_action( 'rfs_finalize_accelerated_sync_item', array( $run_id, (int) $item_index ) );
		return;
	}
	try {
		$state = rfs_get_accelerated_sync_state();
		if ( ( $state['run_id'] ?? '' ) !== $run_id || 'running' !== ( $state['status'] ?? '' ) || ! empty( $state['finished_items'][ $item_index ] ) ) {
			delete_option( $key );
			return;
		}
		if ( rfs_is_accelerated_sync_cancel_requested( $run_id ) || rfs_get_fresh_option( 'rfs_sync_deactivated', false ) ) {
			rfs_mark_accelerated_sync_cancelled( $state );
			delete_option( $key );
			return;
		}
		$item = $state['items'][ $item_index ] ?? null;
		if ( ! is_array( $item ) ) { delete_option( $key ); return; }
		$state['finished_items'][ $item_index ] = true;
		unset( $state['active_items'][ $item_index ] );
		$field = $result['success'] ? 'completed' : 'failed';
		$state[ $field ] = (int) ( $state[ $field ] ?? 0 ) + 1;
		if ( 'property' === ( $item['type'] ?? '' ) ) {
			$field = $result['success'] ? 'property_completed' : 'property_failed';
			$state[ $field ] = (int) ( $state[ $field ] ?? 0 ) + 1;
		}
		if ( ! $result['success'] ) {
			$state['errors'][] = array( 'label' => $item['label'] ?? 'Sync item', 'message' => $result['message'] );
			$state['errors'] = array_slice( $state['errors'], -20 );
		}
		$state['current_label'] = implode( ', ', array_slice( array_values( $state['active_items'] ?? array() ), 0, 3 ) );
		$state['updated_at'] = time();
		if ( (int) $state['completed'] + (int) $state['failed'] >= (int) $state['total'] ) {
			$state['status'] = 'complete';
			$state['current_label'] = '';
			$state['active_items'] = array();
			$state['completed_at'] = time();
			rfs_update_accelerated_sync_state( $state );
			// Keep the mutex until the completion boundary is emitted once.
			do_action( 'rfs_sync_cycle_finished', 'accelerated', $state );
		} elseif ( $result['queue_next'] || ! empty( $state['dispatch_ready'] ) ) {
			rfs_queue_accelerated_sync_workers( $state );
		} else {
			rfs_update_accelerated_sync_state( $state );
		}
		delete_option( $key );
	} catch ( Throwable $exception ) {
		error_log( 'Rent Fetch Sync result finalization will retry: ' . $exception->getMessage() );
		rfs_retry_accelerated_sync_action( 'rfs_finalize_accelerated_sync_item', array( $run_id, (int) $item_index ) );
	} finally {
		rfs_release_accelerated_sync_lock( $lock );
	}
}
add_action( 'rfs_finalize_accelerated_sync_item', 'rfs_finalize_accelerated_sync_item', 10, 2 );

/** Account for a fatal or otherwise uncaught Action Scheduler worker failure. */
function rfs_accelerated_sync_action_failed( $action_id ) {
	$action = ActionScheduler::store()->fetch_action( $action_id );
	if ( 'rfs_accelerated_sync_step' !== $action->get_hook() ) { return; }
	$args = array_values( $action->get_args() );
	if ( isset( $args[0], $args[1] ) && is_numeric( $args[1] ) ) {
		rfs_record_accelerated_sync_result( (string) $args[0], (int) $args[1], false, 'The background worker ended before completing this item.' );
	}
}
add_action( 'action_scheduler_failed_execution', 'rfs_accelerated_sync_action_failed' );
add_action( 'action_scheduler_unexpected_shutdown', 'rfs_accelerated_sync_action_failed' );

/** Clean only this run's retained result options after cancellation/replacement. */
function rfs_clear_accelerated_sync_results( $run_id ) {
	global $wpdb;
	$prefix = 'rfs_accelerated_result_' . md5( (string) $run_id ) . '_';
	$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	foreach ( $keys as $key ) { delete_option( $key ); }
}

/**
 * Determine whether Rent Fetch Sync can start an accelerated run.
 *
 * @return true|WP_Error
 */
function rfs_can_start_accelerated_sync() {
	if ( rfs_is_sync_locked() ) {
		return new WP_Error( 'rentfetch_sync_locked', rfs_sync_locked_message() );
	}
	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		return new WP_Error( 'action_scheduler_unavailable', 'Action Scheduler is not available.' );
	}

	return true;
}

/**
 * Build the same property sync manifest used by normal recurring sync setup.
 *
 * @return array
 */
function rfs_get_accelerated_sync_manifest() {
	$items                = array();
	$enabled_integrations = get_option( 'rentfetch_options_enabled_integrations' );

	if ( ! is_array( $enabled_integrations ) ) {
		$enabled_integrations = array();
	}

	if ( in_array( 'yardi', $enabled_integrations, true ) ) {
		$yardi_properties = get_option( 'rentfetch_options_yardi_integration_creds_yardi_property_code' );
		$yardi_properties = str_replace( ' ', '', (string) $yardi_properties );
		$yardi_properties = array_values( array_filter( explode( ',', $yardi_properties ) ) );

		if ( ! empty( $yardi_properties ) ) {
			$items[] = array(
				'hook'        => 'rfs_yardi_do_delete_orphans',
				'args'        => array(),
				'type'        => 'cleanup',
				'integration' => 'yardi',
				'property_id' => '',
				'label'       => 'Yardi orphan cleanup',
			);
		}

		foreach ( $yardi_properties as $yardi_property ) {
			$items[] = array(
				'hook'        => 'rfs_do_sync',
				'args'        => array(
					array(
						'integration' => 'yardi',
						'property_id' => $yardi_property,
					),
				),
				'type'        => 'property',
				'integration' => 'yardi',
				'property_id' => $yardi_property,
				'label'       => sprintf( 'Yardi property %s', $yardi_property ),
			);
		}
	}

	if ( in_array( 'engrain', $enabled_integrations, true ) ) {
		$engrain_assets = function_exists( 'rfs_engrain_get_configured_asset_ids' )
			? rfs_engrain_get_configured_asset_ids()
			: array();

		$items[] = array(
			'hook'        => 'rfs_engrain_do_delete_orphans',
			'args'        => array(),
			'type'        => 'cleanup',
			'integration' => 'engrain',
			'property_id' => '',
			'label'       => 'Engrain orphan cleanup',
		);

		foreach ( $engrain_assets as $engrain_asset ) {
			$items[] = array(
				'hook'        => 'rfs_do_sync',
				'args'        => array(
					array(
						'integration' => 'engrain',
						'property_id' => $engrain_asset,
					),
				),
				'type'        => 'property',
				'integration' => 'engrain',
				'property_id' => $engrain_asset,
				'label'       => sprintf( 'Engrain asset %s', $engrain_asset ),
			);
		}
	}

	if ( in_array( 'entrata', $enabled_integrations, true ) ) {
		$entrata_properties = get_option( 'rentfetch_options_entrata_integration_creds_entrata_property_ids' );
		$entrata_properties = str_replace( ' ', '', (string) $entrata_properties );
		$entrata_properties = array_values( array_filter( explode( ',', $entrata_properties ) ) );

		if ( ! empty( $entrata_properties ) ) {
			$items[] = array(
				'hook'        => 'rfs_entrata_do_delete_orphans',
				'args'        => array(),
				'type'        => 'cleanup',
				'integration' => 'entrata',
				'property_id' => '',
				'label'       => 'Entrata orphan cleanup',
			);
		}

		foreach ( $entrata_properties as $entrata_property ) {
			$items[] = array(
				'hook'        => 'rfs_do_sync',
				'args'        => array(
					array(
						'integration' => 'entrata',
						'property_id' => $entrata_property,
					),
				),
				'type'        => 'property',
				'integration' => 'entrata',
				'property_id' => $entrata_property,
				'label'       => sprintf( 'Entrata property %s', $entrata_property ),
			);
		}
	}

	if ( in_array( 'rentmanager', $enabled_integrations, true ) ) {
		$rentmanager_properties = rfs_get_enabled_rentmanager_properties();

		if ( ! is_array( $rentmanager_properties ) ) {
			$rentmanager_properties = array();
		}

		foreach ( $rentmanager_properties as $rentmanager_property ) {
			if ( empty( $rentmanager_property['ShortName'] ) ) {
				continue;
			}

			$property_shortname = sanitize_text_field( $rentmanager_property['ShortName'] );
			$items[]            = array(
				'hook'        => 'rfs_do_sync',
				'args'        => array(
					array(
						'integration' => 'rentmanager',
						'property_id' => $property_shortname,
					),
				),
				'type'        => 'property',
				'integration' => 'rentmanager',
				'property_id' => $property_shortname,
				'label'       => sprintf( 'Rent Manager property %s', $property_shortname ),
			);
		}
	}

	if ( in_array( 'rentegi', $enabled_integrations, true ) && rfs_rentegi_core_is_compatible() ) {
		$items[] = array(
			'hook'        => 'rfs_rentegi_do_delete_orphans',
			'args'        => array(),
			'type'        => 'cleanup',
			'integration' => 'rentegi',
			'property_id' => '',
			'label'       => 'RentEGI orphan cleanup',
		);

		foreach ( rfs_rentegi_get_property_codes() as $property_code ) {
			$items[] = array(
				'hook' => 'rfs_do_sync',
				'args' => array( array( 'integration' => 'rentegi', 'property_id' => $property_code ) ),
				'type' => 'property', 'integration' => 'rentegi', 'property_id' => $property_code,
				'label' => sprintf( 'RentEGI property %s', $property_code ),
			);
		}
	}

	return apply_filters( 'rfs_accelerated_sync_manifest', $items );
}

/**
 * Check whether a run still has scheduled accelerated work.
 *
 * @param string $run_id Run ID.
 * @return bool
 */
function rfs_accelerated_sync_has_scheduled_work( $run_id ) {
	if ( ! function_exists( 'as_has_scheduled_action' ) || '' === (string) $run_id ) {
		return false;
	}

	return as_has_scheduled_action( 'rfs_accelerated_sync_step', null, 'rentfetch-accelerated-sync' );
}

/**
 * Queue enough accelerated sync workers to reach the concurrency limit.
 *
 * @param array $state Current sync state.
 * @return array Updated sync state.
 */
/** @internal Caller holds the accelerated state mutex. */
function rfs_queue_accelerated_sync_workers( $state ) {
	if ( ! empty( $state['run_id'] ) && ( rfs_get_fresh_option( 'rfs_sync_deactivated', false ) || rfs_is_accelerated_sync_cancel_requested( $state['run_id'] ) ) ) {
		return rfs_mark_accelerated_sync_cancelled( $state );
	}
	if ( empty( $state['run_id'] ) || empty( $state['status'] ) || 'running' !== $state['status'] ) {
		return $state;
	}

	$total       = isset( $state['total'] ) ? (int) $state['total'] : 0;
	$queued      = isset( $state['queued'] ) ? (int) $state['queued'] : 0;
	$completed   = isset( $state['completed'] ) ? (int) $state['completed'] : 0;
	$failed      = isset( $state['failed'] ) ? (int) $state['failed'] : 0;
	$concurrency = rfs_get_accelerated_sync_concurrency();
	$in_flight   = max( 0, $queued - $completed - $failed );
	$to_enqueue  = array();

	while ( $queued < $total && $in_flight < $concurrency ) {
		$to_enqueue[] = $queued;
		++$queued;
		++$in_flight;
	}

	$state['queued']        = $queued;
	$state['current_index'] = $queued;
	$state['updated_at']    = time();

	rfs_update_accelerated_sync_state( $state );

	foreach ( $to_enqueue as $item_index ) {
		if ( rfs_get_fresh_option( 'rfs_sync_deactivated', false ) || rfs_is_accelerated_sync_cancel_requested( $state['run_id'] ) ) { break; }
		if ( ! as_enqueue_async_action( 'rfs_accelerated_sync_step', array( $state['run_id'], $item_index ), 'rentfetch-accelerated-sync', false ) ) {
			rfs_retry_accelerated_sync_action( 'rfs_accelerated_sync_step', array( $state['run_id'], $item_index ) );
		}
	}

	return $state;
}

/**
 * Start an accelerated sync.
 *
 * @return array|WP_Error
 */
function rfs_start_accelerated_sync() {
	if ( rfs_is_sync_locked() ) {
		$access = rfs_check_sync_access();
		if ( is_wp_error( $access ) ) { return $access; }
	}
	$can_start = rfs_can_start_accelerated_sync();
	if ( is_wp_error( $can_start ) ) { return $can_start; }
	if ( function_exists( 'rfs_maybe_migrate_sync_action_args' ) ) { rfs_maybe_migrate_sync_action_args(); }
	$lock = rfs_acquire_accelerated_sync_lock( 'start' );
	if ( ! $lock ) { return new WP_Error( 'sync_state_busy', 'A sync state update is in progress. Try again shortly.' ); }
	try {
		$current = rfs_get_accelerated_sync_state();
		if ( 'running' === ( $current['status'] ?? '' ) && ! rfs_is_accelerated_sync_cancel_requested( $current['run_id'] ) && ( ! empty( $current['active_items'] ) || rfs_accelerated_sync_has_scheduled_work( $current['run_id'] ) ) ) {
			return $current;
		}
		$items = rfs_get_accelerated_sync_manifest();
		if ( ! $items ) { return new WP_Error( 'empty_manifest', 'No synced properties are configured.' ); }
		rfs_unschedule_accelerated_sync_actions();
		if ( ! empty( $current['run_id'] ) ) { rfs_clear_accelerated_sync_results( $current['run_id'] ); }
		update_option( 'rentfetch_options_data_sync', 'updatesync' );
		delete_transient( 'rentfetchsync_properties_limit' );
		if ( function_exists( 'rfs_clear_rentfetch_api_info_cache' ) ) {
			rfs_clear_rentfetch_api_info_cache();
		} else {
			delete_transient( 'rentfetch_api_info' );
			delete_transient( 'rentfetch_api_info_error' );
			delete_option( 'rentfetch_api_info_refresh_lock' );
		}
		$run_id = wp_generate_uuid4();
		rfs_clear_accelerated_sync_cancel_request( $run_id );
		$state = array(
			'run_id' => $run_id, 'status' => 'running', 'items' => array_values( $items ),
			'total' => count( $items ),
			'property_total' => count( array_filter( $items, static function( $item ) { return 'property' === ( $item['type'] ?? '' ); } ) ),
			'current_index' => 0, 'queued' => 0, 'completed' => 0, 'property_completed' => 0,
			'failed' => 0, 'property_failed' => 0, 'errors' => array(), 'current_label' => '',
			'active_items' => array(), 'finished_items' => array(), 'dispatch_ready' => false,
			'started_at' => time(), 'updated_at' => time(), 'completed_at' => 0,
		);
		$immediate = (bool) apply_filters( 'rfs_accelerated_sync_process_first_item_immediately', true, $state );
		if ( $immediate ) {
			$immediate_until = 0;
			foreach ( $state['items'] as $index => $item ) {
				if ( 'property' === ( $item['type'] ?? '' ) ) { $immediate_until = $index; break; }
			}
			$state['queued'] = $immediate_until + 1;
			$state['current_index'] = $state['queued'];
		}
		rfs_update_accelerated_sync_state( $state );
	} finally {
		rfs_release_accelerated_sync_lock( $lock );
	}
	if ( $immediate ) {
		for ( $index = 0; $index <= $immediate_until; ++$index ) {
			rfs_process_accelerated_sync_step( $run_id, $index, false );
		}
	}
	$lock = rfs_acquire_accelerated_sync_lock( $run_id );
	if ( ! $lock ) {
		rfs_retry_accelerated_sync_action( 'rfs_queue_accelerated_sync_run', array( $run_id ) );
		return rfs_get_accelerated_sync_state();
	}
	try {
		$state = rfs_get_accelerated_sync_state();
		if ( $run_id === ( $state['run_id'] ?? '' ) && 'running' === ( $state['status'] ?? '' ) ) {
			$state['dispatch_ready'] = true;
			$state = rfs_queue_accelerated_sync_workers( $state );
		}
		return $state;
	} finally {
		rfs_release_accelerated_sync_lock( $lock );
	}
}

/** Complete initial queue dispatch if another worker temporarily held the mutex. */
function rfs_queue_accelerated_sync_run( $run_id ) {
	if ( rfs_get_fresh_option( 'rfs_sync_deactivated', false ) ) { return; }
	$lock = rfs_acquire_accelerated_sync_lock( $run_id );
	if ( ! $lock ) { rfs_retry_accelerated_sync_action( 'rfs_queue_accelerated_sync_run', array( $run_id ) ); return; }
	try {
		$state = rfs_get_accelerated_sync_state();
		if ( $run_id === ( $state['run_id'] ?? '' ) && 'running' === ( $state['status'] ?? '' ) && ! rfs_is_accelerated_sync_cancel_requested( $run_id ) ) {
			$state['dispatch_ready'] = true;
			rfs_queue_accelerated_sync_workers( $state );
		}
	} finally { rfs_release_accelerated_sync_lock( $lock ); }
}
add_action( 'rfs_queue_accelerated_sync_run', 'rfs_queue_accelerated_sync_run' );

/**
 * Cancel an accelerated sync.
 *
 * @return array
 */
function rfs_cancel_accelerated_sync() {
	$state = rfs_get_accelerated_sync_state();
	if ( ! empty( $state['run_id'] ) ) { rfs_request_accelerated_sync_cancel( $state['run_id'] ); }
	rfs_unschedule_accelerated_sync_actions();
	$lock = rfs_acquire_accelerated_sync_lock( $state['run_id'] ?? '' );
	if ( ! $lock ) { return $state; }
	try {
		$state = rfs_mark_accelerated_sync_cancelled( rfs_get_accelerated_sync_state() );
		if ( ! empty( $state['run_id'] ) ) { rfs_clear_accelerated_sync_results( $state['run_id'] ); }
		return $state;
	} finally { rfs_release_accelerated_sync_lock( $lock ); }
}

/**
 * Process one accelerated sync item, then queue more work up to the concurrency limit.
 *
 * @param string $run_id Run ID.
 * @param int    $item_index Item index.
 * @param bool   $queue_next Whether to queue more work after this item.
 * @return void
 */
function rfs_process_accelerated_sync_step( $run_id, $item_index = null, $queue_next = true ) {
	if ( rfs_is_sync_locked() || rfs_get_fresh_option( 'rfs_sync_deactivated', false ) ) { return; }
	$lock = rfs_acquire_accelerated_sync_lock( $run_id );
	if ( ! $lock ) {
		rfs_retry_accelerated_sync_action( 'rfs_accelerated_sync_step', array( $run_id, $item_index ) );
		return;
	}
	try {
		$state = rfs_get_accelerated_sync_state();
		if ( ( $state['run_id'] ?? '' ) !== $run_id || 'running' !== ( $state['status'] ?? '' ) ) { return; }
		if ( rfs_is_accelerated_sync_cancel_requested( $run_id ) ) {
			rfs_mark_accelerated_sync_cancelled( $state );
			return;
		}
		if ( null === $item_index ) {
			$item_index = (int) ( $state['queued'] ?? $state['current_index'] ?? 0 );
			$state['queued'] = $item_index + 1;
			$state['current_index'] = $state['queued'];
		}
		$item_index = (int) $item_index;
		$item = $state['items'][ $item_index ] ?? null;
		if ( ! is_array( $item ) || ! empty( $state['finished_items'][ $item_index ] ) ) { return; }
		if ( is_array( rfs_get_fresh_option( rfs_accelerated_sync_result_key( $run_id, $item_index ) ) ) ) {
			rfs_retry_accelerated_sync_action( 'rfs_finalize_accelerated_sync_item', array( $run_id, $item_index ) );
			return;
		}
		if ( isset( $state['active_items'][ $item_index ] ) ) { return; }
		$state['active_items'][ $item_index ] = $item['label'] ?? 'Sync item';
		$state['current_label'] = implode( ', ', array_slice( array_values( $state['active_items'] ), 0, 3 ) );
		$state['updated_at'] = time();
		rfs_update_accelerated_sync_state( $state );
	} finally {
		rfs_release_accelerated_sync_lock( $lock );
	}
	try {
		if ( empty( $item['hook'] ) || ! isset( $item['args'] ) || ! is_array( $item['args'] ) ) {
			throw new RuntimeException( 'Accelerated sync item is missing a hook or args.' );
		}
		do_action_ref_array( $item['hook'], $item['args'] );
		$success = true;
		$error = '';
	} catch ( Throwable $exception ) {
		$success = false;
		$error = $exception->getMessage();
	}
	rfs_record_accelerated_sync_result( $run_id, $item_index, $success, $error, $queue_next );
}

add_action( 'rfs_accelerated_sync_step', 'rfs_process_accelerated_sync_step', 10, 2 );

/**
 * Get a compact status payload for UI updates.
 *
 * @param bool $include_finished Whether to include completed/cancelled run messages.
 * @return array
 */
function rfs_get_accelerated_sync_status_payload( $include_finished = false ) {
	$state     = rfs_get_accelerated_sync_state();
	$status    = isset( $state['status'] ) ? (string) $state['status'] : 'idle';
	$completed_at = isset( $state['completed_at'] ) ? (int) $state['completed_at'] : 0;
	$total     = isset( $state['total'] ) ? (int) $state['total'] : 0;
	$completed = isset( $state['completed'] ) ? (int) $state['completed'] : 0;
	$failed    = isset( $state['failed'] ) ? (int) $state['failed'] : 0;
	$active    = isset( $state['active_items'] ) && is_array( $state['active_items'] ) ? array_values( $state['active_items'] ) : array();
	$current   = 'running' === $status && ! empty( $active ) ? implode( ', ', array_slice( $active, 0, 3 ) ) : '';
	$items              = isset( $state['items'] ) && is_array( $state['items'] ) ? $state['items'] : array();
	$property_total     = isset( $state['property_total'] ) ? (int) $state['property_total'] : 0;
	$property_completed = isset( $state['property_completed'] ) ? (int) $state['property_completed'] : 0;
	$property_failed    = isset( $state['property_failed'] ) ? (int) $state['property_failed'] : 0;
	$property_active    = 0;
	$active_type_counts = array();
	$active_type_labels = array();
	$type_labels        = array(
		'property'  => 'property',
		'floorplan' => 'floor plan',
		'unit'      => 'unit',
	);

	if ( ! $property_total && ! empty( $items ) ) {
		foreach ( $items as $item ) {
			if ( isset( $item['type'] ) && 'property' === $item['type'] ) {
				++$property_total;
			}
		}
	}

	if ( isset( $state['active_items'] ) && is_array( $state['active_items'] ) ) {
		foreach ( array_keys( $state['active_items'] ) as $item_index ) {
			if ( isset( $items[ $item_index ]['type'] ) && 'property' === $items[ $item_index ]['type'] ) {
				++$property_active;
			}

			$item_type = isset( $items[ $item_index ]['type'] ) ? (string) $items[ $item_index ]['type'] : '';
			if ( '' !== $item_type ) {
				if ( ! isset( $active_type_counts[ $item_type ] ) ) {
					$active_type_counts[ $item_type ] = 0;
				}

				++$active_type_counts[ $item_type ];

				if ( ! isset( $active_type_labels[ $item_type ] ) ) {
					$active_type_labels[ $item_type ] = array();
				}

				if ( isset( $state['active_items'][ $item_index ] ) && '' !== (string) $state['active_items'][ $item_index ] ) {
					$active_type_labels[ $item_type ][] = (string) $state['active_items'][ $item_index ];
				}
			}
		}
	}

	if (
		in_array( $status, array( 'complete', 'cancelled' ), true )
		&& ( ! $include_finished || ( $completed_at > 0 && ( time() - $completed_at ) > MINUTE_IN_SECONDS ) )
	) {
		$status = 'idle';
	}

	if ( rfs_is_sync_locked() ) {
		$status  = 'locked';
		$current = '';
		$message = rfs_sync_locked_message();
	} elseif ( 'running' === $status ) {
		$active_type = '';
		foreach ( array( 'property', 'floorplan', 'unit' ) as $type ) {
			if ( ! empty( $active_type_counts[ $type ] ) ) {
				$active_type = $type;
				break;
			}
		}

		if ( '' !== $active_type ) {
			$type_label   = isset( $type_labels[ $active_type ] ) ? $type_labels[ $active_type ] : str_replace( '_', ' ', $active_type );
			$item_label   = ! empty( $active_type_labels[ $active_type ] ) ? $active_type_labels[ $active_type ][0] : '';
			$active_count = max( 1, (int) $active_type_counts[ $active_type ] );
			$type_total   = 'property' === $active_type && $property_total > 0 ? $property_total : $total;
			$type_done    = 'property' === $active_type ? $property_completed + $property_failed : $completed + $failed;
			$position     = sprintf( '%1$s %2$d of %3$d', $type_label, min( $type_done + $active_count, $type_total ), $type_total );
			$message      = '' !== $item_label
				? sprintf( 'Syncing %1$s, %2$s', $item_label, $position )
				: sprintf( 'Syncing %s', $position );
		} elseif ( $property_total > 0 ) {
			$active_count = max( 1, $property_active );
			$message      = sprintf(
				'%1$s property %2$d of %3$d',
				$property_active > 0 ? 'Syncing' : 'Waiting to sync',
				min( $property_completed + $property_failed + $active_count, $property_total ),
				$property_total
			);
		} else {
			$active_count = max( 1, count( $active ) );
			$message      = sprintf(
				'%1$s %2$d of %3$d',
				count( $active ) > 0 ? 'Syncing' : 'Waiting to sync',
				min( $completed + $failed + $active_count, $total ),
				$total
			);
		}
	} elseif ( 'complete' === $status ) {
		$synced_count = $property_total > 0 ? $property_completed : $completed;
		$failed_count = $property_total > 0 ? max( $property_failed, $failed ) : $failed;
		$message      = sprintf( 'Sync complete. %1$d synced%2$s.', $synced_count, $failed_count ? sprintf( ', %d failed', $failed_count ) : '' );
	} elseif ( 'cancelled' === $status ) {
		$message = 'Accelerated sync cancelled.';
	} else {
		$message = 'Ready to run full sync.';
	}

	return array(
		'status'    => $status,
		'message'   => $message,
		'current'   => $current,
		'total'     => $total,
		'completed' => $completed,
		'failed'    => $failed,
		'running'   => 'running' === $status,
		'sync_locked' => rfs_is_sync_locked(),
		'sync_enabled' => 'updatesync' === get_option( 'rentfetch_options_data_sync' ),
		'errors'    => isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array(),
	);
}

/**
 * Render a status label.
 *
 * @param bool $include_finished Whether to include completed/cancelled run messages.
 * @return string
 */
function rfs_get_accelerated_sync_status_title( $include_finished = false ) {
	$status = rfs_get_accelerated_sync_status_payload( $include_finished );
	$detail = 'running' === $status['status'] && $status['current'] ? $status['current'] : '';

	if ( 'idle' === $status['status'] ) {
		return '';
	}

	return sprintf(
		'<span class="rfs-accelerated-sync-status"><span class="rfs-accelerated-sync-message">%1$s</span><span class="rfs-accelerated-sync-current">%2$s</span></span>',
		esc_html( $status['message'] ),
		$detail ? esc_html( 'Current: ' . $detail ) : ''
	);
}

/**
 * Render accelerated sync controls inside the custom RentFetch admin bar panel.
 *
 * @return void
 */
function rfs_render_accelerated_sync_admin_bar_panel() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$title = function_exists( 'rentfetch_get_admin_bar_section_title' )
		? rentfetch_get_admin_bar_section_title( 'Sync' )
		: '<span class="rentfetch-admin-bar-section-title">SYNC</span>';
	$status_title = rfs_get_accelerated_sync_status_title();
	$status_is_empty = '' === $status_title;
	if ( '' === $status_title ) {
		$status_title = '<span class="rfs-accelerated-sync-status is-empty"><span class="rfs-accelerated-sync-message"></span><span class="rfs-accelerated-sync-current"></span></span>';
	}
	?>
	<div id="wp-admin-bar-rentfetch-sync-section" class="rentfetch-admin-bar-section rentfetch-admin-bar-sync-section">
		<?php echo wp_kses_post( $title ); ?>
	</div>
	<div id="wp-admin-bar-rentfetch-sync-status" class="<?php echo esc_attr( $status_is_empty ? 'rentfetch-admin-bar-sync-status is-empty' : 'rentfetch-admin-bar-sync-status' ); ?>">
		<?php echo wp_kses_post( $status_title ); ?>
	</div>
	<div class="<?php echo esc_attr( rfs_get_accelerated_sync_status_payload()['running'] ? 'rfs-accelerated-sync-admin-actions is-running' : 'rfs-accelerated-sync-admin-actions' ); ?>">
		<button type="button" id="wp-admin-bar-rentfetch-sync-run-full" class="rfs-accelerated-sync-admin-button rfs-accelerated-sync-start">Run full sync now</button>
		<button type="button" id="wp-admin-bar-rentfetch-sync-cancel" class="rfs-accelerated-sync-admin-button rfs-accelerated-sync-cancel">Cancel sync</button>
	</div>
	<?php
}
add_action( 'rentfetch_admin_bar_panel_after_content', 'rfs_render_accelerated_sync_admin_bar_panel', 10, 0 );

/**
 * Render accelerated sync controls in the Sync settings section.
 *
 * @return void
 */
function rfs_render_accelerated_sync_settings_controls() {
	$status        = rfs_get_accelerated_sync_status_payload();
	$controls_class = $status['running'] ? 'rfs-accelerated-sync-controls is-running' : 'rfs-accelerated-sync-controls';
	$status_title = rfs_get_accelerated_sync_status_title();
	$status_class = '' === $status_title ? 'description rfs-accelerated-sync-status is-empty' : 'description rfs-accelerated-sync-status';
	if ( '' === $status_title ) {
		$status_title = '<span class="rfs-accelerated-sync-message"></span><span class="rfs-accelerated-sync-current"></span>';
	}
	?>
	<p class="<?php echo esc_attr( $controls_class ); ?>">
		<a href="#" class="button rfs-accelerated-sync-start">Run full sync now</a>
		<a href="#" class="button rfs-accelerated-sync-cancel">Cancel sync</a>
	</p>
	<p class="<?php echo esc_attr( $status_class ); ?>"><?php echo wp_kses_post( $status_title ); ?></p>
	<?php
}

/**
 * Handle start accelerated sync AJAX.
 *
 * @return void
 */
function rfs_start_accelerated_sync_ajax_handler() {
	check_ajax_referer( 'rfs_accelerated_sync', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to start sync.' ), 403 );
	}

	$state = rfs_start_accelerated_sync();
	if ( is_wp_error( $state ) ) {
		wp_send_json_error( rfs_is_sync_locked() ? rfs_get_accelerated_sync_status_payload( true ) : array( 'message' => $state->get_error_message() ), 400 );
	}

	wp_send_json_success( rfs_get_accelerated_sync_status_payload( true ) );
}
add_action( 'wp_ajax_rfs_start_accelerated_sync', 'rfs_start_accelerated_sync_ajax_handler' );

/**
 * Handle cancel accelerated sync AJAX.
 *
 * @return void
 */
function rfs_cancel_accelerated_sync_ajax_handler() {
	check_ajax_referer( 'rfs_accelerated_sync', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to cancel sync.' ), 403 );
	}

	rfs_cancel_accelerated_sync();

	wp_send_json_success( rfs_get_accelerated_sync_status_payload( true ) );
}
add_action( 'wp_ajax_rfs_cancel_accelerated_sync', 'rfs_cancel_accelerated_sync_ajax_handler' );

/**
 * Handle accelerated sync status AJAX.
 *
 * @return void
 */
function rfs_get_accelerated_sync_status_ajax_handler() {
	check_ajax_referer( 'rfs_accelerated_sync', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to view sync status.' ), 403 );
	}

	$include_finished = isset( $_POST['include_finished'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['include_finished'] ) );

	wp_send_json_success( rfs_get_accelerated_sync_status_payload( $include_finished ) );
}
add_action( 'wp_ajax_rfs_get_accelerated_sync_status', 'rfs_get_accelerated_sync_status_ajax_handler' );

/**
 * Print accelerated sync admin bar styles.
 *
 * @return void
 */
function rfs_accelerated_sync_admin_styles() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<style>
		.rfs-accelerated-sync-controls {
			align-items: center;
			display: flex;
			gap: 10px;
			margin: 14px 0 12px;
		}

		.rfs-accelerated-sync-controls .button.rfs-accelerated-sync-start,
		.rfs-accelerated-sync-controls .button.rfs-accelerated-sync-cancel {
			border-radius: 5px;
			font-weight: 600;
			padding: 5px 18px;
		}

		.rfs-accelerated-sync-controls .button.rfs-accelerated-sync-start {
			background: #1f313b;
			border-color: #1f313b;
			color: #fff;
		}

		.rfs-accelerated-sync-controls .button.rfs-accelerated-sync-start:hover,
		.rfs-accelerated-sync-controls .button.rfs-accelerated-sync-start:focus {
			background: #375565;
			border-color: #375565;
			color: #fff;
		}

		.rfs-accelerated-sync-controls .button.rfs-accelerated-sync-cancel {
			background: #fff;
			border-color: #c3c4c7;
			color: #1f313b;
			display: none;
		}

		.rfs-accelerated-sync-controls.is-running .button.rfs-accelerated-sync-start {
			display: none;
		}

		.rfs-accelerated-sync-controls.is-running .button.rfs-accelerated-sync-cancel {
			display: inline-flex;
		}

		.rfs-accelerated-sync-controls .button.rfs-accelerated-sync-cancel:hover,
		.rfs-accelerated-sync-controls .button.rfs-accelerated-sync-cancel:focus {
			background: #f6f7f7;
			border-color: #8c8f94;
			color: #1f313b;
		}

		form.rent-fetch-options .rfs-accelerated-sync-status,
		form.rent-fetch-options .rfs-accelerated-sync-message,
		form.rent-fetch-options .rfs-accelerated-sync-current {
			display: block;
			line-height: 1.35;
		}

		form.rent-fetch-options .rfs-accelerated-sync-status {
			margin-top: 10px;
		}

		form.rent-fetch-options .rfs-accelerated-sync-status.is-empty {
			display: none;
		}

		form.rent-fetch-options .rfs-accelerated-sync-message {
			font-weight: 600;
		}

		form.rent-fetch-options .rfs-accelerated-sync-current {
			font-size: 13px;
			margin-top: 4px;
			opacity: 0.8;
		}

		#wpadminbar #wp-admin-bar-rentfetch-sync-status {
			color: #a7aaad;
			cursor: default;
			font-size: 11px;
			height: auto;
			line-height: 1.25;
			min-width: 220px;
			padding-bottom: 7px;
			padding-top: 0;
			white-space: normal;
		}

		#wpadminbar #wp-admin-bar-rentfetch-sync-status:hover,
		#wpadminbar #wp-admin-bar-rentfetch-sync-status:focus {
			background: transparent;
			color: #a7aaad;
		}

		#wpadminbar #wp-admin-bar-rentfetch-sync-status.is-empty {
			display: none;
		}

		#wpadminbar #wp-admin-bar-rentfetch-admin-bar .rfs-accelerated-sync-status,
		#wpadminbar #wp-admin-bar-rentfetch-admin-bar .rfs-accelerated-sync-message,
		#wpadminbar #wp-admin-bar-rentfetch-admin-bar .rfs-accelerated-sync-current {
			display: block;
			line-height: 1.25;
		}

		#wpadminbar #wp-admin-bar-rentfetch-admin-bar .rfs-accelerated-sync-message {
			color: #dcdcde;
			font-weight: 600;
		}

		#wpadminbar #wp-admin-bar-rentfetch-admin-bar .rfs-accelerated-sync-current {
			color: #a7aaad;
			font-size: 10px;
			margin-top: 1px;
		}

		#wpadminbar #wp-admin-bar-rentfetch-admin-bar .rfs-accelerated-sync-admin-actions {
			margin: 4px 0 6px;
		}

		#wpadminbar #wp-admin-bar-rentfetch-admin-bar .rfs-accelerated-sync-admin-actions.is-running .rfs-accelerated-sync-start,
		#wpadminbar #wp-admin-bar-rentfetch-admin-bar .rfs-accelerated-sync-admin-actions:not(.is-running) .rfs-accelerated-sync-cancel {
			display: none;
		}

		#wpadminbar #wp-admin-bar-rentfetch-admin-bar button.rfs-accelerated-sync-admin-button {
			appearance: none;
			background: #1f313b;
			border: 0;
			border-radius: 4px;
			box-shadow: none;
			color: #fff;
			display: inline-flex;
			font-size: 11px;
			font-weight: 600;
			height: auto;
			line-height: 1.2;
			margin: 0;
			min-width: 0;
			padding: 7px 12px;
			text-decoration: none;
			width: auto;
		}

		#wpadminbar #wp-admin-bar-rentfetch-admin-bar button.rfs-accelerated-sync-admin-button:hover,
		#wpadminbar #wp-admin-bar-rentfetch-admin-bar button.rfs-accelerated-sync-admin-button:focus {
			background: #1f313b;
			box-shadow: none;
			color: #fff;
			outline: none;
			text-decoration: none;
		}
	</style>
	<?php
}
add_action( 'admin_head', 'rfs_accelerated_sync_admin_styles' );
add_action( 'wp_head', 'rfs_accelerated_sync_admin_styles' );

/**
 * Print accelerated sync admin script.
 *
 * @return void
 */
function rfs_accelerated_sync_admin_script() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$config = array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'rfs_accelerated_sync' ),
	);
	?>
	<script>
	(function() {
		var config = <?php echo wp_json_encode( $config ); ?>;
		var pollTimer = null;
		var clearTimer = null;
		var includeFinishedStatus = false;

		function post(action) {
			var body = new window.FormData();
			body.append('action', action);
			body.append('nonce', config.nonce);
			if (includeFinishedStatus) {
				body.append('include_finished', '1');
			}

			return window.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			}).then(function(response) {
				return response.json();
			});
		}

		function setStartDisabled(disabled) {
			document.querySelectorAll('.rfs-accelerated-sync-start').forEach(function(control) {
				if (disabled) {
					control.setAttribute('aria-disabled', 'true');
					control.setAttribute('tabindex', '-1');
				} else {
					control.removeAttribute('aria-disabled');
					control.removeAttribute('tabindex');
				}

				if ('disabled' in control) {
					control.disabled = !!disabled;
				}
			});
		}

		function setStatus(payload) {
			if (!payload) {
				return;
			}

			if (payload.sync_locked === true) {
				document.dispatchEvent(new Event('rfs-sync-locked'));
			}

			var resume = document.getElementById('rentfetch_options_data_sync_updatesync');
			if (payload.sync_locked === false && (document.querySelector('.rfs-sync-locked-notice') || (resume && resume.disabled))) {
				document.querySelectorAll('.rfs-sync-locked-notice').forEach(function(notice) {
					notice.remove();
				});
				if (resume) resume.disabled = false;
				if (typeof payload.sync_enabled === 'boolean') {
					document.querySelectorAll('input[name="rentfetch_options_data_sync"]').forEach(function(input) {
						input.checked = input.value === (payload.sync_enabled ? 'updatesync' : 'nosync');
					});
				}
			}

			if (clearTimer) {
				window.clearTimeout(clearTimer);
				clearTimer = null;
			}

			document.querySelectorAll('.rentfetch-admin-bar-sync-status').forEach(function(status) {
				status.classList.toggle('is-empty', payload.status === 'idle' && !payload.current);
			});

			document.querySelectorAll('.rfs-accelerated-sync-status').forEach(function(status) {
				var message = status.querySelector('.rfs-accelerated-sync-message');
				var current = status.querySelector('.rfs-accelerated-sync-current');
				var shouldHide = payload.status === 'idle' && !payload.current;

				status.classList.toggle('is-empty', shouldHide);

				if (message) {
					message.textContent = payload.message || '';
				} else {
					status.textContent = payload.message || '';
				}

				if (current) {
					current.textContent = payload.current ? 'Current: ' + payload.current : '';
				}
			});

			document.querySelectorAll('.rfs-accelerated-sync-controls').forEach(function(controls) {
				controls.classList.toggle('is-running', payload.status === 'running');
			});

			document.querySelectorAll('.rfs-accelerated-sync-admin-actions').forEach(function(actions) {
				actions.classList.toggle('is-running', payload.status === 'running');
			});

			setStartDisabled(payload.status === 'running');

			if (payload.status === 'running') {
				includeFinishedStatus = true;
				startPolling();
			} else {
				stopPolling();
				if (payload.status === 'complete' || payload.status === 'cancelled') {
					clearTimer = window.setTimeout(function() {
						includeFinishedStatus = false;
						setStatus({
							status: 'idle',
							message: 'Ready to run full sync.',
							current: ''
						});
					}, 7000);
				}
			}
		}

		function pollStatus() {
			post('rfs_get_accelerated_sync_status')
				.then(function(response) {
					if (response && response.success) {
						setStatus(response.data);
					}
				})
				.catch(function() {});
		}

		function startPolling() {
			if (pollTimer) {
				return;
			}

			pollTimer = window.setInterval(pollStatus, 3000);
		}

		function stopPolling() {
			if (!pollTimer) {
				return;
			}

			window.clearInterval(pollTimer);
			pollTimer = null;
		}

		document.addEventListener('click', function(event) {
			var start = event.target.closest('.rfs-accelerated-sync-start');
			var cancel = event.target.closest('.rfs-accelerated-sync-cancel');

			if (!start && !cancel) {
				return;
			}

			event.preventDefault();

			if (start && start.getAttribute('aria-disabled') === 'true') {
				return;
			}

			if (start) {
				includeFinishedStatus = true;
				setStartDisabled(true);
				setStatus({
					status: 'running',
					message: 'Starting full sync...',
					current: 'Waiting for the sync queue to start'
				});
			} else if (cancel) {
				includeFinishedStatus = true;
				setStatus({
					status: 'running',
					message: 'Cancelling sync...',
					current: ''
				});
			}

			post(start ? 'rfs_start_accelerated_sync' : 'rfs_cancel_accelerated_sync')
				.then(function(response) {
					var data = response && response.data ? response.data : {};

					if (response && response.success) {
						setStatus(data);
					} else {
						setStatus({
							status: data.sync_locked ? 'locked' : 'error',
							sync_locked: data.sync_locked,
							message: data.message || 'Sync request failed.',
							current: ''
						});
					}
				})
				.catch(function() {
					setStatus({
						status: 'error',
						message: 'Sync request failed.',
						current: ''
					});
				});
		});

		document.addEventListener('rfs-sync-access-checked', function(event) {
			setStatus(event.detail);
		});

		pollStatus();
	}());
	</script>
	<?php
}
add_action( 'admin_footer', 'rfs_accelerated_sync_admin_script' );
add_action( 'wp_footer', 'rfs_accelerated_sync_admin_script' );
