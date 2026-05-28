<?php
/**
 * Configure Action Scheduler cleanup for Rent Fetch sites.
 *
 * Action Scheduler defaults to keeping completed and canceled actions for 31
 * days and does not purge failed actions. Rent Fetch Sync can create many
 * routine actions, so keep only a shorter operational history.
 *
 * @package RentFetchSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep completed, canceled, and failed scheduled actions for one week.
 *
 * @param int $retention_period Retention period in seconds.
 *
 * @return int
 */
function rfs_action_scheduler_retention_period( $retention_period ) {
	return (int) apply_filters( 'rentfetch_sync_action_scheduler_retention_period', WEEK_IN_SECONDS );
}
add_filter( 'action_scheduler_retention_period', 'rfs_action_scheduler_retention_period' );

/**
 * Include failed actions in the standard Action Scheduler cleanup pass.
 *
 * @param array $statuses Statuses eligible for cleanup.
 *
 * @return array
 */
function rfs_action_scheduler_cleaner_statuses( $statuses ) {
	$statuses[] = 'complete';
	$statuses[] = 'canceled';
	$statuses[] = 'failed';

	return array_values( array_unique( $statuses ) );
}
add_filter( 'action_scheduler_default_cleaner_statuses', 'rfs_action_scheduler_cleaner_statuses' );

/**
 * Delete old scheduled action records in larger batches.
 *
 * @param int $batch_size Number of actions to delete per status.
 *
 * @return int
 */
function rfs_action_scheduler_cleanup_batch_size( $batch_size ) {
	return (int) apply_filters( 'rentfetch_sync_action_scheduler_cleanup_batch_size', max( 200, (int) $batch_size ) );
}
add_filter( 'action_scheduler_cleanup_batch_size', 'rfs_action_scheduler_cleanup_batch_size' );
