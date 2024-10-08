<?php
/*
	Plugin Name: Rent Fetch Sync
	Plugin URI: https://github.com/jonschr/rentfetch-sync
	Description: An addon for Rent Fetch that syncs properties, floorplans, and units 
	Version: 0.4.13
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
define ( 'RENTFETCHSYNC_VERSION', '0.4.13' );

// Plugin directory
define( 'RENTFETCHSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RENTFETCHSYNC_PATH', plugin_dir_url( __FILE__ ) );
define( 'RENTFETCHSYNC_FILE', __FILE__ );
define( 'RENTFETCHSYNC_SURECART_PUBLIC_TOKEN', 'pt_nw8Xnhfrs3tHBZZUFpdDRZ1q' );

// Register deactivation hook
register_deactivation_hook( __FILE__, 'rfs_deactivate_actions' );

/**
 * Cancel all scheduled actions on deactivation.
 *
 * @return void.
 */
function rfs_deactivate_actions() {
	as_unschedule_all_actions( 'rfs_do_sync' );
	as_unschedule_all_actions( 'rfs_yardi_do_delete_orphans' );
}


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

// initialize client with your plugin name and your public token.
$client = new SureCart\Licensing\Client( 'Rent Fetch Sync', RENTFETCHSYNC_SURECART_PUBLIC_TOKEN, __FILE__ );

// set your textdomain.
$client->set_textdomain( 'rentfetch-sync' );

// add the pre-built license settings page.
$client->settings()->add_page( 
	[
	'type'                 => 'submenu', // Can be: menu, options, submenu.
	'parent_slug'          => 'rentfetch-options', // add your plugin menu slug.
	'page_title'           => 'Manage Sync license',
	'menu_title'           => 'Manage Sync license',
	'capability'           => 'manage_options',
	'menu_slug'            => $client->slug . '-manage-license',
	'icon_url'             => '',
	'position'             => null,
	'activated_redirect'   => admin_url( 'admin.php?page=rentfetch-options' ), // should you want to redirect on activation of license.
	// 'deactivated_redirect' => admin_url( 'admin.php?page=my-plugin-deactivation-page' ), // should you want to redirect on detactivation of license.
	] 
);

// $activation = new SureCart\Licensing\Client( 'Rent Fetch Sync', 'pt_nw8Xnhfrs3tHBZZUFpdDRZ1q', __FILE__ );

// print_r( $client );
// echo $client->'public_token';
// echo $client->'slug';

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

// Load Plugin Update Checker.
require RENTFETCHSYNC_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
$update_checker = Puc_v4_Factory::buildUpdateChecker(
	'https://github.com/BrindleDigital/rentfetch-sync',
	__FILE__,
	'rentfetch-sync'
);

// Optional: Set the branch that contains the stable release.
$update_checker->setBranch( 'main' );
