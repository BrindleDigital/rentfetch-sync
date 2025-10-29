<?php
/**
 * Gravity Forms integration for lead source tracking.
 *
 * This file adds automatic lead source field population to all Gravity Forms.
 * It works with the wordpress_rentfetch_lead_source cookie set by the lead source functionality.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Add visible lead source field to all Gravity Forms
 */
function rfs_add_lead_source_field( $form ) {
	
	// Check if Gravity Forms is active
	if ( ! class_exists( 'GFAPI' ) ) {
		return $form;
	}

	error_log( '[rentfetch] Processing form ID: ' . $form['id'] );

	// Check if the form already has a lead source field
	$has_lead_source_field = false;
	foreach ( $form['fields'] as $field ) {
		error_log( '[rentfetch] Checking existing field: ' . $field->label . ' (type: ' . $field->type . ', cssClass: ' . (isset($field->cssClass) ? $field->cssClass : 'none') . ', inputName: ' . (isset($field->inputName) ? $field->inputName : 'none') . ')' );

		if ( $field->type === 'text' && isset( $field->cssClass ) && strpos( $field->cssClass, 'lead-source-field' ) !== false ) {
			$has_lead_source_field = true;
			error_log( '[rentfetch] Found existing lead source field by cssClass, skipping' );
			break;
		}
		if ( isset( $field->inputName ) && $field->inputName === 'lead_source' ) {
			$has_lead_source_field = true;
			error_log( '[rentfetch] Found existing lead source field by inputName, skipping' );
			break;
		}
		// Also check by label as a fallback
		if ( isset( $field->label ) && strtolower( $field->label ) === 'lead source' ) {
			$has_lead_source_field = true;
			error_log( '[rentfetch] Found existing lead source field by label, skipping' );
			break;
		}
	}

	// If no lead source field exists, add one (render-time fallback)
	if ( ! $has_lead_source_field ) {
		error_log( '[rentfetch] Adding new lead source field to form' );

	$lead_source_field = new GF_Field_Text();
		$lead_source_field->label = 'Lead Source';
		$lead_source_field->cssClass = 'lead-source-field';
		$lead_source_field->inputName = 'lead_source';
		$lead_source_field->allowsPrepopulate = true;
		$lead_source_field->description = 'This field is automatically populated with your lead source information.';
		$lead_source_field->isRequired = false;

		// Add to the end of the form
		$form['fields'][] = $lead_source_field;

		error_log( '[rentfetch] Lead source field added (render-time) to form ' . $form['id'] );
	} else {
		error_log( '[rentfetch] Lead source field already exists, not adding' );
	}

	return $form;
}
add_filter( 'gform_pre_render', 'rfs_add_lead_source_field' );
add_filter( 'gform_pre_validation', 'rfs_add_lead_source_field' );

/**
 * Populate lead source in submission data
 */
function rfs_populate_lead_source( $form ) {
	$lead_source = '';
	if ( isset( $_COOKIE['wordpress_rentfetch_lead_source'] ) ) {
		$lead_source = sanitize_text_field( $_COOKIE['wordpress_rentfetch_lead_source'] );
		error_log( '[rentfetch] Pre-submission - Cookie found: ' . $lead_source );
	} else {
		error_log( '[rentfetch] Pre-submission - Cookie not found' );
	}
	// Find the correct field id for this form and set the matching POST field
	$found = false;
	foreach ( $form['fields'] as $field ) {
		if ( isset( $field->inputName ) && $field->inputName === 'lead_source' ) {
			$field_id = $field->id;
			$_POST['input_' . $field_id] = $lead_source;
			error_log( '[rentfetch] Set POST input_' . $field_id . ' to: ' . $lead_source );
			$found = true;
			break;
		}
	}

	if ( ! $found ) {
		error_log( '[rentfetch] Could not find lead_source field in form ' . $form['id'] . ' during pre_submission' );
	}
}


/**
 * Persistently ensure each Gravity Form has a lead_source field (run in admin)
 */
function rfs_ensure_lead_source_field_persistent() {
	if ( ! class_exists( 'GFAPI' ) ) {
		return;
	}

	$forms = GFAPI::get_forms();
	if ( empty( $forms ) ) {
		return;
	}

	foreach ( $forms as $f ) {
		try {
			$form = GFAPI::get_form( $f['id'] );
		} catch ( Exception $e ) {
			continue;
		}

		$has = false;
		foreach ( $form['fields'] as $field ) {
			if ( isset( $field->inputName ) && $field->inputName === 'lead_source' ) {
				$has = true;
				break;
			}
		}

		if ( ! $has ) {
			$lead_field = new GF_Field_Text();
			$lead_field->label = 'Lead Source';
			$lead_field->inputName = 'lead_source';
			$lead_field->cssClass = 'lead-source-field';
			$lead_field->description = 'Automatically populated lead source';
			$lead_field->allowsPrepopulate = true;

			$form['fields'][] = $lead_field;
			$res = GFAPI::update_form( $form );
			if ( $res ) {
				error_log( '[rentfetch] Persistently added lead_source to form ' . $form['id'] );
			} else {
				error_log( '[rentfetch] Failed to persist lead_source to form ' . $form['id'] );
			}
		}
	}
}
add_action( 'admin_init', 'rfs_ensure_lead_source_field_persistent' );
/**
 * Ensure lead source is saved in entry after submission
 */
function rfs_save_lead_source_entry( $entry, $form ) {
	$lead_source = '';
	if ( isset( $_COOKIE['wordpress_rentfetch_lead_source'] ) ) {
		$lead_source = sanitize_text_field( $_COOKIE['wordpress_rentfetch_lead_source'] );
	}

	// Find our field and update the entry
	foreach ( $form['fields'] as $field ) {
		if ( isset( $field->inputName ) && $field->inputName === 'lead_source' ) {
			$field_id = $field->id;
			$entry[$field_id] = $lead_source;
			GFAPI::update_entry( $entry );
			error_log( '[rentfetch] Updated entry ' . $entry['id'] . ' field ' . $field_id . ' with: ' . $lead_source );
			break;
		}
	}
}
add_action( 'gform_after_submission', 'rfs_save_lead_source_entry', 10, 2 );

/**
 * Visually hide lead source field without making it a GF hidden field
 * Use off-screen positioning so Gravity Forms still treats it as a visible field
 */
function rfs_hide_lead_source_field_css() {
	// Only print on front-end
	if ( is_admin() ) {
		return;
	}
	echo "<style>\n";
	echo ".gfield.lead-source-field { position: absolute !important; left: -9999px !important; width: 1px !important; height: 1px !important; overflow: hidden !important; }\n";
	echo "</style>\n";
}
add_action( 'wp_head', 'rfs_hide_lead_source_field_css' );