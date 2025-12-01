<?php
/**
 * Send leads to Yardi API.
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

function rentfetch_send_lead_to_yardi( $form_data, $property_source, $property ) {
	return array(
		'success' => false,
		'status_code' => 0,
		'message' => 'Yardi API not currently implemented for leads',
	);
}