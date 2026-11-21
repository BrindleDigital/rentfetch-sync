<?php
/** Prepare only disposable fixture data before the next real plugin bootstrap. */
if ( ! str_contains( ABSPATH, '/rentfetch-followup-test-' ) ) {
	throw new RuntimeException( 'Disposable site required.' );
}
$case = json_decode( getenv( 'RF_MIXED_CASE' ), true );
$plugins = 'sync-first' === $case['order']
	? array( 'rentfetch-sync/rentfetch-sync.php', 'rentfetch/rentfetch.php' )
	: array( 'rentfetch/rentfetch.php', 'rentfetch-sync/rentfetch-sync.php' );
update_option( 'active_plugins', $plugins );
update_option( 'rentfetch_options_data_sync', 'nosync' );
update_option( 'rentfetch_options_enabled_integrations', array( 'yardi', 'rentegi' ) );
update_option( 'rentfetch_options_yardi_integration_creds_yardi_property_code', 'MIXED_YARDI' );
update_option( 'rentfetch_options_yardi_integration_creds_yardi_api_key', 'fixture' );
update_option( 'rentfetch_options_yardi_integration_creds_yardi_company_code', 'fixture' );
update_option( 'rentfetch_options_rentegi_property_codes', '184AST' );
delete_option( 'rfs_api_sync_locked' );
delete_option( 'rfs_sync_deactivated' );
delete_transient( 'rentfetch_api_info' );
foreach ( array( 'properties' => 'property', 'floorplans' => 'floorplan', 'units' => 'unit' ) as $type => $singular ) {
	foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => 'property_id', 'meta_value' => '184AST' ) ) as $id ) {
		wp_delete_post( $id, true );
	}
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Saved RentEGI fixture', 'post_content' => 'Preserve this content', 'meta_input' => array( 'property_id' => '184AST', $singular . '_source' => 'rentegi', $singular . '_id' => 'properties' === $type ? '184AST' : 'SAVED_RECORD' ) ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Fixture insert failed.' );
	}
	update_option( 'mixed_saved_' . $type, $id );
}
