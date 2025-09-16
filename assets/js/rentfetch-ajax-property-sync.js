jQuery(document).ready(function ($) {
	$('.sync-property').on('click', function (e) {
		e.preventDefault();

		var $link = $(this);
		var propertyId = $link.data('property-id');
		var integration = $link.data('integration');

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
		$link.text('Sync in progress...');

		// Update status message
		$('.rfs-sync-status-message').text('Sync in progress...');
		$('.rfs-sync-status-meta').text('Please wait while we sync your data.');

		// Set progress bar to indeterminate state
		$('.rfs-sync-progress-fill').css('width', '50%');

		// Start the server-side sync request
		var ajaxUrl =
			typeof rfs_ajax_object !== 'undefined'
				? rfs_ajax_object.ajax_url
				: '/wp-admin/admin-ajax.php';

		$.post(ajaxUrl, {
			action: 'rfs_sync_single_property',
			property_id: propertyId,
			integration: integration,
			_ajax_nonce:
				typeof rfs_ajax_object !== 'undefined'
					? rfs_ajax_object.nonce
					: '',
		})
			.done(function (response) {
				if (response.success) {
					// Success
					$('.rfs-sync-status-message').text(
						'Sync completed successfully!'
					);
					$('.rfs-sync-status-meta').text(
						'Completed at: ' + new Date().toLocaleTimeString()
					);
					$('.rfs-sync-progress-fill').css('width', '100%');

					// Revert link text
					$link.text(originalText);
					$link.data('syncing', false);
					$link.attr('aria-busy', 'false');

					// Optional: Show refresh prompt
					setTimeout(function () {
						if (
							confirm(
								'Sync completed! Would you like to refresh the page to see the changes?'
							)
						) {
							window.location.reload();
						}
					}, 1000);
				} else {
					// Handle server-side error
					$('.rfs-sync-status-message').text('Sync failed');
					$('.rfs-sync-status-meta').text(
						'Error: ' + (response.data || 'Unknown error occurred')
					);
					$('.rfs-sync-progress-fill').css('width', '0%');

					// Revert link text
					$link.text(originalText);
					$link.data('syncing', false);
					$link.attr('aria-busy', 'false');
				}
			})
			.fail(function (xhr, status, error) {
				// Handle AJAX error
				$('.rfs-sync-status-message').text('Sync failed');
				$('.rfs-sync-status-meta').text(
					'Network error: ' + error + ' (Status: ' + xhr.status + ')'
				);
				$('.rfs-sync-progress-fill').css('width', '0%');

				// Revert link text
				$link.text(originalText);
				$link.data('syncing', false);
				$link.attr('aria-busy', 'false');
			});
	});
});
