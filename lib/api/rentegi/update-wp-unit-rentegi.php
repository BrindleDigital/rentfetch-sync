<?php
/** Save offered RentEGI units before the final provider-record cleanup. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rfs_rentegi_update_units( $inventory ) {
	$property_code = $inventory['property']['id'];
	foreach ( $inventory['units'] as $unit ) {
		if ( ! $unit['available'] ) { continue; }
		// Keep an empty row for public queries when the provider withdraws a date.
		$unit['meta']['availability_date'] = $unit['meta']['availability_date'] ?? '';
		rfs_rentegi_upsert( 'units', 'unit', $property_code, $unit );
	}
}
