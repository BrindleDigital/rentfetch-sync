<?php
/**
 * Standalone tests for Rent Manager lease-derived availability.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/lib/api/rentmanager/api-functions-rentmanager.php';

$GLOBALS['rfs_test_post_meta'] = array();

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['rfs_test_post_meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['rfs_test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql' ) {
		return '2026-08-02 12:00:00';
	}
}

/**
 * Assert that two values are identical.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure message.
 * @return void
 */
function rfs_test_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite(
			STDERR,
			sprintf(
				"FAIL: %s\nExpected: %s\nActual: %s\n",
				$message,
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
		exit( 1 );
	}
}

$today = '2026-07-30';

$GLOBALS['rfs_test_post_meta'][31]['api_response'] = array(
	'properties_api' => array( 'api_response' => 'last successful property response' ),
);
$GLOBALS['rfs_test_post_meta'][44]['api_response'] = array(
	'units_api' => array( 'api_response' => 'last successful unit response' ),
);

rfs_rentmanager_update_property_related_api_response(
	array( 'wordpress_property_post_id' => 31 ),
	'units_api',
	array(
		'success'      => false,
		'delete_safe'  => false,
		'reason'       => 'unexpected_response_code',
		'status_code'  => 403,
		'error_message' => 'Rent Manager lease access is required.',
		'raw_response' => '{"error":"Customers: View required"}',
	)
);
$property_responses = $GLOBALS['rfs_test_post_meta'][31]['api_response'];
rfs_test_assert_same( 'last successful property response', $property_responses['properties_api']['api_response'], 'A related failure preserves the property response.' );
rfs_test_assert_same( 403, $property_responses['units_api']['status_code'], 'A unit request failure is stored on the property.' );
rfs_test_assert_same( 'Rent Manager lease access is required.', $property_responses['units_api']['error_message'], 'A human-readable failure explanation is stored when available.' );
rfs_test_assert_same( 'last successful unit response', $GLOBALS['rfs_test_post_meta'][44]['api_response']['units_api']['api_response'], 'A unit request failure does not overwrite the last successful unit response.' );

rfs_rentmanager_update_property_related_api_response(
	array( 'wordpress_property_post_id' => 31 ),
	'units_api',
	array(
		'success'     => true,
		'delete_safe' => true,
		'units'       => array(),
	)
);
rfs_test_assert_same( false, array_key_exists( 'units_api', $GLOBALS['rfs_test_post_meta'][31]['api_response'] ), 'A good unit response clears the last property-level unit failure.' );

rfs_rentmanager_update_property_related_api_response(
	array( 'wordpress_property_post_id' => 31 ),
	'unit_types_api',
	array(
		'success'      => true,
		'delete_safe'  => false,
		'reason'       => 'valid_unit_types_with_unusable_records',
		'raw_response' => '[{}]',
	)
);
rfs_test_assert_same( 'valid_unit_types_with_unusable_records', $GLOBALS['rfs_test_post_meta'][31]['api_response']['unit_types_api']['reason'], 'An unsafe partial response remains visible on the property.' );

rfs_test_assert_same(
	true,
	rfs_rentmanager_unit_has_sync_identifiers( array( 'UnitID' => 12, 'UnitTypeID' => 44 ) ),
	'Documented positive unit identifiers are safe for relationship and deletion comparisons.'
);
rfs_test_assert_same(
	false,
	rfs_rentmanager_unit_has_sync_identifiers( array( 'UnitID' => 12, 'UnitTypeID' => null ) ),
	'A unit without a usable floorplan identifier is not deletion-safe.'
);

$vacant = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => true,
		'Leases'   => array(),
	),
	$today
);
rfs_test_assert_same( true, $vacant['available'], 'A vacant unit without a future lease is available.' );
rfs_test_assert_same( '07/30/2026', $vacant['availability_date'], 'A vacant unit is available today.' );

$future_lease = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => true,
		'Leases'   => array(
			array(
				'MoveInDate' => '2026-08-15T00:00:00',
			),
		),
	),
	$today
);
rfs_test_assert_same( false, $future_lease['available'], 'A future lease overrides current vacancy.' );
rfs_test_assert_same( 'future_lease', $future_lease['reason'], 'A future lease supplies the unavailable reason.' );

$on_notice = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => false,
		'Leases'   => array(
			array(
				'MoveInDate'          => '2025-09-01T00:00:00',
				'NoticeDate'          => '2026-07-15T00:00:00',
				'ExpectedMoveOutDate' => '2026-09-03T00:00:00',
			),
		),
	),
	$today
);
rfs_test_assert_same( true, $on_notice['available'], 'An occupied unit on notice has future availability.' );
rfs_test_assert_same( '09/03/2026', $on_notice['availability_date'], 'Expected move-out drives availability.' );

$on_notice_with_buffer = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => false,
		'Leases'   => array(
			array(
				'MoveInDate'          => '2025-09-01',
				'ExpectedMoveOutDate' => '2026-09-03',
			),
		),
	),
	$today,
	5
);
rfs_test_assert_same( '09/08/2026', $on_notice_with_buffer['availability_date'], 'The make-ready buffer is added to expected move-out.' );
rfs_test_assert_same( 'current_lease_expected_move_out', $on_notice_with_buffer['reason'], 'Expected move-out works even when notice date is absent.' );

$overdue_move_out_with_buffer = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => false,
		'Leases'   => array(
			array(
				'MoveInDate'          => '2025-09-01',
				'ExpectedMoveOutDate' => '2026-07-28',
			),
		),
	),
	$today,
	5
);
rfs_test_assert_same( '08/02/2026', $overdue_move_out_with_buffer['availability_date'], 'The buffer is added to expected move-out before clamping to today.' );

$long_overdue_move_out_with_buffer = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => false,
		'Leases'   => array(
			array(
				'MoveInDate'          => '2025-09-01',
				'ExpectedMoveOutDate' => '2026-07-01',
			),
		),
	),
	$today,
	5
);
rfs_test_assert_same( '07/30/2026', $long_overdue_move_out_with_buffer['availability_date'], 'An expired buffered date is available today.' );

$future_lease_after_notice = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => false,
		'Leases'   => array(
			array(
				'MoveInDate'          => '2025-09-01',
				'NoticeDate'          => '2026-07-15',
				'ExpectedMoveOutDate' => '2026-09-03',
			),
			array(
				'MoveInDate' => '2026-09-10',
			),
		),
	),
	$today
);
rfs_test_assert_same( false, $future_lease_after_notice['available'], 'A future lease overrides an outgoing lease on notice.' );

$active_lease = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => true,
		'Leases'   => array(
			array(
				'MoveInDate' => '2026-07-30',
			),
		),
	),
	$today
);
rfs_test_assert_same( false, $active_lease['available'], 'An active lease overrides a stale vacant status.' );

$notice_without_expected_date = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => false,
		'Leases'   => array(
			array(
				'MoveInDate' => '2025-09-01',
				'NoticeDate' => '2026-07-15',
			),
		),
	),
	$today
);
rfs_test_assert_same( false, $notice_without_expected_date['available'], 'Notice without expected move-out is not a usable availability date.' );

$historical_lease = rfs_rentmanager_derive_unit_availability(
	array(
		'CurrentUnitStatus' => array(
			array(
				'UnitStatusType' => array(
					'Name' => 'Vacant',
				),
			),
		),
		'Leases'            => array(
			array(
				'MoveInDate'  => '2024-01-01',
				'MoveOutDate' => '2025-01-01',
			),
		),
	),
	$today
);
rfs_test_assert_same( true, $historical_lease['available'], 'A historical lease does not block a currently vacant unit.' );

$documented_vacant_status = rfs_rentmanager_derive_unit_availability(
	array(
		'CurrentUnitStatus' => array(
			'UnitStatusType' => array(
				'Name'     => 'Make Ready',
				'IsVacant' => true,
			),
		),
		'Leases'            => array(),
	),
	$today
);
rfs_test_assert_same( true, $documented_vacant_status['available'], 'The documented UnitStatusType.IsVacant field identifies vacancy.' );

$moved_out_today = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => true,
		'Leases'   => array(
			array(
				'MoveInDate'         => '2025-01-01',
				'MoveOutDate'        => '2026-07-30',
				'IsMoveOutConfirmed' => true,
			),
		),
	),
	$today
);
rfs_test_assert_same( true, $moved_out_today['available'], 'A lease ending today does not override a currently vacant status.' );

$lease_without_move_in = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => true,
		'Leases'   => array(
			array(
				'ExpectedMoveOutDate' => '2026-09-01',
			),
		),
	),
	$today
);
rfs_test_assert_same( false, $lease_without_move_in['available'], 'An incomplete non-historical lease fails closed.' );
rfs_test_assert_same( 'lease_missing_move_in_date', $lease_without_move_in['reason'], 'An incomplete lease reports a diagnostic reason.' );

$single_future_lease = rfs_rentmanager_derive_unit_availability(
	array(
		'IsVacant' => true,
		'Leases'   => array(
			'MoveInDate' => '2026-08-01',
		),
	),
	$today
);
rfs_test_assert_same( false, $single_future_lease['available'], 'A single embedded lease object is normalized.' );

$floorplan = rfs_rentmanager_summarize_unit_availability_dates(
	array(
		'09/03/2026',
		'07/30/2026',
		'',
		null,
	)
);
rfs_test_assert_same( 2, $floorplan['available_units'], 'Floorplan availability counts current and future available units.' );
rfs_test_assert_same( '20260730', $floorplan['availability_date'], 'Floorplan availability uses the earliest unit date in Ymd format.' );

$unavailable_floorplan = rfs_rentmanager_summarize_unit_availability_dates( array( '', null, 'not-a-date' ) );
rfs_test_assert_same( 0, $unavailable_floorplan['available_units'], 'A floorplan without unit dates has no availability.' );
rfs_test_assert_same( null, $unavailable_floorplan['availability_date'], 'A floorplan without unit dates has no availability date.' );

$missing_market_rent = rfs_rentmanager_get_market_rent_range( array() );
rfs_test_assert_same( 0.0, $missing_market_rent['minimum'], 'Missing market rent does not prevent an available unit from syncing.' );
rfs_test_assert_same( 0.0, $missing_market_rent['maximum'], 'Missing market rent produces a safe zero maximum.' );

$sparse_market_rent = rfs_rentmanager_get_market_rent_range(
	array(
		array(),
		array( 'Amount' => '1,425.50' ),
		array( 'Amount' => 'not-a-price' ),
		array( 'Amount' => 1625 ),
	)
);
rfs_test_assert_same( 1425.5, $sparse_market_rent['minimum'], 'Invalid market-rent rows are ignored.' );
rfs_test_assert_same( 1625.0, $sparse_market_rent['maximum'], 'Valid market-rent rows determine the range.' );

echo "Rent Manager availability tests passed.\n";
