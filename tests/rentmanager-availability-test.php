<?php
/**
 * Standalone tests for Rent Manager lease-derived availability.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/lib/api/rentmanager/api-functions-rentmanager.php';

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
				'MoveInDate'          => '2025-01-01',
				'MoveOutDate'         => '2026-07-30',
				'IsMoveOutConfirmed'  => true,
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

echo "Rent Manager availability tests passed.\n";
