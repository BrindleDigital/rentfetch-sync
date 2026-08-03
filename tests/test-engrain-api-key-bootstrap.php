<?php
/**
 * Verify that Engrain runtime credentials come from the Rent Fetch API.
 *
 * Run with: php tests/test-engrain-api-key-bootstrap.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$rfs_test_bootstrap = array(
	'engrain' => array(
		'api_key' => 'central-engrain-key',
	),
);

function wp_using_ext_object_cache() {
	return true;
}

function get_option( $key, $default = false ) {
	if ( 'rentfetch_options_enabled_integrations' === $key ) {
		return array( 'engrain' );
	}

	return $default;
}

function get_transient( $key ) {
	global $rfs_test_bootstrap;

	return 'rentfetch_api_info' === $key ? $rfs_test_bootstrap : false;
}

require_once dirname( __DIR__ ) . '/lib/api/fetch-rentfetch-api-info.php';
require_once dirname( __DIR__ ) . '/lib/api/get-credentials.php';

assert( 'central-engrain-key' === rfs_get_engrain_api_key() );
assert( 'central-engrain-key' === rfs_get_credentials()['engrain']['api_key'] );
assert( '' === rfs_validate_rentfetch_api_info_response( $rfs_test_bootstrap, array( 'engrain' ) ) );
assert(
	'Rent Fetch API response missing engrain credentials.' === rfs_validate_rentfetch_api_info_response( array(), array( 'engrain' ) )
);

echo "Engrain API key bootstrap tests passed.\n";
