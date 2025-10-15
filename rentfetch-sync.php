<?php
/*
	Plugin Name:   Rent Fetch Sync
	Plugin URI:    https://github.com/jonschr/rentfetch-sync
	Description:   An addon for Rent Fetch that syncs properties, floorplans, and units
	Version:       0.11.1
	Author:        Brindle Digital
	Author URI:    https://www.brindledigital.com/

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
if ( ! defined( 'ABSPATH' ) ) {
	die( 'Sorry, you are not allowed to access this page directly.' );
}

// Define the version of the plugin.
define( 'RENTFETCHSYNC_VERSION', '0.11.1' );

// Plugin directory.
define( 'RENTFETCHSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RENTFETCHSYNC_PATH', plugin_dir_url( __FILE__ ) );
define( 'RENTFETCHSYNC_FILE', __FILE__ );

// Register deactivation hook.
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

// include action scheduler.
require_once plugin_dir_path( __FILE__ ) . 'vendor/action-scheduler/action-scheduler.php';

/**
 * Include all files in a directory and its subdirectories.
 *
 * @param   string $directory  The path to the directory to load.
 *
 * @return  void
 */
function rfs_require_files_recursive( $directory ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && $file->getExtension() === 'php' ) {
			require_once $file->getPathname();
		}
	}
}

// require_once all files in /lib and its subdirectories.
rfs_require_files_recursive( RENTFETCHSYNC_DIR . 'lib' );

// Load Plugin Update Checker.
require RENTFETCHSYNC_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
$update_checker = Puc_v4_Factory::buildUpdateChecker(
	'https://github.com/BrindleDigital/rentfetch-sync',
	__FILE__,
	'rentfetch-sync'
);

// Optional: Set the branch that contains the stable release.
$update_checker->setBranch( 'main' );
