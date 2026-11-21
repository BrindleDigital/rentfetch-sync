<?php
/** Standalone feature-detection and administrator-notice regression. */
define( 'ABSPATH', __DIR__ );
$enabled = array( 'rentegi' );
$admin = true;
function add_action( ...$args ) {}
function __( $message, $domain ) { return $message; }
function esc_html( $message ) { return htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ); }
function get_option( $key, $default = false ) { return $GLOBALS['enabled']; }
function current_user_can( $capability ) { return $GLOBALS['admin']; }
function compatibility_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
require dirname( __DIR__ ) . '/lib/api/rentegi/compatibility-rentegi.php';
compatibility_check( ! rfs_rentegi_core_is_compatible(), 'Missing Core must be unsupported.' );
ob_start(); rfs_rentegi_core_update_notice(); $notice = ob_get_clean();
compatibility_check( str_contains( $notice, 'Update Rent Fetch' ), 'Enabled RentEGI must explain the Core requirement.' );
$enabled = array( 'yardi' );
ob_start(); rfs_rentegi_core_update_notice(); $notice = ob_get_clean();
compatibility_check( '' === $notice, 'Other integrations must not get an irrelevant notice.' );
$enabled = array( 'rentegi' ); $admin = false;
ob_start(); rfs_rentegi_core_update_notice(); $notice = ob_get_clean();
compatibility_check( '' === $notice, 'Only administrators should get the update notice.' );
if ( true ) {
	function rentfetch_get_floorplan_unit_query_args() {}
}
compatibility_check( ! rfs_rentegi_core_is_compatible(), 'Partial Core support is insufficient.' );
if ( true ) {
	function rentfetch_property_syncing_fields_rentegi() {}
}
compatibility_check( rfs_rentegi_core_is_compatible(), 'Complete Core features must enable RentEGI without a version bump.' );
$admin = true;
ob_start(); rfs_rentegi_core_update_notice(); $notice = ob_get_clean();
compatibility_check( '' === $notice, 'The notice must clear automatically after Core updates.' );
echo "RentEGI Core compatibility passed: absent/partial/full capabilities and scoped admin notices.\n";
