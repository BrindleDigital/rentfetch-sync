<?php
/**
 * Rent manager remove properties/floorplans/units when the associated property is no longer in the list of properties to sync.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Remove any WordPress properties that are no longer in the Rent Manager list.
 *
 * @return  void.
 */
function rfs_remove_properties_that_shouldnt_be_synced() {

	// get the property shortnames that are already in the setting.
	$property_shortnames = get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames' );

	// bail if that setting is empty.
	if ( ! isset( $property_shortnames ) || ! is_array( $property_shortnames ) ) {
		return;
	}

	// bail if the first element of the array is not an array with a ShortName key (if we encountered an error, we don't want to continue).
	if ( ! isset( $property_shortnames[0]['ShortName'] ) ) {
		return;
	}

	// let's get the WordPress posts that are properties with a property_source that equals 'rentmanager'.
	$wordpress_posts = get_posts(
		array(
			'post_type'      => 'properties',
			'posts_per_page' => -1,
			'meta_query'     => array( // phpcs:ignore
				array(
					'key'   => 'property_source',
					'value' => 'rentmanager',
				),
			),
		)
	);

	// bail if we don't have any properties.
	if ( ! isset( $wordpress_posts ) || ! is_array( $wordpress_posts ) ) {
		return;
	}

	// let's get the array of the shortnames of the properties from the setting.
	$property_shortnames = array_column( $property_shortnames, 'ShortName' );

	// if we have any $wordpress_posts that are not in the $property_shortnames, we need to delete them.
	foreach ( $wordpress_posts as $post ) {
		if ( ! in_array( get_post_meta( $post->ID, 'property_id', true ), $property_shortnames, true ) ) {
			wp_delete_post( $post->ID, true );
		}
	}
}

/**
 * Remove any WordPress floorplans that are no longer in the Rent Manager list.
 *
 * @return  void.
 */
function rfs_remove_floorplans_that_shouldnt_be_synced() {

	// get the property shortnames that are already in the setting.
	$property_shortnames = get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames' );

	// bail if that setting is empty.
	if ( ! isset( $property_shortnames ) || ! is_array( $property_shortnames ) ) {
		return;
	}

	// bail if the first element of the array is not an array with a ShortName key (if we encountered an error, we don't want to continue).
	if ( ! isset( $property_shortnames[0]['ShortName'] ) ) {
		return;
	}

	// let's get the WordPress posts that are properties with a property_source that equals 'rentmanager'.
	$wordpress_posts = get_posts(
		array(
			'post_type'      => 'floorplans',
			'posts_per_page' => -1,
			'meta_query'     => array( // phpcs:ignore
				array(
					'key'   => 'floorplan_source',
					'value' => 'rentmanager',
				),
			),
		)
	);

	// bail if we don't have any properties.
	if ( ! isset( $wordpress_posts ) || ! is_array( $wordpress_posts ) ) {
		return;
	}

	// let's get the array of the shortnames of the properties from the setting.
	$property_shortnames = array_column( $property_shortnames, 'ShortName' );

	// if we have any $wordpress_posts that are not in the $property_shortnames, we need to delete them.
	foreach ( $wordpress_posts as $post ) {
		if ( ! in_array( get_post_meta( $post->ID, 'property_id', true ), $property_shortnames, true ) ) {
			wp_delete_post( $post->ID, true );
		}
	}
}

/**
 * Remove any WordPress units that are no longer in the Rent Manager list.
 *
 * @return  void.
 */
function rfs_remove_units_that_shouldnt_be_synced() {

	// get the property shortnames that are already in the setting.
	$property_shortnames = get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames' );

	// bail if that setting is empty.
	if ( ! isset( $property_shortnames ) || ! is_array( $property_shortnames ) ) {
		return;
	}

	// bail if the first element of the array is not an array with a ShortName key (if we encountered an error, we don't want to continue).
	if ( ! isset( $property_shortnames[0]['ShortName'] ) ) {
		return;
	}

	// let's get the WordPress posts that are properties with a property_source that equals 'rentmanager'.
	$wordpress_posts = get_posts(
		array(
			'post_type'      => 'units',
			'posts_per_page' => -1,
			'meta_query'     => array( // phpcs:ignore
				array(
					'key'   => 'unit_source',
					'value' => 'rentmanager',
				),
			),
		)
	);

	// bail if we don't have any properties.
	if ( ! isset( $wordpress_posts ) || ! is_array( $wordpress_posts ) ) {
		return;
	}

	// let's get the array of the shortnames of the properties from the setting.
	$property_shortnames = array_column( $property_shortnames, 'ShortName' );

	// if we have any $wordpress_posts that are not in the $property_shortnames, we need to delete them.
	foreach ( $wordpress_posts as $post ) {
		if ( ! in_array( get_post_meta( $post->ID, 'property_id', true ), $property_shortnames, true ) ) {
			wp_delete_post( $post->ID, true );
		}
	}
}
