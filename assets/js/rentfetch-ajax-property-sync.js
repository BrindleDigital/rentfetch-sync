jQuery(document).ready(function ($) {
	$('.sync-property').on('click', function (e) {
		e.preventDefault();

		var $link = $(this);
		var propertyId = $link.data('property-id');
		var integration = $link.data('integration');

		// Resolve AJAX URL with fallbacks to avoid 404s when localized object is missing or environment differs.
		var ajaxUrl = null;
		if (
			typeof rfs_ajax_object !== 'undefined' &&
			rfs_ajax_object.ajax_url
		) {
			ajaxUrl = rfs_ajax_object.ajax_url;
		} else if (typeof ajaxurl !== 'undefined') {
			ajaxUrl = ajaxurl; // WordPress often exposes this global in admin.
		} else if (window.location && window.location.origin) {
			ajaxUrl = window.location.origin + '/wp-admin/admin-ajax.php';
		} else {
			ajaxUrl = '/wp-admin/admin-ajax.php';
		}

		if (
			ajaxUrl.indexOf('admin-ajax.php') === -1 &&
			window.console &&
			window.console.warn
		) {
			console.warn('rfs: ajax URL looks unexpected:', ajaxUrl);
		}

		// Prevent double-click while a sync is in progress for this link
		if ($link.data('syncing')) {
			return;
		}

		// Preserve original text so we can revert after success
		var originalText = $link.data('original-text');
		if (typeof originalText === 'undefined') {
			originalText = $link.text();
			$link.data('original-text', originalText);
		}

		$link.data('syncing', true);
		$link.attr('aria-busy', 'true');
		$link.text('Sync in progress');

		// Begin polling immediately so we can observe progress set by the server while it runs.
		// allow per-link override via data-poll-interval (ms), fall back to 50ms
		var pollInterval = parseInt($link.data('poll-interval'), 10) || 50; // ms
		var poller = null;
		var maxSteps = 10; // map server steps to up to 10 visible steps
		var firstProgressAt = null; // timestamp of first non-queued progress

		function updateFromProgress(data) {
			if (!data || !data.success || !data.data) return;
			var d = data.data;
			var step = parseInt(d.step, 10) || 0;
			var total = parseInt(d.total, 10) || 1;
			var message = d.message || '';

			// map step/total to visible step (1..maxSteps)
			var visible = Math.ceil((step / total) * maxSteps);
			if (visible < 1) visible = 1;
			if (visible > maxSteps) visible = maxSteps;

			var percent = Math.round((visible / maxSteps) * 100);

			// Update the link text and title
			$link.attr('title', message);

			// Find the status area if present
			var $statusMsg = $link
				.closest('.metabox')
				.find('.rfs-sync-status-message');
			var $statusMeta = $link
				.closest('.metabox')
				.find('.rfs-sync-status-meta');

			// Find progress bar fill (search in common WP metabox containers, fall back to global)
			var $progressFill = $link
				.closest('.postbox, .metabox')
				.find('.rfs-sync-progress-fill');
			if ($progressFill.length === 0) {
				$progressFill = $('.rfs-sync-progress-fill');
			}

			// Fallback selectors if structure differs
			if ($statusMsg.length === 0) {
				$statusMsg = $('.rfs-sync-status-message');
			}
			if ($statusMeta.length === 0) {
				$statusMeta = $('.rfs-sync-status-meta');
			}

			// If we're stuck at initial 1/10 for more than 2s, show queued label instead of 'Syncing: 1/10'
			if (visible === 1 && percent <= 10) {
				if (!firstProgressAt) {
					firstProgressAt = Date.now();
				}
				var elapsed = Date.now() - firstProgressAt;
				if (elapsed > 2000) {
					// queued-looking state
					$link.text('Queued — waiting');
					$statusMsg.text('Queued — waiting');
					$statusMeta.text(
						'step: ' +
							step +
							'/' +
							total +
							' — ' +
							message +
							' — updated: ' +
							new Date().toLocaleTimeString()
					);
					return;
				}
			}

			// Minimal link text; full details live in the status area
			$link.text('Sync in progress');
			if ($statusMsg.length)
				$statusMsg.text(
					message ||
						'Syncing: ' +
							visible +
							'/' +
							maxSteps +
							' (' +
							percent +
							'%)'
				);
			if ($statusMeta.length)
				$statusMeta.text(
					'step: ' +
						step +
						'/' +
						total +
						' — updated: ' +
						new Date().toLocaleTimeString()
				);

			// Update progress bar
			if ($progressFill.length) {
				var percent = Math.round((visible / maxSteps) * 100);
				$progressFill.css('width', percent + '%');
				$progressFill.attr('aria-valuenow', percent);
			}
		}

		function poll() {
			$.post(ajaxUrl, {
				action: 'rfs_get_sync_progress',
				property_id: propertyId,
				integration: integration,
				_ajax_nonce:
					typeof rfs_ajax_object !== 'undefined'
						? rfs_ajax_object.nonce
						: '',
			})
				.done(function (res) {
					updateFromProgress(res);
					// if server reports completion (step >= total), stop polling and show complete state
					if (
						res &&
						res.success &&
						res.data &&
						parseInt(res.data.step, 10) >=
							parseInt(res.data.total, 10)
					) {
						clearInterval(poller);
						$('.rfs-sync-status-message').text(
							'Sync complete. Please refresh the page to see the changes.'
						);
						$('.rfs-sync-status-meta').text(
							'completed at: ' + new Date().toLocaleTimeString()
						);
						$link.attr('aria-busy', 'false');
						// Immediately revert link text and mark not syncing
						$link.text(originalText);
						$link.data('syncing', false);
					}
				})
				.fail(function (xhr) {
					// if 404/no-progress yet, keep polling; but if other error, stop and indicate failure
					if (xhr.status && xhr.status !== 404) {
						clearInterval(poller);
						$link.text('Sync failed');
						$link.attr('aria-busy', 'false');
						$link.data('syncing', false);
					}
				});
		}

		// Start polling immediately so we observe progress while server works
		poll();
		poller = setInterval(poll, pollInterval);

		// Add a deadline so polling doesn't run forever (30s default)
		var pollDeadline = Date.now() + 30000; // ms

		// Update poll to handle deadline expiration
		var _originalPoll = poll;
		function pollWithDeadline() {
			if (Date.now() > pollDeadline) {
				clearInterval(poller);
				$link.text('Sync failed');
				$link.attr('aria-busy', 'false');
				$link.data('syncing', false);
				$('.rfs-sync-status-message').text('Sync failed (timeout)');
				return;
			}
			_originalPoll();
		}

		clearInterval(poller);
		poller = setInterval(pollWithDeadline, pollInterval);

		// Start the server-side sync request (async from the browser, but server run may be synchronous)
		$.post(ajaxUrl, {
			action: 'rfs_sync_single_property',
			property_id: propertyId,
			integration: integration,
			_ajax_nonce:
				typeof rfs_ajax_object !== 'undefined'
					? rfs_ajax_object.nonce
					: '',
		})
			.always(function () {
				// always trigger an immediate poll to pick up any final state the server may have written
				poll();
			})
			.fail(function () {
				// don't stop polling immediately on start-failure; allow poller to pick up any server-side progress or final state until deadline
				$('.rfs-sync-status-message').text(
					'Failed to start sync request'
				);
			});
	});
});
