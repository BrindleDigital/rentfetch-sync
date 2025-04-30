<?php
/**
 * Enqueue stuff
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Enqueue the scripts
 *
 * @return  void.
 */
function rfs_enqueue_backend_scripts() {
	wp_register_script(
		'ajax-property-sync',
		RENTFETCHSYNC_PATH . '/assets/js/ajax-property-sync.js',
		array( 'jquery' ),
		RENTFETCHSYNC_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'rfs_enqueue_backend_scripts' );

/**
 * Enqueue the frontend scripts
 *
 * @return void
 */
function rfs_enqueue_frontend_scripts() {
	wp_register_script(
		'rentfetch-form-script', // Handle
		RENTFETCHSYNC_PATH . '/assets/js/rentfetch-form-submission-handler.js', // Path to your JS file (adjust path relative to enqueue.php)
		array( 'jquery' ), // Dependencies
		RENTFETCHSYNC_VERSION, // Version
		true // In footer
	);
}
add_action( 'wp_enqueue_scripts', 'rfs_enqueue_frontend_scripts' );