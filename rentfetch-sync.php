<?php
/*
	Plugin Name: Rent Fetch Sync
	Plugin URI: https://github.com/jonschr/rentfetch-sync
    Description: An addon for Rent Fetch that syncs properties 
	Version: 0.1
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
define ( 'RENTFETCHSYNC_VERSION', '0.1' );

// Plugin directory
define( 'RENTFETCHSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RENTFETCHSYNC_PATH', plugin_dir_url( __FILE__ ) );