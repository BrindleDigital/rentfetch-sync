jQuery(document).ready(function ($) {
	// Cache the single progress fill element on the page (there's only one).
	var $globalProgressFill = $('.rfs-sync-progress-fill').first();

	// Helper to force the progress bar to 100% with a short animation.
	function forceProgressTo100() {
		if (!($globalProgressFill && $globalProgressFill.length)) return;
		var durationMs = 250; // animation duration
		// apply a short transition, then set width to 100%
		$globalProgressFill.css({
			transition: 'width ' + durationMs + 'ms ease',
			'will-change': 'width',
		});
		// trigger the width change
		$globalProgressFill.css('width', '100%');
		// update aria value after the animation completes
		setTimeout(function () {
			$globalProgressFill.attr('aria-valuenow', 100);
			// clean up transition to avoid affecting future updates
			$globalProgressFill.css('transition', '');
			$globalProgressFill.css('will-change', '');
		}, durationMs + 20);
	}

	// Inspect status meta elements and force-complete if any contains "completed at".
	function checkCompletedAndForce() {
		$('.rfs-sync-status-meta').each(function () {
			var txt = $(this).text() || '';
			if (txt.toLowerCase().indexOf('completed at') !== -1) {
				forceProgressTo100();
				return false; // break out of .each
			}
		});
	}

	$('.sync-property').on('click', async function (e) {
		e.preventDefault();

		var $link = $(this);
		var propertyId = $link.data('property-id');
		var integration = $link.data('integration');

		// Resolve AJAX URL by waiting briefly for the localized object to be available.
		// Keep only a small, explicit fallback to the global `ajaxurl` if present.
		function resolveAjaxUrl(timeoutMs = 3000, intervalMs = 50) {
			return new Promise(function (resolve) {
				var waited = 0;
				var handle = setInterval(function () {
					if (
						typeof rfs_ajax_object !== 'undefined' &&
						rfs_ajax_object.ajax_url
					) {
						clearInterval(handle);
						resolve(rfs_ajax_object.ajax_url);
						return;
					}
					if (typeof ajaxurl !== 'undefined' && ajaxurl) {
						clearInterval(handle);
						resolve(ajaxurl);
						return;
					}
					waited += intervalMs;
					if (waited >= timeoutMs) {
						clearInterval(handle);
						resolve(null);
					}
				}, intervalMs);
			});
		}

		// Resolve AJAX URL; if it's not available we won't poll but we'll still start the sync request.
		var ajaxUrl = await resolveAjaxUrl();
		if (!ajaxUrl) {
			// show a minimal status and continue without polling
			$('.rfs-sync-status-message').text(
				'Sync started (no ajax URL available for polling)'
			);
			$('.rfs-sync-status-meta').text(
				'Progress will be available after the page refresh.'
			);
			ajaxUrl = null;
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
		// allow per-link override via data-poll-interval (ms), fall back to 25ms for snappier UI
		var pollInterval = parseInt($link.data('poll-interval'), 10) || 25; // ms
		var poller = null;
		var maxSteps = 10; // map server steps to up to 10 visible steps
		var firstProgressAt = null; // timestamp of first non-queued progress

		// If any polling request fails, we stop polling and wait for the server-side sync
		var pollingFailed = false;

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

			// Use the cached global progress fill element.
			var $progressFill = $globalProgressFill;

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

			// If any status meta already shows a completed timestamp, ensure progress shows 100%.
			checkCompletedAndForce();

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
					// Reset poll failure counter on any successful response
					pollRetries = 0;

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
						// Ensure the progress bar shows 100% on completion.
						forceProgressTo100();

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
				.fail(function () {
					// Increment retry counter but do not surface error details to the user.
					pollRetries++;

					// Keep the UI neutral and simple: show waiting state while we retry or await completion.
					$('.rfs-sync-status-message').text('Waiting for response');
					$('.rfs-sync-status-meta').text('');

					if (pollRetries <= maxPollRetries) {
						// still retrying silently; don't stop polling here
						return;
					}

					// Exhausted retries: stop polling and continue showing the neutral waiting message.
					clearInterval(poller);
					pollingFailed = true;
				});
		}

		// Start polling immediately so we observe progress while server works (only if we have an ajaxUrl)
		if (ajaxUrl) {
			poll();
			poller = setInterval(poll, pollInterval);
		}

		// Add a deadline so polling doesn't run forever (30s default)
		var pollDeadline = Date.now() + 30000; // ms

		// Update poll to handle deadline expiration
		var _originalPoll = poll;
		function pollWithDeadline() {
			if (Date.now() > pollDeadline) {
				clearInterval(poller);
				// If we hit the deadline without completion, stop polling and wait for server completion.
				pollingFailed = true;
				$('.rfs-sync-status-message').text(
					'Sync in progress (waiting for server to finish)'
				);
				$('.rfs-sync-status-meta').text('Awaiting sync completion.');
				return;
			}
			_originalPoll();
		}

		clearInterval(poller);
		poller = setInterval(pollWithDeadline, pollInterval);

		// Start the server-side sync request (async from the browser, but server run may be synchronous)

		var startRetries = 0;
		var maxStartRetries = 2;
		var pollRetries = 0;
		var maxPollRetries = 2;
		var retryDelay = 500; // ms

		function startSyncRequest() {
			var startUrl = ajaxUrl || '/wp-admin/admin-ajax.php';
			$.post(startUrl, {
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
				.fail(function (xhr) {
					// On start failure, attempt a small number of retries before giving up.
					startRetries++;
					if (startRetries <= maxStartRetries) {
						setTimeout(function () {
							// re-resolve ajaxUrl in case it became available
							resolveAjaxUrl().then(function (url) {
								if (url) {
									ajaxUrl = url;
								}
								startSyncRequest();
							});
						}, retryDelay);
						return;
					}
					// Exhausted retries; stop polling and wait for server-side completion
					var status =
						xhr && xhr.status ? xhr.status : 'network error';
					var url =
						xhr && xhr.responseURL ? xhr.responseURL : ajaxUrl;
					clearInterval(poller);
					pollingFailed = true;
					$('.rfs-sync-status-message').text(
						'Sync in progress (failed to start request)'
					);
					$('.rfs-sync-status-meta').text(
						'Failed to start sync request (' +
							status +
							') — Request URL: ' +
							url +
							' — awaiting server completion.'
					);
				});
		}

		startSyncRequest();
	});
});
