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
		'rentfetch-ajax-property-sync',
		RENTFETCHSYNC_PATH . 'assets/js/rentfetch-ajax-property-sync.js',
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
		RENTFETCHSYNC_PATH . 'assets/js/rentfetch-form-submission-handler.js',
		array( 'jquery' ),
		RENTFETCHSYNC_VERSION,
		true
	);

	// Script that populates runtime-only values (lead_source) to avoid cached HTML values
	wp_register_script(
		'rentfetch-form-populate',
		RENTFETCHSYNC_PATH . 'assets/js/rentfetch-form-populate-lead-source.js',
		array( 'jquery' ),
		RENTFETCHSYNC_VERSION,
		false // Changed to false to load in head so it's available when Gravity Forms loads
	);
	
	wp_register_script(
		'rentfetch-form-entrata-availability',
		RENTFETCHSYNC_PATH . 'assets/js/rentfetch-form-entrata-availability.js',
		array( 'jquery' ),
		RENTFETCHSYNC_VERSION,
		true
	);
	
	wp_register_script(
		'rentfetch-form-availability-blaze-slider-init',
		RENTFETCHSYNC_PATH . 'assets/js/rentfetch-form-availability-blaze-slider-init.js',
		array( 'blaze-script' ),
		RENTFETCHSYNC_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'rfs_enqueue_frontend_scripts' );

/**
 * Conditionally enqueue scripts based on page content
 *
 * @return void
 */
function rfs_enqueue_conditional_scripts() {
	// Check if Gravity Forms is active and if we're on a page with Gravity Forms
	$gf_active = class_exists( 'GFAPI' );
	$has_forms = rfs_has_gravity_forms();

	if ( $gf_active && $has_forms ) {
		wp_enqueue_script( 'rentfetch-form-populate' );
	}
}
add_action( 'wp_enqueue_scripts', 'rfs_enqueue_conditional_scripts' );

/**
 * Check if the current page has any Gravity Forms
 *
 * @return bool
 */
function rfs_has_gravity_forms() {
	global $post;
	
	// Check if Gravity Forms is active
	if ( ! class_exists( 'GFAPI' ) ) {
		return false;
	}

	// Check if we're on a singular page/post and it contains Gravity Forms shortcode
	if ( is_singular() && isset( $post->post_content ) ) {
		if ( has_shortcode( $post->post_content, 'gravityform' ) ||
			 has_shortcode( $post->post_content, 'gravityforms' ) ||
			 strpos( $post->post_content, '[gravityform' ) !== false ) {
			return true;
		}
	}

	// Check if Gravity Forms blocks are present (Gutenberg)
	if ( function_exists( 'has_block' ) && has_block( 'gravityforms/form' ) ) {
		return true;
	}

	// Simplified fallback: if Gravity Forms is active and has forms, enqueue on most pages
	// This covers cases where forms are loaded via widgets, theme templates, or dynamically
	$forms = GFAPI::get_forms();
	
	if ( ! empty( $forms ) ) {
		
		// Only exclude on pages where forms are very unlikely (search, 404)
		if ( ! is_search() && ! is_404() ) {
			return true;
		}

	}

	return false;
}