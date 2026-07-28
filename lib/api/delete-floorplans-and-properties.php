<?php
/**
 * Queue-safe deletion of synced Rent Fetch data.
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Normalize the legacy delete sync mode so deletes can only happen through confirmation.
 *
 * @return void
 */
function rfs_normalize_legacy_delete_sync_mode() {
	if ( 'delete' === get_option( 'rentfetch_options_data_sync' ) ) {
		update_option( 'rentfetch_options_data_sync', 'nosync' );
	}
}
add_action( 'wp_loaded', 'rfs_normalize_legacy_delete_sync_mode', 1 );

/**
 * Get source meta keys for synced content deletion.
 *
 * @return array
 */
function rfs_get_synced_data_delete_types() {
	return array(
		'units'      => array(
			'label'    => 'units',
			'meta_key' => 'unit_source',
		),
		'floorplans' => array(
			'label'    => 'floor plans',
			'meta_key' => 'floorplan_source',
		),
		'properties' => array(
			'label'    => 'properties',
			'meta_key' => 'property_source',
		),
	);
}

/**
 * Get delete queue state option key.
 *
 * @return string
 */
function rfs_get_delete_synced_data_state_key() {
	return 'rfs_delete_synced_data_state';
}

/**
 * Get delete queue state.
 *
 * @return array
 */
function rfs_get_delete_synced_data_state() {
	$state = get_option( rfs_get_delete_synced_data_state_key(), array() );

	return is_array( $state ) ? $state : array();
}

/**
 * Persist delete queue state.
 *
 * @param array $state State to persist.
 * @return void
 */
function rfs_update_delete_synced_data_state( $state ) {
	update_option( rfs_get_delete_synced_data_state_key(), is_array( $state ) ? $state : array(), false );
}

/**
 * Count synced posts by post type.
 *
 * @return array
 */
function rfs_count_synced_data_for_delete() {
	$counts = array();

	foreach ( rfs_get_synced_data_delete_types() as $post_type => $config ) {
		$query = new WP_Query(
			array(
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => $config['meta_key'],
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'  => false,
				'post_status'    => 'any',
				'post_type'      => $post_type,
				'posts_per_page' => 1,
			)
		);

		$counts[ $post_type ] = (int) $query->found_posts;
	}

	return $counts;
}

/**
 * Get a readable delete count breakdown.
 *
 * @param array      $counts Source counts by post type.
 * @param array|null $deleted Optional deleted counts by post type.
 * @return string
 */
function rfs_get_delete_synced_data_breakdown_label( $counts, $deleted = null ) {
	$labels = array(
		'properties' => 'Properties',
		'floorplans' => 'Floor plans',
		'units'      => 'Units',
	);
	$parts  = array();

	foreach ( $labels as $post_type => $label ) {
		$count = isset( $counts[ $post_type ] ) ? (int) $counts[ $post_type ] : 0;

		if ( is_array( $deleted ) ) {
			$deleted_count = isset( $deleted[ $post_type ] ) ? (int) $deleted[ $post_type ] : 0;
			$parts[]       = sprintf( '%1$s %2$s/%3$s', $label, number_format_i18n( $deleted_count ), number_format_i18n( $count ) );
		} else {
			$parts[] = sprintf( '%1$s %2$s', $label, number_format_i18n( $count ) );
		}
	}

	return implode( ' | ', $parts );
}

/**
 * Get the next batch of synced post IDs to delete.
 *
 * @param string $post_type Post type.
 * @param string $meta_key Source meta key.
 * @param int    $limit Batch size.
 * @param int[]  $exclude Post IDs to skip.
 * @return int[]
 */
function rfs_get_synced_data_delete_batch_ids( $post_type, $meta_key, $limit, $exclude = array() ) {
	$query = new WP_Query(
		array(
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => $meta_key,
					'compare' => 'EXISTS',
				),
			),
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'post__not_in'   => array_map( 'intval', (array) $exclude ),
			'post_status'    => 'any',
			'post_type'      => $post_type,
			'posts_per_page' => max( 1, (int) $limit ),
		)
	);

	return array_map( 'intval', $query->posts );
}

/**
 * Check whether a delete queue still has scheduled work.
 *
 * @return bool
 */
function rfs_delete_synced_data_has_scheduled_work() {
	if ( ! function_exists( 'as_has_scheduled_action' ) ) {
		return false;
	}

	return as_has_scheduled_action( 'rfs_delete_synced_data_step', null, 'rentfetch-delete-synced-data' );
}

/**
 * Schedule a delayed fallback delete step if browser-driven polling stops.
 *
 * @param string $run_id Run ID.
 * @return void
 */
function rfs_schedule_delete_synced_data_fallback( $run_id ) {
	if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
		return;
	}

	$args = array( $run_id );
	if ( as_has_scheduled_action( 'rfs_delete_synced_data_step', $args, 'rentfetch-delete-synced-data' ) ) {
		return;
	}

	as_schedule_single_action( time() + 60, 'rfs_delete_synced_data_step', $args, 'rentfetch-delete-synced-data' );
}

/**
 * Clear delayed fallback delete work for a run.
 *
 * @param string $run_id Run ID.
 * @return void
 */
function rfs_clear_delete_synced_data_fallback( $run_id ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'rfs_delete_synced_data_step', array( $run_id ), 'rentfetch-delete-synced-data' );
	}
}

/**
 * Start queued deletion of synced data.
 *
 * @param string $confirmation Confirmation text.
 * @return array|WP_Error
 */
function rfs_start_delete_synced_data( $confirmation ) {
	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		return new WP_Error( 'action_scheduler_unavailable', 'Action Scheduler is not available.' );
	}

	if ( 'delete' !== strtolower( trim( (string) $confirmation ) ) ) {
		return new WP_Error( 'confirmation_required', 'Type delete to confirm.' );
	}

	$current_state = rfs_get_delete_synced_data_state();
	if (
		isset( $current_state['status'] )
		&& 'running' === $current_state['status']
		&& rfs_delete_synced_data_has_scheduled_work()
	) {
		return $current_state;
	}

	$counts = rfs_count_synced_data_for_delete();
	$total  = array_sum( $counts );

	if ( $total <= 0 ) {
		return new WP_Error( 'nothing_to_delete', 'No synced properties, floor plans, or units were found.' );
	}

	update_option( 'rentfetch_options_data_sync', 'nosync' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'rfs_do_sync' );
		as_unschedule_all_actions( 'rfs_yardi_do_delete_orphans' );
		as_unschedule_all_actions( 'rfs_engrain_do_delete_orphans' );
	}

	if ( function_exists( 'rfs_cancel_accelerated_sync' ) ) {
		rfs_cancel_accelerated_sync();
	} elseif ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'rfs_accelerated_sync_step' );
	}

	$now   = time();
	$state = array(
		'run_id'       => wp_generate_uuid4(),
		'status'       => 'running',
		'total'        => $total,
		'counts'       => $counts,
		'deleted'      => array_fill_keys( array_keys( rfs_get_synced_data_delete_types() ), 0 ),
		'current_type' => 'units',
		'errors'       => array(),
		'skipped'      => array_fill_keys( array_keys( rfs_get_synced_data_delete_types() ), array() ),
		'started_at'   => $now,
		'updated_at'   => $now,
		'completed_at' => 0,
	);

	rfs_update_delete_synced_data_state( $state );

	rfs_process_delete_synced_data_step( $state['run_id'], 1.0, 3 );
	rfs_schedule_delete_synced_data_fallback( $state['run_id'] );
	$state = rfs_get_delete_synced_data_state();

	return $state;
}

/**
 * Process delete batches for a short time slice.
 *
 * @param string $run_id Run ID.
 * @param float|null $time_budget_override Optional time budget in seconds.
 * @param int|null   $max_batches Optional maximum batch count.
 * @param bool       $schedule_next Whether to schedule fallback continuation work.
 * @return void
 */
function rfs_process_delete_synced_data_step( $run_id, $time_budget_override = null, $max_batches = null, $schedule_next = true ) {
	$state = rfs_get_delete_synced_data_state();

	if (
		empty( $state['run_id'] )
		|| $state['run_id'] !== $run_id
		|| empty( $state['status'] )
		|| 'running' !== $state['status']
	) {
		return;
	}

	$started_at     = microtime( true );
	$time_budget    = null === $time_budget_override
		? max( 0.25, min( 20, (float) apply_filters( 'rfs_delete_synced_data_time_budget', 1.25, $state ) ) )
		: max( 0.1, min( 20, (float) $time_budget_override ) );
	$types      = rfs_get_synced_data_delete_types();
	$batch_size = max( 1, min( 100, (int) apply_filters( 'rfs_delete_synced_data_batch_size', 25, $state ) ) );
	$max_batches = null === $max_batches
		? max( 1, min( 10, (int) apply_filters( 'rfs_delete_synced_data_batches_per_step', 3, $state ) ) )
		: max( 1, min( 10, (int) $max_batches ) );
	$total_processed = 0;
	$batches_processed = 0;

	do {
		$processed = 0;

		foreach ( $types as $post_type => $config ) {
			$skipped_ids = isset( $state['skipped'][ $post_type ] ) && is_array( $state['skipped'][ $post_type ] )
				? $state['skipped'][ $post_type ]
				: array();
			$ids         = rfs_get_synced_data_delete_batch_ids( $post_type, $config['meta_key'], $batch_size, $skipped_ids );
			if ( empty( $ids ) ) {
				continue;
			}

			$state['current_type'] = $post_type;

			foreach ( $ids as $post_id ) {
				$deleted = wp_delete_post( $post_id, true );
				if ( $deleted ) {
					++$state['deleted'][ $post_type ];
				} else {
					$state['skipped'][ $post_type ][] = $post_id;
					$state['errors'][] = array(
						'post_id'   => $post_id,
						'post_type' => $post_type,
						'message'   => 'WordPress did not delete the post.',
					);
					$state['errors'] = array_slice( $state['errors'], -20 );
				}

				++$processed;
				++$total_processed;
			}

			break;
		}

		$state['updated_at'] = time();
		rfs_update_delete_synced_data_state( $state );
		++$batches_processed;
	} while (
		$processed > 0
		&& ( microtime( true ) - $started_at ) < $time_budget
		&& $batches_processed < $max_batches
	);

	$state['updated_at'] = time();

	if ( 0 === $total_processed ) {
		$state['status']       = 'complete';
		$state['current_type'] = '';
		$state['completed_at'] = time();
		rfs_update_delete_synced_data_state( $state );
		rfs_clear_delete_synced_data_fallback( $run_id );
		return;
	}

	rfs_update_delete_synced_data_state( $state );
	if ( $schedule_next ) {
		rfs_schedule_delete_synced_data_fallback( $run_id );
	}
}
add_action( 'rfs_delete_synced_data_step', 'rfs_process_delete_synced_data_step', 10, 1 );

/**
 * Build the delete status payload.
 *
 * @param bool $include_finished Whether to include completed/cancelled run messages.
 * @return array
 */
function rfs_get_delete_synced_data_status_payload( $include_finished = false ) {
	$state   = rfs_get_delete_synced_data_state();
	$status  = isset( $state['status'] ) ? (string) $state['status'] : 'idle';
	$total   = isset( $state['total'] ) ? (int) $state['total'] : 0;
	$counts  = isset( $state['counts'] ) && is_array( $state['counts'] ) ? array_map( 'intval', $state['counts'] ) : array();
	$deleted = isset( $state['deleted'] ) && is_array( $state['deleted'] ) ? array_map( 'intval', $state['deleted'] ) : array();
	$done    = array_sum( $deleted );

	if ( 'running' === $status ) {
		$message = sprintf( 'Deleting synced data: %1$d of %2$d deleted.', min( $done, $total ), $total );
		$breakdown = rfs_get_delete_synced_data_breakdown_label( $counts, $deleted );
	} elseif ( 'complete' === $status && $include_finished ) {
		$counts  = rfs_count_synced_data_for_delete();
		$total   = array_sum( $counts );
		$message = sprintf( 'Synced data deletion complete. %d item(s) deleted.', $done );
		$breakdown = rfs_get_delete_synced_data_breakdown_label( $counts );
	} else {
		$status  = 'idle';
		$counts  = rfs_count_synced_data_for_delete();
		$total   = array_sum( $counts );
		$message = 'Synced data available to delete:';
		$breakdown = rfs_get_delete_synced_data_breakdown_label( $counts );
	}

	return array(
		'status'       => $status,
		'message'      => $message,
		'breakdown'    => $breakdown,
		'total'        => $total,
		'counts'       => $counts,
		'deleted'      => $deleted,
		'current_type' => isset( $state['current_type'] ) ? (string) $state['current_type'] : '',
		'running'      => 'running' === $status,
		'errors'       => isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array(),
	);
}

/**
 * Render delete controls in the sync settings section.
 *
 * @return void
 */
function rfs_render_delete_synced_data_settings_controls() {
	$status = rfs_get_delete_synced_data_status_payload();
	?>
	<div class="rfs-delete-synced-data-modal" aria-hidden="true">
		<div class="rfs-delete-synced-data-modal__panel" role="dialog" aria-modal="true" aria-labelledby="rfs-delete-synced-data-title">
			<h2 id="rfs-delete-synced-data-title">Delete all synced data?</h2>
			<p>This permanently deletes ALL synced properties, floor plans, and units. This action CANNOT be undone, and it CAN remove any custom additions you've made to synced properties, floor plans, and units. Be sure to back up your database before proceeding.</p>
			<p class="description rfs-delete-synced-data-status">
				<span class="rfs-delete-synced-data-message"><?php echo esc_html( $status['message'] ); ?></span>
				<span class="rfs-delete-synced-data-breakdown"><?php echo esc_html( $status['breakdown'] ); ?></span>
			</p>
			<label for="rfs-delete-synced-data-confirm">Type <strong>delete</strong> to confirm.</label>
			<input type="text" id="rfs-delete-synced-data-confirm" autocomplete="off">
			<div class="rfs-delete-synced-data-modal__actions">
				<button type="button" class="button rfs-delete-synced-data-cancel">Cancel</button>
				<button type="button" class="button rfs-delete-synced-data-confirm" disabled>Delete synced data</button>
			</div>
		</div>
	</div>
	<p class="rfs-delete-synced-data-link-wrap">
		<a href="#" class="rfs-delete-synced-data-open">Delete synced data</a>
	</p>
	<?php
}

/**
 * Handle delete start AJAX.
 *
 * @return void
 */
function rfs_start_delete_synced_data_ajax_handler() {
	check_ajax_referer( 'rfs_delete_synced_data', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to delete synced data.' ), 403 );
	}

	$confirmation = isset( $_POST['confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';
	$state        = rfs_start_delete_synced_data( $confirmation );

	if ( is_wp_error( $state ) ) {
		wp_send_json_error( array( 'message' => $state->get_error_message() ), 400 );
	}

	wp_send_json_success( rfs_get_delete_synced_data_status_payload( true ) );
}
add_action( 'wp_ajax_rfs_start_delete_synced_data', 'rfs_start_delete_synced_data_ajax_handler' );

/**
 * Handle delete status AJAX.
 *
 * @return void
 */
function rfs_get_delete_synced_data_status_ajax_handler() {
	check_ajax_referer( 'rfs_delete_synced_data', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'You do not have permission to view delete status.' ), 403 );
	}

	$include_finished = isset( $_POST['include_finished'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['include_finished'] ) );
	$state            = rfs_get_delete_synced_data_state();

	if ( ! empty( $state['run_id'] ) && ! empty( $state['status'] ) && 'running' === $state['status'] ) {
		rfs_process_delete_synced_data_step( $state['run_id'], 0.75, 2, false );
	}

	wp_send_json_success( rfs_get_delete_synced_data_status_payload( $include_finished ) );
}
add_action( 'wp_ajax_rfs_get_delete_synced_data_status', 'rfs_get_delete_synced_data_status_ajax_handler' );

/**
 * Print delete UI styles.
 *
 * @return void
 */
function rfs_delete_synced_data_admin_styles() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<style>
		.rfs-delete-synced-data-confirm {
			background: #b32d2e !important;
			border-color: #b32d2e !important;
			color: #fff !important;
		}

		.rfs-delete-synced-data-confirm:not(:disabled):hover,
		.rfs-delete-synced-data-confirm:not(:disabled):focus {
			background: #8a2424 !important;
			border-color: #8a2424 !important;
			color: #fff !important;
		}

		.rfs-delete-synced-data-link-wrap {
			margin-top: 10px;
		}

		.rfs-delete-synced-data-open {
			color: #b32d2e !important;
			text-decoration: underline;
			text-underline-offset: 2px;
		}

		.rfs-delete-synced-data-open:hover,
		.rfs-delete-synced-data-open:focus {
			color: #8a2424 !important;
		}

		.rfs-delete-synced-data-confirm:disabled {
			cursor: not-allowed;
			opacity: 0.45;
		}

		.rfs-delete-synced-data-status span {
			display: block;
			line-height: 1.35;
		}

		.rfs-delete-synced-data-breakdown {
			margin-top: 3px;
			opacity: 0.82;
		}

		.rfs-delete-synced-data-modal .rfs-delete-synced-data-status {
			background: #f6f7f7;
			border-left: 3px solid #b32d2e;
			margin: 16px 0;
			padding: 10px 12px;
		}

		.rfs-delete-synced-data-modal {
			align-items: center;
			background: rgba(0, 0, 0, 0.45);
			display: none;
			inset: 0;
			justify-content: center;
			position: fixed;
			z-index: 100000;
		}

		.rfs-delete-synced-data-modal.is-open {
			display: flex;
		}

		.rfs-delete-synced-data-modal__panel {
			background: #fff;
			border-radius: 4px;
			box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
			max-width: 460px;
			padding: 24px;
			width: calc(100% - 32px);
		}

		.rfs-delete-synced-data-modal__panel h2 {
			margin-top: 0;
		}

		.rfs-delete-synced-data-modal__panel label {
			display: block;
			font-weight: 600;
			margin-top: 16px;
		}

		.rfs-delete-synced-data-modal__panel input {
			margin-top: 8px;
			width: 100%;
		}

		.rfs-delete-synced-data-modal__actions {
			display: flex;
			gap: 10px;
			justify-content: flex-end;
			margin-top: 20px;
		}
	</style>
	<?php
}
add_action( 'admin_head', 'rfs_delete_synced_data_admin_styles' );

/**
 * Print delete UI script.
 *
 * @return void
 */
function rfs_delete_synced_data_admin_script() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$config = array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'rfs_delete_synced_data' ),
	);
	?>
	<script>
	(function() {
		var config = <?php echo wp_json_encode( $config ); ?>;
		var modal = document.querySelector('.rfs-delete-synced-data-modal');
		var input = document.getElementById('rfs-delete-synced-data-confirm');
		var confirmButton = document.querySelector('.rfs-delete-synced-data-confirm');
		var includeFinishedStatus = false;
		var pollTimer = null;
		var pollInFlight = false;

		if (!document.querySelector('.rfs-delete-synced-data-status') && !modal) {
			return;
		}

		function post(action, confirmation) {
			var body = new window.FormData();
			body.append('action', action);
			body.append('nonce', config.nonce);
			if (confirmation) {
				body.append('confirmation', confirmation);
			}
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

		function setStatus(payload) {
			var status = document.querySelector('.rfs-delete-synced-data-status');
			var message = status ? status.querySelector('.rfs-delete-synced-data-message') : null;
			var breakdown = status ? status.querySelector('.rfs-delete-synced-data-breakdown') : null;
			if (!status || !payload) {
				return;
			}

			if (message) {
				message.textContent = payload.message || '';
			} else {
				status.textContent = payload.message || '';
			}

			if (breakdown) {
				breakdown.textContent = payload.breakdown || '';
			}

			if (payload.status === 'running') {
				startPolling();
			} else {
				stopPolling();
				if (payload.status === 'complete') {
					window.setTimeout(function() {
						closeModal();
						includeFinishedStatus = false;
						pollStatus();
					}, 350);
				}
			}
		}

		function openModal() {
			if (!modal || !input || !confirmButton) {
				return;
			}
			modal.classList.add('is-open');
			modal.setAttribute('aria-hidden', 'false');
			input.value = '';
			confirmButton.disabled = true;
			pollStatus();
			input.focus();
		}

		function closeModal() {
			if (!modal) {
				return;
			}
			modal.classList.remove('is-open');
			modal.setAttribute('aria-hidden', 'true');
		}

		function pollStatus() {
			if (pollInFlight) {
				return;
			}

			pollInFlight = true;
			post('rfs_get_delete_synced_data_status')
				.then(function(response) {
					if (response && response.success) {
						setStatus(response.data);
					}
				})
				.catch(function() {})
				.finally(function() {
					pollInFlight = false;
				});
		}

		function startPolling() {
			if (pollTimer) {
				return;
			}
			pollTimer = window.setInterval(pollStatus, 500);
		}

		function stopPolling() {
			if (!pollTimer) {
				return;
			}
			window.clearInterval(pollTimer);
			pollTimer = null;
		}

		document.addEventListener('click', function(event) {
			if (event.target.closest('.rfs-delete-synced-data-open')) {
				event.preventDefault();
				openModal();
				return;
			}

			if (event.target.closest('.rfs-delete-synced-data-cancel')) {
				event.preventDefault();
				closeModal();
				return;
			}

			if (modal && event.target === modal) {
				closeModal();
			}
		});

		if (input && confirmButton) {
			input.addEventListener('input', function() {
				confirmButton.disabled = input.value.trim().toLowerCase() !== 'delete';
			});
		}

		if (confirmButton && input) {
			confirmButton.addEventListener('click', function() {
				if (confirmButton.disabled) {
					return;
				}

				confirmButton.disabled = true;
				post('rfs_start_delete_synced_data', input.value)
					.then(function(response) {
						var data = response && response.data ? response.data : {};
						if (response && response.success) {
							includeFinishedStatus = true;
							setStatus(data);
						} else {
							setStatus({ message: data.message || 'Delete request failed.' });
							confirmButton.disabled = false;
						}
					})
					.catch(function() {
						setStatus({ message: 'Delete request failed.' });
						confirmButton.disabled = false;
					});
			});
		}

		pollStatus();
	}());
	</script>
	<?php
}
add_action( 'admin_footer', 'rfs_delete_synced_data_admin_script' );
