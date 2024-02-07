<?php


function rentfetch_check_actionscheduler_tables() {
	global $wpdb;

	$table_list = array(
		'actionscheduler_actions',
		'actionscheduler_logs',
		'actionscheduler_groups',
		'actionscheduler_claims',
	);

	$missing_tables = false; // Flag to track missing tables.

	foreach ($table_list as $table) {
		// Securely prefixing the table name.
		$table_name = $wpdb->prefix . $table;
		$query = $wpdb->prepare("SHOW TABLES LIKE %s", $table_name);
		$found_tables = $wpdb->get_col($query);

		if (empty($found_tables)) {
			$missing_tables = true;
			break; // Exit the loop if at least one table is missing.
		}
	}

	if ($missing_tables) {
		add_action('admin_notices', 'rentfetch_database_tables_missing_notice');
	}
}
add_action( 'wp_loaded', 'rentfetch_check_actionscheduler_tables' );

function rentfetch_database_tables_missing_notice() {
	echo '<div class="notice notice-error is-dismissible">';
	echo wp_kses_post( '<p>' . _x( '<strong>Rent Fetch:</strong> The Action Scheduler tables appear to be missing. Please <a href="/wp-admin/tools.php?page=action-scheduler">vist the Action Scheduler admin page</a> to regenerate those.', 'rentfetch' ) . '</p> ' );
	echo '</div>';
}