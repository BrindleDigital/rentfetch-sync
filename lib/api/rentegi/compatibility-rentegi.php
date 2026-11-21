<?php
/** Keep RentEGI inactive until its companion Core features are available. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Feature checks also support development builds whose versions are not bumped yet. */
function rfs_rentegi_core_is_compatible() {
	return function_exists( 'rentfetch_get_floorplan_unit_query_args' )
		&& function_exists( 'rentfetch_property_syncing_fields_rentegi' );
}

function rfs_rentegi_core_update_message() {
	return __( 'Update Rent Fetch to enable RentEGI syncing. Saved RentEGI settings and inventory are preserved. Other integrations remain available.', 'rentfetch-sync' );
}

function rfs_rentegi_core_update_notice() {
	if ( rfs_rentegi_core_is_compatible() || ! current_user_can( 'manage_options' )
		|| ! in_array( 'rentegi', (array) get_option( 'rentfetch_options_enabled_integrations', array() ), true ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' . esc_html( rfs_rentegi_core_update_message() ) . '</p></div>';
}
add_action( 'admin_notices', 'rfs_rentegi_core_update_notice' );
