jQuery(document).ready(function ($) {
	var $apiKeyInput = $('#rentfetch_options_rfs_api_key');
	var $validateButton = $('#rfs_validate_api_key');
	var $statusDiv = $('#rfs_api_key_status');

	/**
	 * Display a status message
	 *
	 * @param {string} message The message to display
	 * @param {string} type The type of message: 'success', 'error', 'warning', or 'info'
	 */
	function displayStatus(message, type) {
		var cssClass = 'notice notice-' + type;
		$statusDiv
			.html('<div class="' + cssClass + '"><p>' + message + '</p></div>')
			.show();
	}

	/**
	 * Validate the API key
	 *
	 * @param {boolean} showLoadingState Whether to show a loading state on the button
	 */
	function validateApiKey(showLoadingState) {
		var apiKey = $apiKeyInput.val().trim();

		if (!apiKey) {
			displayStatus('Please enter an API key.', 'warning');
			return;
		}

		if (showLoadingState) {
			$validateButton.prop('disabled', true).text('Validating...');
		}

		$.post(rfs_ajax_object.ajax_url, {
			action: 'rfs_validate_api_key',
			api_key: apiKey,
			_ajax_nonce: rfs_ajax_object.nonce, // Must match parameter name in PHP: check_ajax_referer('rfs_ajax_nonce', '_ajax_nonce')
		})
			.done(function (response) {
				if (response.success) {
					var data = response.data;
					var status = data.status;
					var message = data.message;

					switch (status) {
						case 'valid':
							displayStatus(message, 'success');
							break;
						case 'not_found':
							displayStatus(message, 'error');
							break;
						case 'in_use':
							displayStatus(message, 'warning');
							break;
						default:
							displayStatus(message, 'info');
							break;
					}
				} else {
					displayStatus(
						'Error validating API key: ' +
							(response.data || 'Unknown error'),
						'error'
					);
				}
			})
			.fail(function (xhr, status, error) {
				displayStatus(
					'Network error while validating API key: ' + error,
					'error'
				);
			})
			.always(function () {
				if (showLoadingState) {
					$validateButton.prop('disabled', false).text('Validate Key');
				}
			});
	}

	// Validate on button click
	$validateButton.on('click', function (e) {
		e.preventDefault();
		validateApiKey(true);
	});

	// Validate on page load if API key exists
	if ($apiKeyInput.length && $apiKeyInput.val().trim()) {
		validateApiKey(false);
	}
});
