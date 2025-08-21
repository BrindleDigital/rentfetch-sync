jQuery(document).ready(function ($) {
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
					if (typeof rfs_ajax_object !== 'undefined' && rfs_ajax_object.ajax_url) {
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

		// Resolve AJAX URL and abort early if none found
		var ajaxUrl = await resolveAjaxUrl();
		if (!ajaxUrl) {
			// show a clear status in the UI and don't start polling
			$('.rfs-sync-status-message').text(
				'Sync failed (could not determine ajax URL)'
			);
			$('.rfs-sync-status-meta').text(
				'Please reload the page or contact your administrator.'
			);
			return;
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
					// Allow a small number of transient failures before giving up (helps when admin-ajax becomes available shortly after).
					pollRetries++;
					if (pollRetries <= maxPollRetries) {
						// re-resolve ajaxUrl and continue polling after a short delay
						setTimeout(function () {
							resolveAjaxUrl().then(function (url) {
								if (url) ajaxUrl = url;
								// resume polling
								poll();
								poller = setInterval(pollWithDeadline, pollInterval);
							});
						}, retryDelay);
						return;
					}

					// Exhausted retries; stop polling and show error
					clearInterval(poller);
					$link.text('Sync failed');
					$link.attr('aria-busy', 'false');
					$link.data('syncing', false);

					var status = (xhr && xhr.status) ? xhr.status : 'network error';
					var url = (xhr && xhr.responseURL) ? xhr.responseURL : ajaxUrl;
					$('.rfs-sync-status-message').text('Sync failed (' + status + ')');
					$('.rfs-sync-status-meta').text('Request URL: ' + url + ' — check ajax_url and local server configuration.');
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
				// If we hit the deadline without completion, enter degraded background monitoring instead of showing failure.
				enterDegradedMode('timeout');
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

		var degradedMode = false;
		var degradedPoller = null;
		var degradedPollInterval = 5000; // 5s between checks in degraded mode
		var degradedDeadline = Date.now() + 10 * 60 * 1000; // 10 minutes

		function enterDegradedMode(reason) {
			if (degradedMode) return;
			degradedMode = true;
			clearInterval(poller);
			// hide step UI (progress bar) and show minimal status
			$('.rfs-sync-progress-fill').css('width', '0%').hide();
			$('.rfs-sync-status-message').text('Sync in progress (server-side, waiting for completion)');
			$('.rfs-sync-status-meta').text(reason + ' — will monitor in background and mark complete when done.');

			// start low-frequency background polling to detect completion
			function degradedCheck() {
				if (Date.now() > degradedDeadline) {
					clearInterval(degradedPoller);
					$('.rfs-sync-status-message').text('Sync still in progress (monitor timed out)');
					$('.rfs-sync-status-meta').text('Please refresh later to check status.');
					$link.attr('aria-busy', 'false');
					$link.data('syncing', false);
					return;
				}

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
						if (res && res.success && res.data && parseInt(res.data.step, 10) >= parseInt(res.data.total, 10)) {
							clearInterval(degradedPoller);
							$('.rfs-sync-status-message').text('Sync complete. Please refresh the page to see the changes.');
							$('.rfs-sync-status-meta').text('completed at: ' + new Date().toLocaleTimeString());
							$link.attr('aria-busy', 'false');
							$link.text(originalText);
							$link.data('syncing', false);
						}
					});
			}

			// start first check immediately, then interval
			degradedCheck();
			degradedPoller = setInterval(degradedCheck, degradedPollInterval);
		}

		function startSyncRequest() {
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
					// Exhausted retries; enter degraded mode so we monitor server-side completion instead of failing loudly.
					var status = (xhr && xhr.status) ? xhr.status : 'network error';
					var url = (xhr && xhr.responseURL) ? xhr.responseURL : ajaxUrl;
					enterDegradedMode('Failed to start sync request (' + status + ') — Request URL: ' + url);
				});
		}

		startSyncRequest();

		// Degraded mode: attempt background monitoring (1s interval, 30s max) and mark complete if the server reports completion.
		function enterDegradedMode(reason) {
			// stop any existing poller
			clearInterval(poller);

			// hide detailed step UI (we're in degraded mode)
			$('.rfs-sync-progress').hide();

			$('.rfs-sync-status-message').text('Monitoring sync in background...');
			$('.rfs-sync-status-meta').text('Degraded mode: ' + reason + ' — polling every 1s for up to 30s');

			var degradedInterval = 1000; // 1s
			var degradedDeadline = Date.now() + 30000; // 30s
			var degradedPoller = null;

			function degradedPoll() {
				if (Date.now() > degradedDeadline) {
					clearInterval(degradedPoller);
					$('.rfs-sync-status-message').text('Sync status unknown — please refresh to confirm.');
					$('.rfs-sync-status-meta').text('Monitoring ended after 30s without confirmation.');
					$link.attr('aria-busy', 'false');
					$link.text($link.data('original-text') || $link.text());
					$link.data('syncing', false);
					return;
				}

				// ensure we have a valid ajaxUrl each time
				resolveAjaxUrl().then(function (url) {
					if (url) ajaxUrl = url;
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
							if (res && res.success && res.data && parseInt(res.data.step, 10) >= parseInt(res.data.total, 10)) {
								clearInterval(degradedPoller);
								$('.rfs-sync-status-message').text('Sync complete. Please refresh the page to see the changes.');
								$('.rfs-sync-status-meta').text('completed at: ' + new Date().toLocaleTimeString());
								$link.attr('aria-busy', 'false');
								$link.text($link.data('original-text') || $link.text());
								$link.data('syncing', false);
							}
						})
						.fail(function () {
							// ignore transient failures in degraded mode; we'll try again until deadline
						});
				});
			}

			// start degraded polling
			degradedPoller = setInterval(degradedPoll, degradedInterval);
			// run one immediately
			degradedPoll();
		}
	});
});
