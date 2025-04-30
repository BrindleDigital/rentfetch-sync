jQuery(document).ready(function ($) {
	$('#rentfetch-form').on('submit', function (e) {
		e.preventDefault(); // Prevent standard form submission

		var form = $(this);
		var formData = form.serialize();
		var submitButton = form.find('button[type="submit"]');
		var originalButtonText = submitButton.text();

		console.log('Form data:', formData); // Debugging line to check form data

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
					// Display success message
					// Assuming success response will have response.data.message
					form.prepend(
						'<div class="rentfetch-form-message-area success"><p>' +
							response.data.message +
							'</p></div>'
					);
					// Optionally clear the form
					form[0].reset();
				} else {
					// Display error messages
					var errorHtml =
						'<div class="rentfetch-form-message-area error"><ul>';
					// Ensure response.data.errors exists and is an array
					if (response.data && Array.isArray(response.data.errors)) {
						$.each(response.data.errors, function (index, error) {
							errorHtml += '<li>' + error + '</li>';
						});
					} else {
						// errorHtml += '<li>An unknown error occurred.</li>';
						errorHtml += response;
					}
					errorHtml += '</ul></div>';
					form.prepend(errorHtml);
				}
			},
			error: function (xhr, status, error) {
				// Handle AJAX request errors
				form.prepend(
					'<div class="rentfetch-form-message-area error"><p>An unexpected error occurred. Please try again.</p></div>'
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
