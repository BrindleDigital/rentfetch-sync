<?php

define( 'ABSPATH', __DIR__ );

$rfs_test_options = array(
	'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames' => array(
		array( 'ShortName' => 'Foxspe' ),
		array( 'ShortName' => 'VGA' ),
		array( 'ShortName' => 'CCSPE' ),
	),
	'rentfetch_options_rentmanager_disabled_property_shortnames' => array( 'VGA' ),
);
$rfs_test_posts = array(
	'properties' => array( (object) array( 'ID' => 1 ) ),
	'floorplans' => array( (object) array( 'ID' => 2 ) ),
	'units'      => array( (object) array( 'ID' => 3 ) ),
);
$rfs_test_meta    = array( 1 => 'Foxspe', 2 => 'Foxspe', 3 => 'Foxspe' );
$rfs_test_deleted = array();

function get_option( $name, $default = false ) {
	global $rfs_test_options;
	return $rfs_test_options[ $name ] ?? $default;
}

function get_posts( $args ) {
	global $rfs_test_posts;
	return $rfs_test_posts[ $args['post_type'] ] ?? array();
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	global $rfs_test_meta;
	return $rfs_test_meta[ $post_id ] ?? '';
}

function wp_delete_post( $post_id, $force_delete = false ) {
	global $rfs_test_deleted;
	$rfs_test_deleted[] = $post_id;
}

require_once dirname( __DIR__ ) . '/lib/api/rentmanager/get-properties-from-setting.php';
require_once dirname( __DIR__ ) . '/lib/api/rentmanager/remove-posts-when-property-no-longer-should-sync.php';

function rfs_test_assert_same( $expected, $actual ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

$enabled = array_column( rfs_get_enabled_rentmanager_properties(), 'ShortName' );
rfs_test_assert_same( array( 'Foxspe', 'CCSPE' ), $enabled );

$rfs_test_options['rentfetch_options_rentmanager_disabled_property_shortnames'] = array( 'Foxspe', 'VGA', 'CCSPE' );
rfs_test_assert_same( array(), rfs_get_enabled_rentmanager_properties() );

rfs_rentmanager_remove_units_that_shouldnt_be_synced_property_deleted();
rfs_rentmanager_remove_floorplans_that_shouldnt_be_synced_property_deleted();
rfs_rentmanager_remove_properties_that_shouldnt_be_synced_property_deleted();
rfs_test_assert_same( array( 3, 2, 1 ), $rfs_test_deleted );

echo "Rent Manager enabled-property tests passed.\n";
