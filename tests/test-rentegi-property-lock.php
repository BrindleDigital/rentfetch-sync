<?php
/** Local connection-isolation regression: wp --skip-plugins --skip-themes eval-file <this-file>. */
require_once dirname( __DIR__, 2 ) . '/rentfetch/rentfetch.php';
require_once dirname( __DIR__ ) . '/rentfetch-sync.php';
rfs_boot_plugin();

$code   = 'RFS_LOCK_TEST_' . wp_generate_password( 8, false );
$name   = rfs_property_sync_lock_name( 'rentegi', $code );
if ( 64 !== strlen( rfs_property_sync_lock_name( str_repeat( 'integration', 40 ), str_repeat( 'property', 400 ) ) ) ) {
	throw new RuntimeException( 'Long identifiers must still produce a MySQL-compatible 64-byte advisory lock name.' );
}
$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lock   = rfs_acquire_property_sync_lock( 'rentegi', $code );
$other  = rfs_property_sync_lock_name( 'rentegi', $code . '_OTHER' );
try {
	if ( '0' !== (string) $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
		throw new RuntimeException( 'A second database worker acquired an already-owned property lock.' );
	}
	if ( '1' !== (string) $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', $other ) ) ) {
		throw new RuntimeException( 'Different properties should be able to sync concurrently.' );
	}
	$rejected = false;
	try {
		rfs_acquire_property_sync_lock( 'rentegi', $code );
	} catch ( RuntimeException $exception ) {
		$rejected = true;
	}
	if ( ! $rejected ) {
		throw new RuntimeException( 'Reentrant syncs must not increment a connection lock counter.' );
	}
	rfs_assert_property_sync_lock( 'rentegi', $code );
} finally {
	rfs_release_property_sync_lock( $lock );
	$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', $other ) );
}

try {
	if ( '1' !== (string) $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
		throw new RuntimeException( 'Completed syncs must release the property for another worker.' );
	}
	$rejected = false;
	try {
		rfs_acquire_property_sync_lock( 'rentegi', $code );
	} catch ( RuntimeException $exception ) {
		$rejected = true;
	}
	if ( ! $rejected ) {
		throw new RuntimeException( 'A competing worker must not enter an active property sync.' );
	}
} finally {
	$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	$second->close();
}

$lock = rfs_acquire_property_sync_lock( 'rentegi', $code );
try {
	rfs_assert_property_sync_lock( 'rentegi', $code );
	global $wpdb;
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	$rejected = false;
	try {
		rfs_assert_property_sync_lock( 'rentegi', $code );
	} catch ( RuntimeException $exception ) {
		$rejected = true;
	}
	if ( ! $rejected ) {
		throw new RuntimeException( 'Lost database locks must prevent further inventory writes.' );
	}
} finally {
	rfs_release_property_sync_lock( $lock );
}

echo "RentEGI property lock regressions passed: competing connections, independent properties, reentry, release and lost-lock detection.\n";
