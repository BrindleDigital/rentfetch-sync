<?php
/*
	Plugin Name: Rent Fetch Sync
	Plugin URI: https://github.com/jonschr/rentfetch-sync
	Description: An addon for Rent Fetch that syncs properties, floorplans, and units 
	Version: 0.4.0
	Author: Brindle Digital
	Author URI: https://www.brindledigital.com/

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.
*/

/* Prevent direct access to the plugin */
if ( !defined( 'ABSPATH' ) ) {
	die( "Sorry, you are not allowed to access this page directly." );
}

// Define the version of the plugin
define ( 'RENTFETCHSYNC_VERSION', '0.4.0' );

// Plugin directory
define( 'RENTFETCHSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RENTFETCHSYNC_PATH', plugin_dir_url( __FILE__ ) );

//////////////////////////////
// INCLUDE ACTION SCHEDULER //
//////////////////////////////

require_once( plugin_dir_path( __FILE__ ) . 'vendor/action-scheduler/action-scheduler.php' );

//////////////////////
// INCLUDE SURECART //
//////////////////////

if ( ! class_exists( 'SureCart\Licensing\Client' ) ) {
	require_once RENTFETCHSYNC_DIR . '/vendor/surecart/src/Client.php';
}

// initialize client with your plugin name.
$client = new \SureCart\Licensing\Client( 'Rent Fetch Sync', __FILE__ );

// set your textdomain.
$client->set_textdomain( 'rentfetch-sync' );

// add the pre-built license settings page.
$client->settings()->add_page( 
	[
	'type'                 => 'submenu', // Can be: menu, options, submenu.
	'parent_slug'          => 'rentfetch-options', // add your plugin menu slug.
	'page_title'           => 'Manage License',
	'menu_title'           => 'Manage License',
	'capability'           => 'manage_options',
	'menu_slug'            => 'rentfetch-sync-manage-license',
	'icon_url'             => '',
	'position'             => null,
	'parent_slug'          => '',
	'activated_redirect'   => admin_url( 'admin.php?page=rentfetch-options' ), // should you want to redirect on activation of license.
	// 'deactivated_redirect' => admin_url( 'admin.php?page=my-plugin-deactivation-page' ), // should you want to redirect on detactivation of license.
	] 
);

// Surecart doesn't seem to actually add this by default, so we'll add it here
function rentfetch_sync_options_page() {
	
	add_submenu_page(
		'rentfetch-options', // Parent menu slug.
		'Rentfetch Sync Licensing', // Page title.
		'Sync Licensing', // Menu title.
		'manage_options', // Capability required to access the menu.
		'rentfetch-sync-manage-license', // Menu slug.
	);
}
add_action( 'admin_menu', 'rentfetch_sync_options_page', 999 );

///////////////////
// FILE INCLUDES //
///////////////////

function rfs_require_files_recursive($directory) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ($iterator as $file) {
		if ($file->isFile() && $file->getExtension() === 'php') {
			require_once $file->getPathname();
		}
	}
}

// require_once all files in /lib and its subdirectories
rfs_require_files_recursive(RENTFETCHSYNC_DIR . 'lib');

// start the engine
add_action( 'wp_loaded', 'rfs_perform_syncs' );

// sync a single property manually
function rfs_start_sync_single_property() {
	
	//! Yardi notes
	// any fake property id return a 1020 error
	// p0556894 returns a 1050 error
	
	//! RealPage notes
	// there's a SiteID and a PmcID. The SiteID is the property ID, and the PmcID is the rental company ID
	// RealPage doesn't have any property information or photos
	
	// define what to sync
	rfs_sync_single_property( $property_id = 'p1547922', $integration = 'yardi' );
	
}
// add_action( 'wp_loaded', 'rfs_start_sync_single_property' );

// Load Plugin Update Checker.
require RENTFETCHSYNC_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
$update_checker = Puc_v4_Factory::buildUpdateChecker(
	'https://github.com/BrindleDigital/rentfetch-sync',
	__FILE__,
	'rentfetch-sync'
);

// Optional: Set the branch that contains the stable release.
$update_checker->setBranch( 'main' );
