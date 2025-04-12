jQuery(document).ready(function ($) {
	$('.sync-property').on('click', function (e) {
		e.preventDefault();

		var propertyId = $(this).data('property-id');
		var integration = $(this).data('integration');

		$.ajax({
			url: rfs_ajax_object.ajax_url,
			type: 'POST',
			data: {
				action: 'rfs_sync_single_property',
				property_id: propertyId,
				integration: integration,
				_ajax_nonce: rfs_ajax_object.nonce,
			},
			success: function (response) {
				alert('Sync completed: ' + response);
			},
			error: function () {
				alert('Sync failed.');
			},
		});
	});
});
