<?php
/** Run with php tests/rentmanager-profile-availability-test.php. No HTTP or DB writes. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function current_time( $format ) { return '2026-11-03'; }
function wp_remote_retrieve_response_code( $r ) { return $r['status']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function rentfetch_clean_json_string( $v ) { return $v; }
function rfs_rentfetch_api_post( $route, $params, $timeout ) {
	$GLOBALS['requests'][] = array( $route, $params );
	return 'rentmanager/units' === $route ? array( 'status' => 200, 'body' => json_encode( $GLOBALS['units'] ) ) : $GLOBALS['profile'];
}
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
require dirname( __DIR__ ) . '/lib/api/rentmanager/api-functions-rentmanager.php';
require dirname( __DIR__ ) . '/lib/api/rentmanager/availability-rentmanager.php';
$args = array( 'property_id' => 'HAZ', 'rentmanager_property_id' => 10, 'credentials' => array( 'rentmanager' => array( 'companycode' => 'example' ) ) );
$GLOBALS['units'] = array(
	array( 'UnitID' => 4038, 'UnitTypeID' => 165, 'Name' => '3385', 'IsVacant' => false, 'Leases' => array( array( 'MoveInDate' => '2025-11-01', 'NoticeDate' => '2026-09-11', 'ExpectedMoveOutDate' => '2026-11-30', 'MoveOutDate' => '2026-11-30' ) ) ),
	array( 'UnitID' => 4079, 'UnitTypeID' => 162, 'Name' => '3446', 'IsVacant' => true, 'Leases' => array( array( 'MoveInDate' => '2026-11-20' ) ) ),
);
function profile( $status, $data ) { $GLOBALS['profile'] = array( 'status' => $status, 'body' => json_encode( $data ) ); }
profile( 200, array( 'units' => array(), 'profile' => 'default' ) );
$r = rfs_rentmanager_get_units_data( $args );
check( $r['success'] && $r['delete_safe'], 'A successful empty availability list is authoritative.' );
foreach ( $r['units'] as $u ) { check( ! rfs_rentmanager_derive_unit_availability( $u )['available'], 'An empty profile must not fall back to leases.' ); }
check( rfs_rentmanager_derive_unit_availability( $GLOBALS['units'][0] )['available'], 'The legacy calculation reproduces the 3385 discrepancy.' );
check( ! rfs_rentmanager_derive_unit_availability( $GLOBALS['units'][1] )['available'], 'The legacy calculation excludes a future lease.' );
profile( 200, array( 'units' => array( array( 'UnitID' => 4038, 'PropertyID' => 10 ) ), 'profile' => 'default' ) );
$r = rfs_rentmanager_get_units_data( $args );
$a = rfs_rentmanager_derive_unit_availability( $r['units'][0] );
check( $a['available'] && '11/30/2026' === $a['availability_date'] && 'availability_profile' === $a['reason'], 'Profile membership retains the future advertised date.' );
check( ! rfs_rentmanager_derive_unit_availability( $r['units'][1] )['available'], 'Only listed unit IDs are eligible.' );
$override = $GLOBALS['units'][1]; $override['RentFetchProfileAvailable'] = true;
check( rfs_rentmanager_derive_unit_availability( $override )['available'], 'The account profile controls eligibility even when its rules differ from our fallback.' );
foreach ( array( array( 404, 'rest_no_route' ), array( 409, 'rentmanager_availability_unconfigured' ), array( 501, 'rentmanager_availability_unsupported' ) ) as list( $status, $code ) ) {
	profile( $status, array( 'code' => $code ) );
	$r = rfs_rentmanager_get_units_data( $args );
	check( $r['success'] && $r['availability']['fallback'] && ! isset( $r['units'][0]['RentFetchProfileAvailable'] ), 'Old API or explicit unsupported configuration must use the unchanged calculation.' );
}
foreach ( array( 400, 401, 403, 404, 429, 500, 502, 503 ) as $status ) {
	profile( $status, array( 'code' => 'other_error' ) );
	$r = rfs_rentmanager_get_units_data( $args );
	check( ! $r['success'] && ! $r['delete_safe'] && null === $r['units'], 'Ordinary HTTP failures must preserve inventory: ' . $status );
}
$GLOBALS['profile'] = new WP_Error();
check( ! rfs_rentmanager_get_units_data( $args )['success'], 'Transport errors must not trigger fallback.' );
foreach ( array( null, array(), array( 'units' => array(), 'profile' => null ), array( 'units' => array( array( 'UnitID' => 4038, 'PropertyID' => 6 ) ), 'profile' => 'default' ), array( 'units' => array( array( 'UnitID' => 9999, 'PropertyID' => 10 ) ), 'profile' => 'default' ), array( 'units' => array( array( 'UnitID' => 4038, 'PropertyID' => 10 ), array( 'UnitID' => 4038, 'PropertyID' => 10 ) ), 'profile' => 'default' ) ) as $bad ) {
	profile( 200, $bad ); check( ! rfs_rentmanager_get_units_data( $args )['success'], 'Malformed, cross-property, duplicate or unmatched records must fail closed.' );
}
$GLOBALS['profile'] = array( 'status' => 200, 'body' => '{broken' );
check( ! rfs_rentmanager_get_units_data( $args )['success'], 'Malformed JSON must fail closed.' );
$GLOBALS['profile'] = array( 'status' => 200, 'body' => '{"units":{},"profile":"default"}' );
check( ! rfs_rentmanager_get_units_data( $args )['success'], 'An empty object must not masquerade as an authoritative empty list.' );
profile( 200, array( 'units' => array( array( 'UnitID' => true, 'PropertyID' => 10 ) ), 'profile' => 'default' ) );
check( ! rfs_rentmanager_get_units_data( $args )['success'], 'Boolean identifiers fail closed.' );
profile( 200, array( 'units' => array(), 'profile' => 'wrong-profile' ) );
check( ! rfs_rentmanager_get_units_data( $args )['success'], 'A response for a different profile fails closed.' );
echo "Rent Manager profile availability checks passed.\n";
