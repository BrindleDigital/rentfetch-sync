// Populate the rentfetch hidden lead_source field at runtime to avoid cached values.
// This file is intentionally not minified so it's easy to inspect during debugging.

jQuery(document).ready(function ($) {
	'use strict';

	// Track if hooks have been set up to avoid duplicates
	var hooksInitialized = false;

	/**
	 * Populate Gravity Forms fields with lead source cookie value
	 */
	function initializeGravityFormsPopulation() {
		// Get the lead source value from cookie
		var leadSource = getCookieValue('wordpress_rentfetch_lead_source');

		if (!leadSource) {
			return;
		}

		// Function to set up Gravity Forms hooks (only once)
		function setupGravityFormsHooks() {
			if (typeof gform === 'undefined') {
				return false;
			}

			if (hooksInitialized) {
				return true;
			}

			// Hook into form rendering - set default value in form object
			gform.addFilter('gform_pre_render', function (form, isAjax) {
				// Look for the automatically added lead source field
				$.each(form.fields, function (index, field) {
					if (
						field.type === 'text' &&
						field.cssClass &&
						field.cssClass.includes('lead-source-field')
					) {
						field.defaultValue = leadSource;
					}
				});

				return form;
			});

			hooksInitialized = true;
			return true;
		}

		// Function to populate existing form fields
		function populateExistingForms() {
			$('.gform_wrapper').each(function () {
				var $form = $(this);
				var $targetField = $form.find(
					'.gfield.lead-source-field input[type="text"]'
				);

				if ($targetField.length > 0) {
					$targetField.val(leadSource);
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
				var $form = $('#gform_wrapper_' + formId);
				var $targetField = $form.find(
					'.gfield.lead-source-field input[type="text"]'
				);

				if ($targetField.length > 0) {
					$targetField.val(leadSource);
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
		return;
	}

	// Read from the new cookie name; keep legacy name in comments for reference.
	var cookieValue = getCookieValue('wordpress_rentfetch_lead_source');
	if (cookieValue) {
		$leadSourceInput.val(cookieValue).attr('value', cookieValue);
		return;
	}

	// Final fallback: use the shortcode-provided lead_source (passed via localized JS)
	if (
		typeof rentfetchFormAjax !== 'undefined' &&
		rentfetchFormAjax.shortcode_lead_source &&
		rentfetchFormAjax.shortcode_lead_source.trim() !== ''
	) {
		var shortcodeValue = rentfetchFormAjax.shortcode_lead_source;
		$leadSourceInput.val(shortcodeValue).attr('value', shortcodeValue);
	}

	function getQueryParameter(name) {
		try {
			return new URLSearchParams(window.location.search).get(name);
		} catch (e) {
			return null;
		}
	}
});
