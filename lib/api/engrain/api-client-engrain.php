<?php
/**
 * Engrain SightMap and Unit Map API client.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return a consistent failed Engrain response.
 *
 * @param string $reason Failure reason.
 * @param int    $status_code HTTP status code.
 * @param string $message Safe diagnostic message.
 * @return array
 */
function rfs_engrain_failed_response( $reason, $status_code = 0, $message = '' ) {
	return array(
		'success'       => false,
		'delete_safe'   => false,
		'data'          => null,
		'reason'        => sanitize_key( (string) $reason ),
		'status_code'   => absint( $status_code ),
		'error_message' => sanitize_text_field( (string) $message ),
	);
}

/**
 * Get an Engrain API base URL.
 *
 * @return string
 */
function rfs_engrain_get_api_base_url() {
	return untrailingslashit(
		(string) apply_filters( 'rfs_engrain_api_base_url', 'https://api.sightmap.com/v1' )
	);
}

/**
 * Validate a pagination URL before following it.
 *
 * @param string $url Pagination URL returned by Engrain.
 * @return bool
 */
function rfs_engrain_is_allowed_api_url( $url ) {
	$url_host  = wp_parse_url( $url, PHP_URL_HOST );
	$base_host = wp_parse_url( rfs_engrain_get_api_base_url(), PHP_URL_HOST );

	return $url_host && $base_host && strtolower( $url_host ) === strtolower( $base_host );
}

/**
 * Perform an authenticated Engrain GET request.
 *
 * List requests follow Engrain pagination and are deletion-safe only after every
 * page succeeds and contains a valid data array.
 *
 * @param array  $args Sync args containing runtime credentials.
 * @param string $path API path relative to /v1.
 * @param array  $options Request options: list, experimental_flags, query.
 * @return array Structured response.
 */
function rfs_engrain_api_get( $args, $path, $options = array() ) {
	$api_key  = isset( $args['credentials']['engrain']['api_key'] )
		? trim( (string) $args['credentials']['engrain']['api_key'] )
		: '';
	$base_url = rfs_engrain_get_api_base_url();

	if ( '' === $api_key || '' === $base_url || '' === trim( (string) $path ) ) {
		return rfs_engrain_failed_response( 'missing_credentials_or_endpoint' );
	}

	$is_list = ! empty( $options['list'] );
	$query   = isset( $options['query'] ) && is_array( $options['query'] ) ? $options['query'] : array();

	if ( $is_list && ! isset( $query['per-page'] ) ) {
		$query['per-page'] = 1000;
	}

	$url = $base_url . '/' . ltrim( (string) $path, '/' );
	if ( ! empty( $query ) ) {
		$url = add_query_arg( $query, $url );
	}

	$headers = array(
		'API-Key' => $api_key,
		'Accept'  => 'application/json',
	);

	if ( ! empty( $options['experimental_flags'] ) ) {
		$headers['Experimental-Flags'] = sanitize_text_field( (string) $options['experimental_flags'] );
	}

	$all_data    = array();
	$page_count  = 0;
	$status_code = 0;

	do {
		++$page_count;
		if ( $page_count > 100 || ! rfs_engrain_is_allowed_api_url( $url ) ) {
			return rfs_engrain_failed_response( 'unsafe_or_excessive_pagination', $status_code );
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return rfs_engrain_failed_response( 'request_error', 0, $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			$error_message = '';
			$error_body    = json_decode( $body, true );
			if ( is_array( $error_body ) && ! empty( $error_body['message'] ) ) {
				$error_message = (string) $error_body['message'];
			}

			return rfs_engrain_failed_response(
				403 === $status_code ? 'access_denied' : 'unexpected_response_code',
				$status_code,
				$error_message
			);
		}

		if ( '' === trim( (string) $body ) ) {
			return rfs_engrain_failed_response( 'blank_response', $status_code );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return rfs_engrain_failed_response( 'invalid_json', $status_code, json_last_error_msg() );
		}

		if ( ! $is_list ) {
			$data = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : $decoded;

			return array(
				'success'       => true,
				'delete_safe'   => false,
				'data'          => $data,
				'reason'        => 'valid_resource',
				'status_code'   => $status_code,
				'error_message' => '',
			);
		}

		if ( ! array_key_exists( 'data', $decoded ) || ! is_array( $decoded['data'] ) ) {
			return rfs_engrain_failed_response( 'missing_data_array', $status_code );
		}

		$all_data = array_merge( $all_data, $decoded['data'] );
		$next_url = isset( $decoded['paging']['next_url'] ) ? trim( (string) $decoded['paging']['next_url'] ) : '';
		$url      = $next_url;
	} while ( '' !== $url );

	return array(
		'success'       => true,
		'delete_safe'   => true,
		'data'          => $all_data,
		'reason'        => empty( $all_data ) ? 'valid_empty_data_array' : 'valid_data_array',
		'status_code'   => $status_code,
		'error_message' => '',
	);
}

/**
 * Get a single SightMap asset.
 *
 * @param array $args Sync args.
 * @return array
 */
function rfs_engrain_get_asset( $args ) {
	return rfs_engrain_api_get( $args, 'assets/' . rawurlencode( (string) $args['property_id'] ) );
}

/**
 * Get an asset-scoped SightMap multifamily list.
 *
 * @param array  $args Sync args.
 * @param string $resource_name Resource path.
 * @param string $experimental_flags Optional experimental flags.
 * @param array  $query Optional query arguments.
 * @return array
 */
function rfs_engrain_get_multifamily_list( $args, $resource_name, $experimental_flags = '', $query = array() ) {
	return rfs_engrain_api_get(
		$args,
		'assets/' . rawurlencode( (string) $args['property_id'] ) . '/multifamily/' . ltrim( $resource_name, '/' ),
		array(
			'list'               => true,
			'experimental_flags' => $experimental_flags,
			'query'              => is_array( $query ) ? $query : array(),
		)
	);
}

/**
 * Get pricing processes for an asset.
 *
 * @param array $args Sync args.
 * @return array
 */
function rfs_engrain_get_pricing_processes( $args ) {
	return rfs_engrain_get_multifamily_list( $args, 'pricing' );
}

/**
 * Select the most appropriate pricing process returned by SightMap.
 *
 * Primary and approved processes are preferred, followed by the most recently
 * updated process. This keeps the selection deterministic when an asset has
 * historical or secondary pricing integrations.
 *
 * @param array $processes Pricing process rows.
 * @return array|null
 */
function rfs_engrain_select_pricing_process( $processes ) {
	$candidates = array_values(
		array_filter(
			(array) $processes,
			static function ( $process ) {
				return is_array( $process ) && ! empty( $process['id'] );
			}
		)
	);

	if ( empty( $candidates ) ) {
		return null;
	}

	usort(
		$candidates,
		static function ( $left, $right ) {
			$left_tags   = array_map( 'strtolower', array_map( 'strval', (array) ( $left['tags'] ?? array() ) ) );
			$right_tags  = array_map( 'strtolower', array_map( 'strval', (array) ( $right['tags'] ?? array() ) ) );
			$left_score  = ( in_array( 'primary', $left_tags, true ) ? 2 : 0 ) + ( in_array( 'approved', $left_tags, true ) ? 1 : 0 );
			$right_score = ( in_array( 'primary', $right_tags, true ) ? 2 : 0 ) + ( in_array( 'approved', $right_tags, true ) ? 1 : 0 );

			if ( $left_score !== $right_score ) {
				return $right_score <=> $left_score;
			}

			$left_updated  = isset( $left['updated_at'] ) ? strtotime( (string) $left['updated_at'] ) : 0;
			$right_updated = isset( $right['updated_at'] ) ? strtotime( (string) $right['updated_at'] ) : 0;

			return $right_updated <=> $left_updated;
		}
	);

	return $candidates[0];
}

/**
 * Get the representative unit rows for a pricing process.
 *
 * @param array  $args Sync args.
 * @param string $process_id Pricing process ID.
 * @return array
 */
function rfs_engrain_get_pricing_units( $args, $process_id ) {
	if ( '' === trim( (string) $process_id ) ) {
		return rfs_engrain_failed_response( 'missing_pricing_process_id' );
	}

	return rfs_engrain_get_multifamily_list(
		$args,
		'pricing/' . rawurlencode( (string) $process_id ) . '/units'
	);
}

/**
 * Get pricing entries for a process in its flat-pricing representation.
 *
 * Revenue-management processes may still return multiple entries per unit;
 * callers normalize both flat and nested response shapes.
 *
 * @param array  $args Sync args.
 * @param string $process_id Pricing process ID.
 * @return array
 */
function rfs_engrain_get_pricing_entries( $args, $process_id ) {
	if ( '' === trim( (string) $process_id ) ) {
		return rfs_engrain_failed_response( 'missing_pricing_process_id' );
	}

	return rfs_engrain_get_multifamily_list(
		$args,
		'pricing/' . rawurlencode( (string) $process_id ) . '/entries',
		'',
		array( 'flat-pricing' => '1' )
	);
}

/**
 * Get SightMap instances for an asset.
 *
 * @param array $args Sync args.
 * @return array
 */
function rfs_engrain_get_sightmaps( $args ) {
	return rfs_engrain_get_multifamily_list( $args, 'sightmaps', 'sightmaps-resource' );
}

/**
 * Select the primary SightMap instance, falling back to the most recent one.
 *
 * @param array $sightmaps SightMap rows.
 * @return array|null
 */
function rfs_engrain_select_sightmap( $sightmaps ) {
	$candidates = array_values(
		array_filter(
			(array) $sightmaps,
			static function ( $sightmap ) {
				return is_array( $sightmap ) && ! empty( $sightmap['id'] );
			}
		)
	);

	if ( empty( $candidates ) ) {
		return null;
	}

	usort(
		$candidates,
		static function ( $left, $right ) {
			$left_tags   = array_map( 'strtolower', array_map( 'strval', (array) ( $left['tags'] ?? array() ) ) );
			$right_tags  = array_map( 'strtolower', array_map( 'strval', (array) ( $right['tags'] ?? array() ) ) );
			$left_score  = in_array( 'primary', $left_tags, true ) ? 1 : 0;
			$right_score = in_array( 'primary', $right_tags, true ) ? 1 : 0;

			if ( $left_score !== $right_score ) {
				return $right_score <=> $left_score;
			}

			$left_updated  = isset( $left['updated_at'] ) ? strtotime( (string) $left['updated_at'] ) : 0;
			$right_updated = isset( $right['updated_at'] ) ? strtotime( (string) $right['updated_at'] ) : 0;

			return $right_updated <=> $left_updated;
		}
	);

	return $candidates[0];
}

/**
 * Get SightMap All-In Pricing rows for the selected instance.
 *
 * @param array  $args Sync args.
 * @param string $sightmap_id SightMap instance ID.
 * @return array
 */
function rfs_engrain_get_all_in_pricing( $args, $sightmap_id ) {
	if ( '' === trim( (string) $sightmap_id ) ) {
		return rfs_engrain_failed_response( 'missing_sightmap_id' );
	}

	return rfs_engrain_get_multifamily_list(
		$args,
		'sightmaps/' . rawurlencode( (string) $sightmap_id ) . '/units/pricing',
		'all-in-pricing'
	);
}

/**
 * Fetch unit-description groups, descriptions, and unit assignments.
 *
 * The nested requests are returned as one authoritative bundle only when all
 * calls succeed, preventing a partial response from erasing stored amenities.
 *
 * @param array $args Sync args.
 * @return array
 */
function rfs_engrain_get_unit_description_bundle( $args ) {
	$groups_response = rfs_engrain_get_multifamily_list(
		$args,
		'units/description-groups',
		'unit-descriptions-resource'
	);

	if ( empty( $groups_response['success'] ) ) {
		return $groups_response;
	}

	$bundle = array(
		'groups'       => $groups_response['data'],
		'descriptions' => array(),
		'assignments'  => array(),
	);

	foreach ( (array) $groups_response['data'] as $group ) {
		if ( ! is_array( $group ) || empty( $group['id'] ) ) {
			continue;
		}

		$group_id              = (string) $group['id'];
		$base                  = 'units/description-groups/' . rawurlencode( $group_id );
		$descriptions_response = rfs_engrain_get_multifamily_list(
			$args,
			$base . '/descriptions',
			'unit-descriptions-resource'
		);
		if ( empty( $descriptions_response['success'] ) ) {
			return $descriptions_response;
		}

		$assignments_response = rfs_engrain_get_multifamily_list(
			$args,
			$base . '/units',
			'unit-descriptions-resource'
		);
		if ( empty( $assignments_response['success'] ) ) {
			return $assignments_response;
		}

		$bundle['descriptions'][ $group_id ] = $descriptions_response['data'];
		$bundle['assignments'][ $group_id ]  = $assignments_response['data'];
	}

	return array(
		'success'       => true,
		'delete_safe'   => true,
		'data'          => $bundle,
		'reason'        => 'valid_unit_descriptions',
		'status_code'   => 200,
		'error_message' => '',
	);
}

/**
 * Fetch entries for each floorplan or unit expense definition.
 *
 * @param array  $args Sync args.
 * @param array  $definitions Expense definitions.
 * @param string $scope Either floor-plans or units.
 * @return array
 */
function rfs_engrain_get_expense_entries( $args, $definitions, $scope ) {
	if ( ! in_array( $scope, array( 'floor-plans', 'units' ), true ) ) {
		return rfs_engrain_failed_response( 'invalid_expense_scope' );
	}

	$entries = array();
	foreach ( (array) $definitions as $definition ) {
		if ( ! is_array( $definition ) || empty( $definition['id'] ) ) {
			continue;
		}

		$response = rfs_engrain_get_multifamily_list(
			$args,
			$scope . '/expenses/' . rawurlencode( (string) $definition['id'] ) . '/entries',
			'expenses'
		);

		if ( empty( $response['success'] ) ) {
			return $response;
		}

		$entries[ (string) $definition['id'] ] = $response['data'];
	}

	return array(
		'success'       => true,
		'delete_safe'   => true,
		'data'          => $entries,
		'reason'        => 'valid_expense_entries',
		'status_code'   => 200,
		'error_message' => '',
	);
}
