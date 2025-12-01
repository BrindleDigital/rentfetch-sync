<?php
/**
 * Send leads to RentManager API.
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

function rentfetch_send_lead_to_rentmanager( $form_data, $property_source, $property ) {
	return array(
		'success' => false,
		'status_code' => 0,
		'message' => 'RentManager API not currently implemented for leads',
	);
}