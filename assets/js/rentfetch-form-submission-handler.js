jQuery(document).ready(function ($) {
	function appendNotice($container, type, message, insertBefore) {
		var $notice = $('<div>', {
			class: 'rentfetch-form-message-area ' + type,
		}).append($('<p>').text(String(message || '')));

		if (insertBefore) {
			$container.before($notice);
		} else {
			$container.prepend($notice);
		}
	}

	function appendErrorList($container, errors) {
		var $notice = $('<div>', { class: 'rentfetch-form-message-area error' });
		var $list = $('<ul>');
		var safeErrors =
			Array.isArray(errors) && errors.length > 0
				? errors
				: ['An unknown error occurred.'];

		safeErrors.forEach(function (error) {
			$list.append($('<li>').text(String(error || '')));
		});

		$notice.append($list);
		$container.prepend($notice);
	}

	$('#rentfetch-form').on('submit', function (e) {
		e.preventDefault(); // Prevent standard form submission

		var form = $(this);
		var formData = form.serialize();
		var submitButton = form.find('button[type="submit"]');
		var originalButtonText = submitButton.text();

		// Disable button and show loading state
		submitButton.prop('disabled', true).text('Submitting...');

		// Clear previous messages
		form.find('.rentfetch-form-message-area').remove();

		$.ajax({
			// Use the localized AJAX URL
			url: rentfetchFormAjax.ajaxurl,
			type: 'POST',
			dataType: 'json',
			// Add the action and nonce from localized script
			data:
				formData +
				'&action=rentfetch_ajax_submit_form&rentfetch_form_nonce=' +
				rentfetchFormAjax.nonce,
			success: function (response) {
				if (response.success) {
					// Redirect if we need to.
					if (
						!new URLSearchParams(window.location.search).has(
							'debug'
						)
					) {
						var redirectUrl = form
							.find('#rentfetch-form-redirection-url')
							.val();
						if (redirectUrl) {
							window.location.href = redirectUrl;
						}
					}

					// Display success message
					appendNotice(
						form,
						'success',
						response &&
							response.data &&
							typeof response.data.message === 'string'
							? response.data.message
							: 'Your message was sent successfully.',
						true
					);
					// Remove the form element
					form.remove();

					// If ?debug is in the URL, log the response
					if (
						new URLSearchParams(window.location.search).has('debug')
					) {
						console.log(response);
					}
				} else {
					// If ?debug is in the URL, log the response
					if (
						new URLSearchParams(window.location.search).has('debug')
					) {
						appendNotice(
							form,
							'notice',
							'See console log for response details.',
							false
						);
						console.log(response);
					} else {
						appendErrorList(
							form,
							response &&
								response.data &&
								Array.isArray(response.data.errors)
								? response.data.errors
								: null
						);
					}
				}
			},
			error: function (xhr, status, error) {
				// Handle AJAX request errors
				appendNotice(
					form,
					'error',
					'An unexpected error occurred. Please try again.',
					false
				);
				console.error(xhr.responseText);
			},
			complete: function () {
				// Re-enable button
				submitButton.prop('disabled', false).text(originalButtonText);
			},
		});
	});
});
