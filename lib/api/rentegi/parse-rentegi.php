<?php
/** Parse the complete RentEGI MITS 5 inventory before any content changes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Namespace-aware element lookup; paths are internal element names only. */
function rfs_rentegi_elements( $node, $path ) {
	$parts = array_map( static function( $name ) { return '*[local-name()="' . $name . '"]'; }, explode( '/', $path ) );
	return $node->xpath( './' . implode( '/', $parts ) ) ?: array();
}

function rfs_rentegi_element( $node, $path ) {
	return rfs_rentegi_elements( $node, $path )[0] ?? null;
}

function rfs_rentegi_id( $node, $type ) {
	foreach ( rfs_rentegi_elements( $node, 'Identification' ) as $id ) {
		if ( $type === (string) $id['IDType'] ) { return trim( (string) $id['IDValue'] ); }
	}
	return '';
}

/** Only bounded, selected source fields are retained for troubleshooting. */
function rfs_rentegi_debug_text( $value, $limit = 256 ) {
	$text = sanitize_text_field( (string) $value );
	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
}

/** Missing values remain absent so manual fields are preserved. */
function rfs_rentegi_number( &$meta, $key, $value, $integer = false ) {
	$value = trim( (string) $value );
	if ( '' === $value ) { return; }
	if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 || ( $integer && (float) $value !== floor( (float) $value ) ) ) {
		throw new RuntimeException( 'RentEGI returned an invalid ' . $key . ' value. Existing content was preserved.' );
	}
	$meta[ $key ] = $integer ? (int) $value : (float) $value;
}

function rfs_rentegi_parse_feed( $xml, $property_code ) {
	if ( ! is_string( $xml ) || '' === trim( $xml ) || strlen( $xml ) > 8 * MB_IN_BYTES || preg_match( '/<!DOCTYPE|<!ENTITY/i', $xml ) ) {
		throw new RuntimeException( 'Invalid RentEGI XML. Existing content was preserved.' );
	}
	$previous = libxml_use_internal_errors( true );
	try {
		$root = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}
	if ( false === $root || 'PhysicalProperty' !== $root->getName() ) {
		throw new RuntimeException( 'RentEGI returned malformed or unexpected XML. Existing content was preserved.' );
	}
	$matches = array();
	foreach ( rfs_rentegi_elements( $root, 'Property' ) as $candidate ) {
		$id = rfs_rentegi_element( $candidate, 'PropertyID' );
		if ( $id && $property_code === rfs_rentegi_id( $id, 'PrimaryID' ) ) { $matches[] = $candidate; }
	}
	if ( 1 !== count( $matches ) ) {
		throw new RuntimeException( 'RentEGI XML did not contain exactly one matching property. Existing content was preserved.' );
	}
	$property = $matches[0];
	$identity = rfs_rentegi_element( $property, 'PropertyID' );
	$result = array( 'property' => array( 'id' => $property_code, 'name' => sanitize_text_field( (string) rfs_rentegi_element( $identity, 'MarketingName' ) ), 'meta' => array() ), 'floorplans' => array(), 'units' => array() );
	foreach ( array( 'address' => 'Address/Address', 'city' => 'Address/City', 'state' => 'Address/State', 'zipcode' => 'Address/PostalCode', 'email' => 'Email', 'phone' => 'PhoneType/PhoneNumber' ) as $key => $path ) {
		$value = rfs_rentegi_element( $identity, $path );
		if ( null !== $value ) { $result['property']['meta'][ $key ] = sanitize_text_field( (string) $value ); }
	}
	$count = rfs_rentegi_element( $property, 'Information/UnitCount' );
	rfs_rentegi_number( $result['property']['meta'], 'unit_count', $count, true );
	if ( '' === $result['property']['name'] || ! isset( $result['property']['meta']['unit_count'] ) ) {
		throw new RuntimeException( 'RentEGI property identity or inventory count is missing.' );
	}
	foreach ( rfs_rentegi_elements( $property, 'Floorplan' ) as $plan ) {
		$id = trim( (string) $plan['IDValue'] );
		$name = sanitize_text_field( (string) rfs_rentegi_element( $plan, 'Name' ) );
		if ( '' === $id || '' === $name || isset( $result['floorplans'][ $id ] ) ) { throw new RuntimeException( 'RentEGI floor plan IDs or names are missing or duplicated.' ); }
		$meta = array();
		foreach ( array( 'unit_count' => 'UnitCount', 'available_units' => 'UnitsAvailable' ) as $key => $path ) {
			rfs_rentegi_number( $meta, $key, rfs_rentegi_element( $plan, $path ), true );
		}
		foreach ( rfs_rentegi_elements( $plan, 'Room' ) as $room ) {
			$key = array( 'Bedroom' => 'beds', 'Bathroom' => 'baths' )[ (string) $room['RoomType'] ] ?? '';
			if ( $key ) { rfs_rentegi_number( $meta, $key, rfs_rentegi_element( $room, 'Count' ) ); }
		}
		foreach ( array( 'SquareFeet' => 'sqft', 'EffectiveRent' => 'rent' ) as $path => $suffix ) {
			$range = rfs_rentegi_element( $plan, $path );
			if ( null !== $range ) {
				rfs_rentegi_number( $meta, 'minimum_' . $suffix, $range['min'], 'sqft' === $suffix );
				rfs_rentegi_number( $meta, 'maximum_' . $suffix, $range['max'], 'sqft' === $suffix );
			}
		}
		$deposit = rfs_rentegi_element( $plan, 'Deposit/Amount/ValueRange' );
		if ( null !== $deposit ) {
			rfs_rentegi_number( $meta, 'minimum_deposit', $deposit['Exact'] );
			rfs_rentegi_number( $meta, 'maximum_deposit', $deposit['Exact'] );
		}
		if ( ! isset( $meta['unit_count'], $meta['available_units'] ) ) { throw new RuntimeException( 'RentEGI floor plan inventory counts are missing.' ); }
		$result['floorplans'][ $id ] = array(
			'id' => $id, 'name' => $name, 'meta' => $meta,
			'source_context' => array( 'reported_unit_count' => $meta['unit_count'], 'reported_available_units' => $meta['available_units'] ),
		);
	}
	foreach ( rfs_rentegi_elements( $property, 'ILS_Unit' ) as $entry ) {
		$unit = rfs_rentegi_element( $entry, 'Units/Unit' );
		if ( null === $unit || 1 !== count( rfs_rentegi_elements( $entry, 'Units/Unit' ) ) ) { throw new RuntimeException( 'RentEGI unit inventory is incomplete.' ); }
		$id = rfs_rentegi_id( $unit, 'UnitID' );
		$plan_id = rfs_rentegi_id( $unit, 'FloorplanID' );
		if ( '' === $id || isset( $result['units'][ $id ] ) || ! isset( $result['floorplans'][ $plan_id ] ) ) { throw new RuntimeException( 'RentEGI unit IDs or floor plan relationships are invalid.' ); }
		$status = strtolower( trim( (string) rfs_rentegi_element( $unit, 'UnitLeasedStatus' ) ) );
		if ( ! in_array( $status, array( 'available', 'on_notice', 'leased' ), true ) ) { throw new RuntimeException( 'RentEGI returned an unknown unit lease status.' ); }
		$meta = array();
		$context = array(
			'lease_status' => rfs_rentegi_debug_text( rfs_rentegi_element( $unit, 'UnitLeasedStatus' ), 64 ),
			'occupancy_status' => rfs_rentegi_debug_text( rfs_rentegi_element( $unit, 'UnitOccupancyStatus' ), 64 ),
			'vacancy_class' => rfs_rentegi_debug_text( rfs_rentegi_element( $entry, 'Availability/VacancyClass' ), 64 ),
			'charges' => array(),
		);
		foreach ( array( 'beds' => 'UnitBedrooms', 'baths' => 'UnitBathrooms' ) as $key => $path ) { rfs_rentegi_number( $meta, $key, rfs_rentegi_element( $unit, $path ) ); }
		foreach ( rfs_rentegi_elements( $entry, 'ChargeOfferClass/ChargeOfferItem' ) as $charge ) {
			$code = (string) $charge['InternalCode'];
			if ( count( $context['charges'] ) < 8 ) {
				$debug_charge = array( 'code' => rfs_rentegi_debug_text( $code, 64 ) );
				foreach ( array(
					'name' => array( 'Name', 256 ), 'description' => array( 'Description', 512 ),
					'amount' => array( 'ChargeOfferAmount/Amounts', 64 ), 'amount_basis' => array( 'AmountBasis', 64 ),
					'term_basis' => array( 'ChargeOfferAmount/TermBasis', 64 ), 'period_duration' => array( 'PeriodDuration', 64 ),
					'requirement' => array( 'Characteristics/ChargeRequirement', 64 ),
					'refundability' => array( 'Characteristics/Refundability', 64 ),
					'payment_frequency' => array( 'Characteristics/PaymentFrequency', 64 ),
				) as $key => $field ) {
					$debug_charge[ $key ] = rfs_rentegi_debug_text( rfs_rentegi_element( $charge, $field[0] ), $field[1] );
				}
				$context['charges'][] = $debug_charge;
			} else {
				$context['charges_truncated'] = true;
			}
			if ( 'RENT' === $code ) {
				rfs_rentegi_number( $meta, 'minimum_rent', rfs_rentegi_element( $charge, 'ChargeOfferAmount/Amounts' ) );
				rfs_rentegi_number( $meta, 'maximum_rent', rfs_rentegi_element( $charge, 'ChargeOfferAmount/Amounts' ) );
			} elseif ( 'DEP' === $code ) {
				rfs_rentegi_number( $meta, 'deposit', rfs_rentegi_element( $charge, 'ChargeOfferAmount/Amounts' ) );
			}
		}
		if ( ! isset( $meta['minimum_rent'] ) ) { throw new RuntimeException( 'RentEGI unit RENT charge is missing.' ); }
		$date = rfs_rentegi_element( $entry, 'Availability/MadeReadyDate' );
		if ( null !== $date ) {
			$year = (int) $date['Year']; $month = (int) $date['Month']; $day = (int) $date['Day'];
			if ( ! checkdate( $month, $day, $year ) ) { throw new RuntimeException( 'RentEGI returned an invalid ready date.' ); }
			$meta['availability_date'] = sprintf( '%02d/%02d/%04d', $month, $day, $year );
		}
		$result['units'][ $id ] = array( 'id' => $id, 'floorplan_id' => $plan_id, 'name' => sanitize_text_field( (string) rfs_rentegi_element( $unit, 'MarketingName' ) ) ?: $id, 'available' => 'leased' !== $status, 'meta' => $meta, 'source_context' => $context );
	}
	if ( count( $result['units'] ) !== $result['property']['meta']['unit_count'] || ( $result['units'] && ! $result['floorplans'] ) ) {
		throw new RuntimeException( 'RentEGI full inventory count does not match the property unit count. Existing content was preserved.' );
	}
	$counts = array();
	foreach ( $result['units'] as $unit ) {
		$plan_id = $unit['floorplan_id'];
		$counts[ $plan_id ]['total'] = ( $counts[ $plan_id ]['total'] ?? 0 ) + 1;
		$counts[ $plan_id ]['offered'] = ( $counts[ $plan_id ]['offered'] ?? 0 ) + ( $unit['available'] ? 1 : 0 );
	}
	$discrepancies = 0;
	foreach ( $result['floorplans'] as $plan_id => &$plan ) {
		$plan['source_context']['inventory_unit_count'] = $counts[ $plan_id ]['total'] ?? 0;
		$plan['source_context']['inventory_available_units'] = $counts[ $plan_id ]['offered'] ?? 0;
		$plan['warnings'] = array();
		if ( $plan['meta']['unit_count'] !== $plan['source_context']['inventory_unit_count'] ) {
			$plan['warnings'][] = 'Reported floor-plan total differs from the complete unit inventory.';
		}
		if ( $plan['meta']['available_units'] !== $plan['source_context']['inventory_available_units'] ) {
			$plan['warnings'][] = 'Reported floor-plan availability differs from offered units in the complete inventory.';
		}
		if ( $plan['warnings'] ) { ++$discrepancies; }
	}
	unset( $plan );
	$result['property']['source_context'] = array( 'inventory_unit_count' => count( $result['units'] ), 'floorplan_count_discrepancies' => $discrepancies );
	return $result;
}
