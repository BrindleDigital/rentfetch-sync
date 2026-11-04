<?php
/** Authoritative profile membership, with explicit compatibility fallback only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function rfs_rentmanager_get_profile_availability( $args ) {
	$profile = (string) apply_filters( 'rfs_rentmanager_availability_profile', '', $args );
	$response = rfs_rentfetch_api_post( 'rentmanager/unit-availability', array(
		'company_code' => $args['credentials']['rentmanager']['companycode'],
		'property_id' => $args['rentmanager_property_id'],
		'profile_name' => $profile,
	), 60 );
	$result = array( 'success' => false, 'fallback' => false, 'reason' => 'availability_request_failed', 'profile' => '' === $profile ? 'default' : $profile );
	if ( is_wp_error( $response ) ) { return $result; }
	$status = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	$shape = json_decode( $body );
	$result['status_code'] = $status;
	$code = is_array( $data ) ? ( $data['code'] ?? '' ) : '';
	$result['reason'] = is_string( $code ) && '' !== $code ? $code : 'availability_invalid_response';
	if ( is_array( $data ) && isset( $data['rentmanager_status'] ) && is_numeric( $data['rentmanager_status'] ) ) {
		$result['rentmanager_status'] = (int) $data['rentmanager_status'];
	}
	if ( ( 404 === $status && 'rest_no_route' === $code )
		|| ( 409 === $status && 'rentmanager_availability_unconfigured' === $code )
		|| ( 501 === $status && 'rentmanager_availability_unsupported' === $code ) ) {
		$result['success'] = true;
		$result['fallback'] = true;
		$result['reason'] = $code;
		return $result;
	}
	if ( 200 !== $status || ! is_array( $data ) || ! is_array( $shape->units ?? null )
		|| array_values( $data['units'] ) !== $data['units'] || $result['profile'] !== ( $data['profile'] ?? null ) ) { return $result; }
	$ids = array();
	foreach ( $data['units'] as $unit ) {
		if ( ! is_array( $unit ) || ( ! is_int( $unit['UnitID'] ?? null ) && ! is_string( $unit['UnitID'] ?? null ) )
			|| ! ctype_digit( (string) $unit['UnitID'] ) || (int) $unit['UnitID'] < 1
			|| ( ! is_int( $unit['PropertyID'] ?? null ) && ! is_string( $unit['PropertyID'] ?? null ) )
			|| (string) ( $unit['PropertyID'] ?? '' ) !== (string) $args['rentmanager_property_id']
			|| isset( $ids[ (string) $unit['UnitID'] ] ) ) {
			$result['reason'] = 'availability_invalid_units';
			return $result;
		}
		$ids[ (string) $unit['UnitID'] ] = true;
	}
	$result['success'] = true;
	$result['reason'] = 'availability_profile';
	$result['unit_ids'] = $ids;
	return $result;
}

/** Keep the profile/fallback decision visible even when a sync succeeds. */
function rfs_rentmanager_record_profile_availability( $args, $response ) {
	$id = $args['wordpress_property_post_id'];
	$availability = $response['availability'] ?? array( 'success' => false, 'reason' => $response['reason'] ?? 'units_request_failed' );
	$diagnostic = $availability;
	$diagnostic['updated'] = current_time( 'mysql' );
	$diagnostic['source'] = empty( $availability['fallback'] ) ? 'profile' : 'lease_calculation';
	if ( isset( $diagnostic['unit_ids'] ) ) {
		$diagnostic['available_units'] = count( $diagnostic['unit_ids'] );
		unset( $diagnostic['unit_ids'] );
	}
	$api = get_post_meta( $id, 'api_response', true );
	$api = is_array( $api ) ? $api : array();
	$diagnostic['api_response'] = wp_json_encode( $diagnostic );
	$api['unit_availability_api'] = $diagnostic;
	update_post_meta( $id, 'api_response', $api );
	if ( empty( $availability['success'] ) ) {
		rfs_mark_sync_failed( $id, 'unit_availability_api' );
	} elseif ( ! empty( $availability['fallback'] ) ) {
		rfs_mark_sync_partial( $id, 'unit_availability_api' );
	} else {
		rfs_mark_sync_succeeded( $id, 'unit_availability_api' );
	}
}
