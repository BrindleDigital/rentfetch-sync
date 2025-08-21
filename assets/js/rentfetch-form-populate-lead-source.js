// Populate the rentfetch hidden lead_source field at runtime to avoid cached values.
// This file is intentionally not minified so it's easy to inspect during debugging.

jQuery(document).ready(function ($) {
	'use strict';

	function getQueryParameter(name) {
		try {
			return new URLSearchParams(window.location.search).get(name);
		} catch (e) {
			return null;
		}
	}

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
});
