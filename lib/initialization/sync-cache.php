<?php
/** Refresh frontend caches at manual-sync or complete background-cycle boundaries. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Identify a property or cleanup job without storing runtime credentials. */
function rfs_get_sync_cache_item_key( $hook, $args ) {
	if ( 'rfs_do_sync' === $hook ) {
		$property = $args[0] ?? array();
		if ( empty( $property['integration'] ) || ! isset( $property['property_id'] ) ) {
			return '';
		}
		return wp_json_encode( array( $property['integration'], (string) $property['property_id'] ) );
	}
	if ( in_array( $hook, array( 'rfs_yardi_do_delete_orphans', 'rfs_entrata_do_delete_orphans', 'rfs_engrain_do_delete_orphans', 'rfs_rentegi_do_delete_orphans' ), true ) ) {
		return $hook;
	}
	return '';
}

/** Use the shared full-sync manifest as the expected background pass. */
function rfs_get_sync_cache_expected_items() {
	$expected = array();
	foreach ( rfs_get_accelerated_sync_manifest() as $item ) {
		$key = rfs_get_sync_cache_item_key( $item['hook'], $item['args'] );
		if ( '' !== $key ) {
			$expected[ $key ] = true;
		}
	}
	// Normal scheduling includes orphan cleanup even when a provider's list is empty.
	$enabled = (array) get_option( 'rentfetch_options_enabled_integrations', array() );
	foreach ( array( 'yardi', 'entrata', 'engrain', 'rentegi' ) as $integration ) {
		if ( 'rentegi' === $integration && ! rfs_rentegi_core_is_compatible() ) {
			continue;
		}
		if ( in_array( $integration, $enabled, true ) ) {
			$expected[ 'rfs_' . $integration . '_do_delete_orphans' ] = true;
		}
	}
	return $expected;
}

/** Invalidate only Rent Fetch query/markup caches, never the site's object cache. */
function rfs_clear_frontend_sync_caches() {
	if ( function_exists( 'rentfetch_clear_search_cache_transients' ) ) {
		rentfetch_clear_search_cache_transients();
	}
}
add_action( 'rfs_manual_sync_finished', 'rfs_clear_frontend_sync_caches' );
add_action( 'rfs_sync_cycle_finished', 'rfs_clear_frontend_sync_caches' );

/**
 * Record one terminal recurring job; failures count as finished attempts too.
 *
 * Independent recurring jobs form a pass only when every configured property and
 * cleanup has finished. Repeated fast jobs cannot flush caches while others wait.
 */
function rfs_record_sync_cache_completion( $action_id ) {
	if ( rfs_get_fresh_option( 'rfs_sync_deactivated', false ) ) { return; }
	$action = ActionScheduler::store()->fetch_action( $action_id );
	if ( 'rentfetch' !== $action->get_group() || ! $action->get_schedule()->is_recurring() ) {
		return;
	}
	$key = rfs_get_sync_cache_item_key( $action->get_hook(), $action->get_args() );
	if ( '' === $key ) {
		return;
	}

	global $wpdb;
	$lock = 'rfs_cache_' . md5( DB_NAME . ':' . $wpdb->options );
	// A connection-owned lock serializes workers without an expiring option race.
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
		rfs_retry_sync_cache_completion( $action_id );
		return;
	}

	try {
		$expected = rfs_get_sync_cache_expected_items();
		if ( ! isset( $expected[ $key ] ) ) {
			return;
		}
		$option = 'rfs_recurring_sync_cache_cycle';
		// Another worker may have changed the option since this request bootstrapped.
		wp_cache_delete( $option, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		$state = get_option( $option, array() );
		$state = is_array( $state ) ? $state : array();
		$last  = array_intersect_key( (array) ( $state['last_actions'] ?? array() ), $expected );
		if ( (int) ( $last[ $key ] ?? 0 ) >= (int) $action_id ) {
			return;
		}
		$seen         = array_intersect_key( (array) ( $state['seen'] ?? array() ), $expected );
		$seen[ $key ] = true;
		$last[ $key ] = (int) $action_id;
		$state        = array( 'seen' => $seen, 'last_actions' => $last );
		if ( ! array_diff_key( $expected, $seen ) ) {
			do_action( 'rfs_sync_cycle_finished', 'recurring', $state );
			$state['seen'] = array();
		}
		if ( ! update_option( $option, $state, false ) && get_option( $option ) !== $state ) {
			rfs_retry_sync_cache_completion( $action_id );
		}
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

/** Preserve completion notifications if another worker temporarily holds the lock. */
function rfs_retry_sync_cache_completion( $action_id ) {
	if ( rfs_get_fresh_option( 'rfs_sync_deactivated', false ) ) { return; }
	$args = array( (int) $action_id );
	if ( ! wp_next_scheduled( 'rfs_record_sync_cache_completion', $args ) ) {
		wp_schedule_single_event( time() + 30, 'rfs_record_sync_cache_completion', $args );
	}
}
add_action( 'rfs_record_sync_cache_completion', 'rfs_record_sync_cache_completion' );
add_action( 'action_scheduler_after_execute', 'rfs_record_sync_cache_completion' );
add_action( 'action_scheduler_failed_execution', 'rfs_record_sync_cache_completion' );
add_action( 'action_scheduler_unexpected_shutdown', 'rfs_record_sync_cache_completion' );
