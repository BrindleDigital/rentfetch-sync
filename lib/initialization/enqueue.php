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
		'rentfetch-form-script',
		RENTFETCHSYNC_PATH . '/assets/js/rentfetch-form-submission-handler.js',
		array( 'jquery' ),
		RENTFETCHSYNC_VERSION,
		true
	);
	
	wp_register_script(
		'rentfetch-form-entrata-availability',
		RENTFETCHSYNC_PATH . '/assets/js/rentfetch-form-entrata-availability.js',
		array( 'jquery' ),
		RENTFETCHSYNC_VERSION,
		true
	);
	
	wp_register_script(
		'rentfetch-form-availability-blaze-slider-init',
		RENTFETCHSYNC_PATH . '/assets/js/rentfetch-form-availability-blaze-slider-init.js',
		array( 'blaze-script' ),
		RENTFETCHSYNC_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'rfs_enqueue_frontend_scripts' );