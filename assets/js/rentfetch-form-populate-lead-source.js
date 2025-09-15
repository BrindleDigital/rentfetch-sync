// Populate the rentfetch hidden lead_source field at runtime to avoid cached values.
// This file is intentionally not minified so it's easy to inspect during debugging.

console.log('[rentfetch] Script loaded and running');

jQuery(document).ready(function ($) {
	'use strict';

	console.log('[rentfetch] jQuery ready, starting lead source population');

	// Track if hooks have been set up to avoid duplicates
	var hooksInitialized = false;

	/**
	 * Populate Gravity Forms fields with lead source cookie value
	 */
	function initializeGravityFormsPopulation() {
		// Get the lead source value from cookie
		var leadSource = getCookieValue('wordpress_rentfetch_lead_source');

		console.log(
			'[rentfetch] Initializing GF population, leadSource:',
			leadSource
		);

		if (!leadSource) {
			console.log('[rentfetch] No lead source found in cookie');
			return;
		}

		// Function to set up Gravity Forms hooks (only once)
		function setupGravityFormsHooks() {
			if (typeof gform === 'undefined') {
				console.log('[rentfetch] Gravity Forms not available yet');
				return false;
			}

			if (hooksInitialized) {
				console.log(
					'[rentfetch] Hooks already initialized, skipping setup'
				);
				return true;
			}

			console.log('[rentfetch] Setting up Gravity Forms hooks');

			// Hook into form rendering - set default value in form object
			gform.addFilter('gform_pre_render', function (form, isAjax) {
				console.log('[rentfetch] Processing form:', form.id);

				// Look for the automatically added lead source field
				$.each(form.fields, function (index, field) {
					if (
						field.type === 'hidden' &&
						field.cssClass &&
						field.cssClass.includes('lead-source-field')
					) {
						console.log(
							'[rentfetch] Setting defaultValue for lead source field'
						);
						field.defaultValue = leadSource;
					}
				});

				return form;
			});

			hooksInitialized = true;
			console.log('[rentfetch] Gravity Forms hooks initialized');
			return true;
		}

		// Function to populate existing form fields
		function populateExistingForms() {
			console.log('[rentfetch] Populating existing forms');

			$('.gform_wrapper').each(function () {
				var $form = $(this);
				var $targetField = $form.find(
					'.gfield.lead-source-field input[type="text"]'
				);

				if ($targetField.length > 0) {
					$targetField.val(leadSource);
					console.log(
						'[rentfetch] Populated field:',
						$targetField.attr('name')
					);
				}
			});
		}

		// Try to set up hooks immediately
		if (setupGravityFormsHooks()) {
			populateExistingForms();
		} else {
			// If Gravity Forms isn't ready, wait and try again
			setTimeout(function () {
				if (setupGravityFormsHooks()) {
					populateExistingForms();
				}
			}, 1000);
		}

		// Listen for dynamically added forms (single listener)
		if (!hooksInitialized) {
			$(document).on('gform_post_render', function (event, formId) {
				console.log('[rentfetch] Dynamic form rendered:', formId);
				var $form = $('#gform_wrapper_' + formId);
				var $targetField = $form.find(
					'.gfield.lead-source-field input[type="hidden"]'
				);

				if ($targetField.length > 0) {
					$targetField.val(leadSource);
					console.log(
						'[rentfetch] Populated dynamic field:',
						$targetField.attr('name')
					);
				}
			});
		}
	}

	// Initialize once
	initializeGravityFormsPopulation();

	function getCookieValue(name) {
		var match = document.cookie.match(
			new RegExp('(^| )' + name + '=([^;]+)')
		);
		return match ? decodeURIComponent(match[2]) : null;
	}

	var $leadSourceInput = $('#rentfetch-form-lead_source');
	if ($leadSourceInput.length === 0) {
		return;
	}

	// Baseline assumption: this plugin will not set lead_source server-side, so
	// always attempt to populate the field client-side from query/cookie/shortcode.

	var queryParameter = getQueryParameter('lead_source');
	if (queryParameter) {
		$leadSourceInput.val(queryParameter).attr('value', queryParameter);
		console.log(
			'[rentfetch] lead_source set from query parameter:',
			queryParameter,
			'(source: query)'
		);
		console.log(
			'[rentfetch] final lead_source value:',
			$leadSourceInput.val()
		);
		return;
	}

	// Read from the new cookie name; keep legacy name in comments for reference.
	var cookieValue = getCookieValue('wordpress_rentfetch_lead_source');
	if (cookieValue) {
		$leadSourceInput.val(cookieValue).attr('value', cookieValue);
		console.log(
			'[rentfetch] lead_source set from cookie:',
			cookieValue,
			'(source: cookie)'
		);
		console.log(
			'[rentfetch] final lead_source value:',
			$leadSourceInput.val()
		);
		return;
	}

	// Final fallback: use the shortcode-provided lead_source (passed via localized JS)
	if (
		window.rentfetchFormAjax &&
		rentfetchFormAjax.shortcode_lead_source &&
		rentfetchFormAjax.shortcode_lead_source.trim() !== ''
	) {
		var shortcodeValue = rentfetchFormAjax.shortcode_lead_source;
		$leadSourceInput.val(shortcodeValue).attr('value', shortcodeValue);
		console.log(
			'[rentfetch] lead_source set from shortcode fallback (localized):',
			shortcodeValue,
			'(source: shortcode)'
		);
		console.log(
			'[rentfetch] final lead_source value:',
			$leadSourceInput.val()
		);
	}

	// If still empty, log that no lead source was found
	if (!$leadSourceInput.val() || $leadSourceInput.val().trim() === '') {
		console.log(
			'[rentfetch] no lead_source found (none set via query, cookie, or shortcode)'
		);
	}

	function getQueryParameter(name) {
		try {
			return new URLSearchParams(window.location.search).get(name);
		} catch (e) {
			return null;
		}
	}
});
