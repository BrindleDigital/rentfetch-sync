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

		if ( $field->type === 'hidden' && isset( $field->cssClass ) && strpos( $field->cssClass, 'lead-source-field' ) !== false ) {
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

	// If no lead source field exists, add one
	if ( ! $has_lead_source_field ) {
		error_log( '[rentfetch] Adding new lead source field to form' );

		$lead_source_field = new GF_Field_Hidden();
		$lead_source_field->label = 'Lead Source';
		$lead_source_field->cssClass = 'lead-source-field';
		$lead_source_field->inputName = 'lead_source';
		$lead_source_field->allowsPrepopulate = true;
		$lead_source_field->description = 'This field is automatically populated with your lead source information.';
		$lead_source_field->isRequired = false;

		// Add to the end of the form
		$form['fields'][] = $lead_source_field;

		error_log( '[rentfetch] Lead source field added successfully' );
	} else {
		error_log( '[rentfetch] Lead source field already exists, not adding' );
	}

	return $form;
}
add_filter( 'gform_pre_render', 'rfs_add_lead_source_field' );
add_filter( 'gform_pre_validation', 'rfs_add_lead_source_field' );