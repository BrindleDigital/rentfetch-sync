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
function rfs_enqueue_scripts() {
	wp_register_script(
		'ajax-property-sync',
		RENTFETCHSYNC_PATH . '/assets/js/ajax-property-sync.js',
		array( 'jquery' ),
		null,
		true
	);
}
add_action('admin_enqueue_scripts', 'rfs_enqueue_scripts');
