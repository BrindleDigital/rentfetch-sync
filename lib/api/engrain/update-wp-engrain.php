<?php
/**
 * Map Engrain data into Rent Fetch property, floorplan, and unit records.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recursively sanitize API data while preserving scalar types.
 *
 * @param mixed $value Value to sanitize.
 * @return mixed
 */
function rfs_engrain_sanitize_mixed( $value ) {
	if ( is_array( $value ) ) {
		$sanitized = array();
		foreach ( $value as $key => $item ) {
			$sanitized[ sanitize_key( (string) $key ) ] = rfs_engrain_sanitize_mixed( $item );
		}
		return $sanitized;
	}

	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
		return $value;
	}

	return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
}

/**
 * Remove provider-only meta written by early Engrain sync builds.
 *
 * Engrain may return useful diagnostic data that has no Rent Fetch consumer.
 * Keep that data in memory while syncing rather than persisting it as post meta.
 *
 * @param int   $post_id Post ID.
 * @param array $keys Meta keys to remove.
 * @return void
 */
function rfs_engrain_delete_unused_meta( $post_id, $keys ) {
	foreach ( $keys as $key ) {
		if ( metadata_exists( 'post', $post_id, $key ) ) {
			delete_post_meta( $post_id, $key );
		}
	}
}

/**
 * Store an API diagnostic snapshot and update endpoint sync status.
 *
 * @param int    $post_id Post ID.
 * @param string $endpoint Endpoint status key.
 * @param array  $response Structured Engrain response.
 * @param mixed  $payload Optional payload override.
 * @param bool   $optional Whether failure should be partial.
 * @return void
 */
function rfs_engrain_record_api_response( $post_id, $endpoint, $response, $payload = null, $optional = false ) {
	if ( ! $post_id || '' === (string) $endpoint ) {
		return;
	}

	$api_response = get_post_meta( $post_id, 'api_response', true );
	if ( ! is_array( $api_response ) ) {
		$api_response = array();
	}

	if ( null === $payload ) {
		$payload = array();
	}

	$api_response[ $endpoint ] = array(
		'updated'      => current_time( 'mysql' ),
		'status_code'  => isset( $response['status_code'] ) ? absint( $response['status_code'] ) : 0,
		'reason'       => isset( $response['reason'] ) ? sanitize_key( $response['reason'] ) : '',
		'delete_safe'  => ! empty( $response['delete_safe'] ) ? 'true' : 'false',
		'api_response' => wp_json_encode( rfs_engrain_sanitize_mixed( $payload ) ),
	);

	if ( ! empty( $response['error_message'] ) ) {
		$api_response[ $endpoint ]['error_message'] = sanitize_text_field( $response['error_message'] );
	}

	update_post_meta( $post_id, 'api_response', $api_response );

	if ( ! empty( $response['success'] ) ) {
		rfs_mark_sync_succeeded( $post_id, $endpoint );
	} elseif ( $optional ) {
		rfs_mark_sync_partial( $post_id, $endpoint );
	} else {
		rfs_mark_sync_failed( $post_id, $endpoint );
	}
}

/**
 * Index rows by id.
 *
 * @param array $rows Rows to index.
 * @return array
 */
function rfs_engrain_index_rows_by_id( $rows ) {
	$indexed = array();
	foreach ( (array) $rows as $row ) {
		if ( is_array( $row ) && isset( $row['id'] ) && '' !== (string) $row['id'] ) {
			$indexed[ (string) $row['id'] ] = $row;
		}
	}
	return $indexed;
}

/**
 * Normalize an Engrain date for Rent Fetch date handling.
 *
 * @param mixed $value Date value.
 * @return string
 */
function rfs_engrain_normalize_date( $value ) {
	if ( '' === trim( (string) $value ) ) {
		return '';
	}

	$timestamp = strtotime( (string) $value );

	return false === $timestamp ? '' : gmdate( 'Y-m-d', $timestamp );
}

/**
 * Normalize an availability date and treat past dates as available today.
 *
 * Engrain can retain the original move-in date for a currently available unit.
 * Rent Fetch uses today's date for that case so searches and display agree.
 *
 * @param mixed $value Date value.
 * @return string
 */
function rfs_engrain_normalize_availability_date( $value ) {
	$date = rfs_engrain_normalize_date( $value );
	if ( '' === $date ) {
		return '';
	}

	$today = current_time( 'Y-m-d' );

	return $date < $today ? $today : $date;
}

/**
 * Format an Engrain availability date for Rent Fetch's shared meta conventions.
 *
 * Existing date-search queries use Ymd for floorplans and m/d/Y for units.
 *
 * @param mixed  $value Date value.
 * @param string $post_type Floorplans or units.
 * @return string
 */
function rfs_engrain_format_availability_date_meta( $value, $post_type ) {
	$date = rfs_engrain_normalize_availability_date( $value );
	if ( '' === $date ) {
		return '';
	}

	$timestamp = strtotime( $date );
	if ( false === $timestamp ) {
		return '';
	}

	return 'floorplans' === $post_type ? gmdate( 'Ymd', $timestamp ) : gmdate( 'm/d/Y', $timestamp );
}

/**
 * Flatten the list-pricing-entries response into individual entry rows.
 *
 * SightMap can return either a flat list or one nested list per unit depending
 * on the pricing strategy and flat-pricing behavior.
 *
 * @param array $entries Raw pricing entries.
 * @return array
 */
function rfs_engrain_flatten_pricing_entries( $entries ) {
	$flattened = array();

	foreach ( (array) $entries as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		if ( isset( $entry['unit_id'] ) && '' !== (string) $entry['unit_id'] ) {
			$flattened[] = $entry;
			continue;
		}

		$flattened = array_merge( $flattened, rfs_engrain_flatten_pricing_entries( $entry ) );
	}

	return $flattened;
}

/**
 * Group pricing entries by Engrain unit ID.
 *
 * @param array $entries Raw pricing entries.
 * @return array
 */
function rfs_engrain_group_pricing_entries_by_unit( $entries ) {
	$grouped = array();

	foreach ( rfs_engrain_flatten_pricing_entries( $entries ) as $entry ) {
		$unit_id = (string) $entry['unit_id'];
		if ( ! isset( $grouped[ $unit_id ] ) ) {
			$grouped[ $unit_id ] = array();
		}
		$grouped[ $unit_id ][] = $entry;
	}

	return $grouped;
}

/**
 * Return an authoritative empty pricing context for an unavailable unit.
 *
 * @return array
 */
function rfs_engrain_empty_unit_pricing_context() {
	return array(
		'authoritative'                  => true,
		'all_in_authoritative'           => false,
		'minimum_rent'                   => null,
		'maximum_rent'                   => null,
		'all_in_min_price'               => null,
		'all_in_max_price'               => null,
		'availability_date'              => '',
		'is_available'                   => false,
		'specials'                       => '',
		'apply_online_url'               => '',
		'apply_online_url_authoritative' => false,
	);
}

/**
 * Build normalized pricing contexts keyed by Engrain unit ID.
 *
 * A valid availability date determines whether a unit is available. Pricing is
 * stored when supplied, but a missing or hidden price does not suppress a
 * dated unit. Prices explicitly hidden with show_pricing=false are not exposed
 * through Rent Fetch's standard rent fields.
 *
 * @param array $pricing_units Pricing process unit rows.
 * @param array $pricing_entries Pricing entry rows.
 * @param bool  $pricing_entries_authoritative Whether the entries request completed successfully.
 * @return array
 */
function rfs_engrain_build_unit_pricing_contexts( $pricing_units, $pricing_entries, $pricing_entries_authoritative = true ) {
	$units_by_id   = rfs_engrain_index_rows_by_id( $pricing_units );
	$entries_by_id = rfs_engrain_group_pricing_entries_by_unit( $pricing_entries );
	$unit_ids      = array_values( array_unique( array_merge( array_keys( $units_by_id ), array_keys( $entries_by_id ) ) ) );
	$contexts      = array();

	foreach ( $unit_ids as $unit_id ) {
		$pricing_unit       = $units_by_id[ $unit_id ] ?? array();
		$entries            = $entries_by_id[ $unit_id ] ?? array();
		$context            = rfs_engrain_empty_unit_pricing_context();
		$prices             = array();
		$dates              = array();
		$specials           = '';
		$apply_online_url   = '';

		foreach ( $entries as $entry ) {
			$price = isset( $entry['price'] ) && is_numeric( $entry['price'] ) ? (float) $entry['price'] : 0.0;
			$date  = rfs_engrain_normalize_availability_date( $entry['available_on'] ?? '' );
			if ( '' !== $date ) {
				$dates[] = $date;
			}
			if ( $price > 0 ) {
				if ( ! array_key_exists( 'show_pricing', $entry ) || false !== $entry['show_pricing'] ) {
					$prices[] = $price;
				}
			}
			if (
				'' === $apply_online_url
				&& ( ! array_key_exists( 'show_online_leasing', $entry ) || false !== $entry['show_online_leasing'] )
				&& ! empty( $entry['leasing_fields']['apply_url'] )
			) {
				$apply_online_url = esc_url_raw( (string) $entry['leasing_fields']['apply_url'] );
			}

			if ( '' === $specials && ! empty( $entry['specials_description'] ) ) {
				$specials = sanitize_text_field( (string) $entry['specials_description'] );
			}
		}

		$unit_price = isset( $pricing_unit['price'] ) && is_numeric( $pricing_unit['price'] ) ? (float) $pricing_unit['price'] : 0.0;
		if ( $unit_price > 0 ) {
			if ( ! array_key_exists( 'show_pricing', $pricing_unit ) || false !== $pricing_unit['show_pricing'] ) {
				$prices[] = $unit_price;
			}
		}

		$unit_date = rfs_engrain_normalize_availability_date( $pricing_unit['available_on'] ?? '' );
		if ( '' !== $unit_date ) {
			$dates[] = $unit_date;
		}

		$dates = array_values( array_unique( $dates ) );
		sort( $dates );

		$context['minimum_rent']                   = empty( $prices ) ? null : min( $prices );
		$context['maximum_rent']                   = empty( $prices ) ? null : max( $prices );
		$context['availability_date']              = empty( $dates ) ? '' : $dates[0];
		$context['is_available']                   = ! empty( $dates );
		$context['specials']                       = ! empty( $pricing_unit['specials_description'] )
			? sanitize_text_field( (string) $pricing_unit['specials_description'] )
			: $specials;
		$context['apply_online_url']               = $apply_online_url;
		$context['apply_online_url_authoritative'] = (bool) $pricing_entries_authoritative;
		$contexts[ (string) $unit_id ]             = $context;
	}

	return $contexts;
}

/**
 * Merge SightMap All-In Pricing rows into normalized unit contexts.
 *
 * All-In Pricing is authoritative for base rent, total monthly rent, and
 * availability. Pricing-process contexts contribute enrichment only, including
 * apply URLs and specials. Shared minimum/maximum rent fields remain base rent;
 * all-in totals are stored separately to prevent fee double-counting.
 *
 * @param array|null $contexts Existing process-pricing contexts.
 * @param array      $all_in_rows All-In Pricing rows.
 * @return array
 */
function rfs_engrain_merge_all_in_pricing_contexts( $contexts, $all_in_rows ) {
	$contexts = is_array( $contexts ) ? $contexts : array();

	foreach ( $contexts as &$context ) {
		$context['authoritative']        = true;
		$context['all_in_authoritative'] = true;
		$context['minimum_rent']         = null;
		$context['maximum_rent']         = null;
		$context['all_in_min_price']     = null;
		$context['all_in_max_price']     = null;
		$context['availability_date']    = '';
		$context['is_available']         = false;
	}
	unset( $context );

	foreach ( (array) $all_in_rows as $row ) {
		if ( ! is_array( $row ) || empty( $row['unit_id'] ) ) {
			continue;
		}

		$unit_id      = (string) $row['unit_id'];
		$context      = $contexts[ $unit_id ] ?? rfs_engrain_empty_unit_pricing_context();
		$base_price   = isset( $row['base_price'] ) && is_numeric( $row['base_price'] ) ? (float) $row['base_price'] : null;
		$all_in_min   = isset( $row['all_in_min_price'] ) && is_numeric( $row['all_in_min_price'] ) ? (float) $row['all_in_min_price'] : null;
		$all_in_max   = isset( $row['all_in_max_price'] ) && is_numeric( $row['all_in_max_price'] ) ? (float) $row['all_in_max_price'] : null;
		$available_on = rfs_engrain_normalize_availability_date( $row['available_on'] ?? '' );

		$context['authoritative']        = true;
		$context['all_in_authoritative'] = true;
		$context['minimum_rent']         = null;
		$context['maximum_rent']         = null;
		$context['all_in_min_price']     = null !== $all_in_min && $all_in_min > 0 ? $all_in_min : null;
		$context['all_in_max_price']     = null !== $all_in_max && $all_in_max > 0 ? $all_in_max : null;
		$context['availability_date']    = '';
		$context['is_available']         = false;

		if ( null !== $base_price && $base_price > 0 ) {
			$context['minimum_rent'] = $base_price;
			$context['maximum_rent'] = $base_price;
		}
		if ( '' !== $available_on ) {
			$context['availability_date'] = $available_on;
			$context['is_available']      = true;
		}

		$contexts[ $unit_id ] = $context;
	}

	return $contexts;
}

/**
 * Aggregate unit pricing into Rent Fetch floorplan fields.
 *
 * @param array $floorplan_units Structural units for a floorplan.
 * @param array $pricing_contexts Unit pricing contexts keyed by unit ID.
 * @return array
 */
function rfs_engrain_get_floorplan_pricing_summary( $floorplan_units, $pricing_contexts ) {
	$prices       = array();
	$all_in_mins  = array();
	$all_in_maxes = array();
	$dates        = array();
	$available    = 0;
	$has_specials = false;

	foreach ( (array) $floorplan_units as $unit ) {
		if ( ! is_array( $unit ) || empty( $unit['id'] ) ) {
			continue;
		}

		$context = $pricing_contexts[ (string) $unit['id'] ] ?? rfs_engrain_empty_unit_pricing_context();
		if ( ! empty( $context['is_available'] ) ) {
			++$available;
			if ( ! empty( $context['availability_date'] ) ) {
				$dates[] = (string) $context['availability_date'];
			}
			if ( isset( $context['minimum_rent'] ) && is_numeric( $context['minimum_rent'] ) && (float) $context['minimum_rent'] > 0 ) {
				$prices[] = (float) $context['minimum_rent'];
			}
			if ( isset( $context['maximum_rent'] ) && is_numeric( $context['maximum_rent'] ) && (float) $context['maximum_rent'] > 0 ) {
				$prices[] = (float) $context['maximum_rent'];
			}
			if ( isset( $context['all_in_min_price'] ) && is_numeric( $context['all_in_min_price'] ) && (float) $context['all_in_min_price'] > 0 ) {
				$all_in_mins[] = (float) $context['all_in_min_price'];
			}
			if ( isset( $context['all_in_max_price'] ) && is_numeric( $context['all_in_max_price'] ) && (float) $context['all_in_max_price'] > 0 ) {
				$all_in_maxes[] = (float) $context['all_in_max_price'];
			}
			if ( ! empty( $context['specials'] ) ) {
				$has_specials = true;
			}
		}
	}

	$dates = array_values( array_unique( $dates ) );
	sort( $dates );

	return array(
		'minimum_rent'      => empty( $prices ) ? null : min( $prices ),
		'maximum_rent'      => empty( $prices ) ? null : max( $prices ),
		'all_in_min_price'  => empty( $all_in_mins ) ? null : min( $all_in_mins ),
		'all_in_max_price'  => empty( $all_in_maxes ) ? null : max( $all_in_maxes ),
		'available_units'   => $available,
		'availability_date' => empty( $dates ) ? '' : $dates[0],
		'has_specials'      => $has_specials,
	);
}

/**
 * Convert basic Markdown description content to a shared unit-amenities string.
 *
 * @param mixed $body Unit description body.
 * @return array Amenity labels.
 */
function rfs_engrain_description_body_items( $body ) {
	$items = array();
	$lines = preg_split( '/\r\n|\r|\n/', (string) $body );

	foreach ( (array) $lines as $line ) {
		$line = preg_replace( '/^\s*(?:[-*+]\s+|\d+[.)]\s+|#{1,6}\s*)/', '', (string) $line );
		$line = str_replace( array( '**', '__', '`' ), '', (string) $line );
		$line = sanitize_text_field( wp_strip_all_tags( (string) $line ) );
		if ( '' !== $line ) {
			$items[] = $line;
		}
	}

	return array_values( array_unique( $items ) );
}

/**
 * Return an authoritative empty unit-description context.
 *
 * @return array
 */
function rfs_engrain_empty_unit_description_context() {
	return array(
		'authoritative' => true,
		'amenities'     => '',
		'descriptions'  => array(),
	);
}

/**
 * Build shared unit amenities keyed by Engrain unit ID.
 *
 * @param array $bundle Unit-description bundle.
 * @return array
 */
function rfs_engrain_build_unit_description_contexts( $bundle ) {
	$descriptions_by_id = array();
	foreach ( (array) ( $bundle['descriptions'] ?? array() ) as $group_descriptions ) {
		foreach ( (array) $group_descriptions as $description ) {
			if ( ! is_array( $description ) || empty( $description['id'] ) || ( array_key_exists( 'is_enabled', $description ) && false === $description['is_enabled'] ) ) {
				continue;
			}
			$descriptions_by_id[ (string) $description['id'] ] = $description;
		}
	}

	$contexts = array();
	foreach ( (array) ( $bundle['assignments'] ?? array() ) as $group_assignments ) {
		foreach ( (array) $group_assignments as $assignment ) {
			if ( ! is_array( $assignment ) || empty( $assignment['unit_id'] ) || empty( $assignment['description_id'] ) ) {
				continue;
			}

			$unit_id     = (string) $assignment['unit_id'];
			$description = $descriptions_by_id[ (string) $assignment['description_id'] ] ?? null;
			if ( ! is_array( $description ) ) {
				continue;
			}

			if ( ! isset( $contexts[ $unit_id ] ) ) {
				$contexts[ $unit_id ]                  = rfs_engrain_empty_unit_description_context();
				$contexts[ $unit_id ]['amenity_items'] = array();
			}

			$items = rfs_engrain_description_body_items( $description['body'] ?? '' );
			if ( empty( $items ) ) {
				$fallback = sanitize_text_field( (string) ( $description['name'] ?? $description['label'] ?? '' ) );
				$items    = '' === $fallback ? array() : array( $fallback );
			}

			$contexts[ $unit_id ]['amenity_items']  = array_merge( $contexts[ $unit_id ]['amenity_items'], $items );
			$contexts[ $unit_id ]['descriptions'][] = $description;
		}
	}

	foreach ( $contexts as $unit_id => $context ) {
		$items                             = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $context['amenity_items'] ) ) ) );
		$contexts[ $unit_id ]['amenities'] = implode( ', ', $items );
		unset( $contexts[ $unit_id ]['amenity_items'] );
	}

	return $contexts;
}

/**
 * Store marker descriptions as property amenities without removing manual terms.
 *
 * Only term relationships created by a prior Engrain sync are replaced.
 *
 * @param array $args Sync args.
 * @param array $response Marker-description response.
 * @return void
 */
function rfs_engrain_update_property_marker_descriptions( $args, $response ) {
	$post_id = isset( $args['wordpress_property_post_id'] ) ? absint( $args['wordpress_property_post_id'] ) : 0;
	if ( ! $post_id ) {
		return;
	}

	$term_ids = array();
	if ( ! empty( $response['success'] ) && is_array( $response['data'] ?? null ) ) {
		$previous_term_ids = array_filter( array_map( 'absint', (array) get_post_meta( $post_id, 'synced_engrain_amenity_term_ids', true ) ) );
		if ( ! empty( $previous_term_ids ) && taxonomy_exists( 'amenities' ) ) {
			wp_remove_object_terms( $post_id, $previous_term_ids, 'amenities' );
		}

		if ( taxonomy_exists( 'amenities' ) ) {
			foreach ( $response['data'] as $description ) {
				if ( ! is_array( $description ) ) {
					continue;
				}

				$name = sanitize_text_field( (string) ( $description['label'] ?? $description['name'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}

				$term = term_exists( $name, 'amenities' );
				if ( ! $term ) {
					$term = wp_insert_term( $name, 'amenities' );
				}
				if ( is_wp_error( $term ) ) {
					continue;
				}

				$term_id = is_array( $term ) ? absint( $term['term_id'] ) : absint( $term );
				if ( $term_id ) {
					wp_set_object_terms( $post_id, array( $term_id ), 'amenities', true );
					$term_ids[] = $term_id;
				}
			}
		}

		update_post_meta( $post_id, 'synced_engrain_amenity_term_ids', array_values( array_unique( $term_ids ) ) );
	}
	rfs_engrain_delete_unused_meta( $post_id, array( 'synced_engrain_marker_descriptions' ) );

	rfs_engrain_record_api_response(
		$post_id,
		'engrain_marker_descriptions_api',
		$response,
		array(
			'marker_description_count' => ! empty( $response['success'] ) ? count( (array) $response['data'] ) : null,
			'amenity_term_count'       => ! empty( $response['success'] ) ? count( array_unique( $term_ids ) ) : null,
			'required_permission'      => 'sightmap.marker-descriptions.read',
		),
		true
	);
}

/**
 * Update an Engrain property.
 *
 * @param array $args Sync args.
 * @param array $asset_response Asset API response.
 * @return void
 */
function rfs_engrain_update_property( $args, $asset_response ) {
	$post_id = isset( $args['wordpress_property_post_id'] ) ? absint( $args['wordpress_property_post_id'] ) : 0;
	$asset   = isset( $asset_response['data'] ) && is_array( $asset_response['data'] ) ? $asset_response['data'] : array();

	if ( ! $post_id || empty( $asset_response['success'] ) || empty( $asset['id'] ) ) {
		if ( $post_id ) {
			rfs_engrain_record_api_response( $post_id, 'engrain_asset_api', $asset_response );
		}
		return;
	}

	$title = trim( (string) ( $asset['display_name'] ?? '' ) );
	if ( '' === $title ) {
		$title = trim( (string) ( $asset['name'] ?? $args['property_id'] ) );
	}
	if ( '' !== $title ) {
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $title,
				'post_name'  => sanitize_title( $title ),
			)
		);
	}

	$meta = array(
		'property_id'     => sanitize_text_field( (string) $args['property_id'] ),
		'property_source' => 'engrain',
		'address'         => sanitize_text_field( (string) ( $asset['address_line1'] ?? '' ) ),
		'address_2'       => sanitize_text_field( (string) ( $asset['address_line2'] ?? '' ) ),
		'city'            => sanitize_text_field( (string) ( $asset['address_city'] ?? '' ) ),
		'state'           => sanitize_text_field( (string) ( $asset['address_state'] ?? '' ) ),
		'country'         => sanitize_text_field( (string) ( $asset['address_country'] ?? '' ) ),
		'zipcode'         => sanitize_text_field( (string) ( $asset['address_postal_code'] ?? '' ) ),
		'latitude'        => isset( $asset['address_latitude'] ) ? (float) $asset['address_latitude'] : '',
		'longitude'       => isset( $asset['address_longitude'] ) ? (float) $asset['address_longitude'] : '',
		'updated'         => current_time( 'mysql' ),
	);

	// Engrain descriptions are commonly null. Do not erase a manual description.
	if ( isset( $asset['description'] ) && '' !== trim( (string) $asset['description'] ) ) {
		$meta['description'] = wp_kses_post( (string) $asset['description'] );
	}

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	// UUIDs, tags, market, account ownership, and provider references currently
	// have no Rent Fetch field or template consumer, so they are not persisted.
	rfs_engrain_delete_unused_meta(
		$post_id,
		array(
			'engrain_asset_uuid',
			'engrain_unit_count',
			'engrain_tags',
			'engrain_market',
			'engrain_account_manager_id',
			'engrain_account_owner_id',
			'engrain_asset_references',
			'engrain_reference_values',
		)
	);
	$api_response = get_post_meta( $post_id, 'api_response', true );
	if ( is_array( $api_response ) && isset( $api_response['engrain_references_api'] ) ) {
		unset( $api_response['engrain_references_api'] );
		update_post_meta( $post_id, 'api_response', $api_response );
	}

	rfs_engrain_record_api_response(
		$post_id,
		'engrain_asset_api',
		$asset_response,
		array( 'asset_id' => sanitize_text_field( (string) $asset['id'] ) )
	);
}

/**
 * Store Engrain galleries in Rent Fetch's normalized property-image meta.
 *
 * @param array $args Sync args.
 * @param array $galleries_response Structured gallery list response.
 * @return void
 */
function rfs_engrain_update_property_galleries( $args, $galleries_response ) {
	$post_id = isset( $args['wordpress_property_post_id'] ) ? absint( $args['wordpress_property_post_id'] ) : 0;
	if ( ! $post_id ) {
		return;
	}

	$images = array();
	if ( ! empty( $galleries_response['success'] ) && isset( $galleries_response['data'] ) && is_array( $galleries_response['data'] ) ) {
		foreach ( $galleries_response['data'] as $gallery ) {
			if ( ! is_array( $gallery ) ) {
				continue;
			}
			foreach ( (array) ( $gallery['images'] ?? array() ) as $image ) {
				if ( ! is_array( $image ) || empty( $image['url'] ) ) {
					continue;
				}
				$images[] = array(
					'url'     => esc_url_raw( (string) $image['url'] ),
					'title'   => sanitize_text_field( (string) ( $image['title'] ?? $gallery['name'] ?? '' ) ),
					'alt'     => sanitize_text_field( (string) ( $image['alt'] ?? $image['alt_text'] ?? $gallery['name'] ?? '' ) ),
					'caption' => sanitize_text_field( (string) ( $image['caption'] ?? '' ) ),
				);
			}
		}

		update_post_meta( $post_id, 'synced_property_images', $images );
	}
	rfs_engrain_delete_unused_meta( $post_id, array( 'synced_engrain_galleries' ) );

	rfs_engrain_record_api_response(
		$post_id,
		'engrain_galleries_api',
		$galleries_response,
		array(
			'gallery_count'       => ! empty( $galleries_response['success'] ) ? count( (array) $galleries_response['data'] ) : null,
			'image_count'         => ! empty( $galleries_response['success'] ) ? count( $images ) : null,
			'required_permission' => 'sightmap.galleries.read',
		),
		true
	);
}

/**
 * Get unit rows belonging to a floorplan.
 *
 * @param array  $units Units.
 * @param string $floorplan_id Floorplan ID.
 * @return array
 */
function rfs_engrain_get_units_for_floorplan( $units, $floorplan_id ) {
	return array_values(
		array_filter(
			(array) $units,
			static function ( $unit ) use ( $floorplan_id ) {
				return is_array( $unit ) && isset( $unit['floor_plan_id'] ) && (string) $unit['floor_plan_id'] === (string) $floorplan_id;
			}
		)
	);
}

/**
 * Update an Engrain floorplan.
 *
 * @param array      $args Sync args.
 * @param array      $floorplan Floorplan row.
 * @param array      $units All asset units.
 * @param array|null $pricing_contexts Authoritative pricing contexts, when available.
 * @return void
 */
function rfs_engrain_update_floorplan( $args, $floorplan, $units, $pricing_contexts = null ) {
	$post_id = isset( $args['wordpress_floorplan_post_id'] ) ? absint( $args['wordpress_floorplan_post_id'] ) : 0;
	if ( ! $post_id || empty( $floorplan['id'] ) ) {
		return;
	}

	$title = trim( (string) ( $floorplan['filter_label'] ?? '' ) );
	if ( '' === $title ) {
		$title = trim( (string) ( $floorplan['name'] ?? $floorplan['id'] ) );
	}
	wp_update_post(
		array(
			'ID'         => $post_id,
			'post_title' => $title,
			'post_name'  => sanitize_title( $title ),
		)
	);

	$floorplan_units = rfs_engrain_get_units_for_floorplan( $units, $floorplan['id'] );
	$areas           = array();
	foreach ( $floorplan_units as $unit ) {
		if ( isset( $unit['area'] ) && is_numeric( $unit['area'] ) && (float) $unit['area'] > 0 ) {
			$areas[] = (int) round( (float) $unit['area'] );
		}
	}

	$meta = array(
		'floorplan_id'                  => sanitize_text_field( (string) $floorplan['id'] ),
		'property_id'                   => sanitize_text_field( (string) $args['property_id'] ),
		'floorplan_source'              => 'engrain',
		'beds'                          => isset( $floorplan['bedroom_count'] ) ? (float) $floorplan['bedroom_count'] : 0,
		'baths'                         => isset( $floorplan['bathroom_count'] ) ? (float) $floorplan['bathroom_count'] : 0,
		'floorplan_image_url'           => esc_url_raw( (string) ( $floorplan['image_url'] ?? '' ) ),
		'floorplan_secondary_image_url' => esc_url_raw( (string) ( $floorplan['secondary_image_url'] ?? '' ) ),
		'updated'                       => current_time( 'mysql' ),
	);
	if ( is_array( $units ) ) {
		$meta['minimum_sqft'] = empty( $areas ) ? null : min( $areas );
		$meta['maximum_sqft'] = empty( $areas ) ? null : max( $areas );
	}
	if ( is_array( $pricing_contexts ) ) {
		$pricing_summary                     = rfs_engrain_get_floorplan_pricing_summary( $floorplan_units, $pricing_contexts );
		$meta['minimum_rent']                = $pricing_summary['minimum_rent'];
		$meta['maximum_rent']                = $pricing_summary['maximum_rent'];
		$meta['minimum_total_monthly_price'] = $pricing_summary['all_in_min_price'];
		$meta['maximum_total_monthly_price'] = $pricing_summary['all_in_max_price'];
		$meta['available_units']             = $pricing_summary['available_units'];
		$meta['availability_date']           = rfs_engrain_format_availability_date_meta( $pricing_summary['availability_date'], 'floorplans' );
		$meta['has_specials']                = $pricing_summary['has_specials'];
	}

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	rfs_engrain_delete_unused_meta(
		$post_id,
		array(
			'engrain_provider_id',
			'engrain_provider_group_id',
			'engrain_show_bedroom_count',
			'engrain_show_bathroom_count',
			'engrain_show_unit_area',
			'engrain_unit_count',
			'engrain_pricing_process_id',
			'engrain_pricing_authoritative',
		)
	);

	$response = array(
		'success'     => true,
		'delete_safe' => false,
		'data'        => $floorplan,
		'reason'      => 'valid_floorplan',
		'status_code' => 200,
	);
	rfs_engrain_record_api_response(
		$post_id,
		'engrain_floorplans_api',
		$response,
		array( 'floorplan_id' => sanitize_text_field( (string) $floorplan['id'] ) )
	);
}

/**
 * Update an Engrain unit.
 *
 * @param array      $args Sync args.
 * @param array      $unit Unit row.
 * @param array      $floorplan Related floorplan row.
 * @param array|null $pricing_context Authoritative pricing context, when available.
 * @param array|null $description_context Authoritative unit-description context, when available.
 * @return void
 */
function rfs_engrain_update_unit( $args, $unit, $floorplan, $pricing_context = null, $description_context = null ) {
	$post_id = isset( $args['wordpress_unit_post_id'] ) ? absint( $args['wordpress_unit_post_id'] ) : 0;
	if ( ! $post_id || empty( $unit['id'] ) ) {
		return;
	}

	$title = trim( (string) ( $unit['label'] ?? '' ) );
	if ( '' === $title ) {
		$title = trim( (string) ( $unit['unit_number'] ?? $unit['id'] ) );
	}
	wp_update_post(
		array(
			'ID'         => $post_id,
			'post_title' => $title,
			'post_name'  => sanitize_title( $title ),
		)
	);

	$area = isset( $unit['area'] ) && is_numeric( $unit['area'] ) ? (int) round( (float) $unit['area'] ) : 0;
	$meta = array(
		'unit_id'      => sanitize_text_field( (string) $unit['id'] ),
		'floorplan_id' => sanitize_text_field( (string) $unit['floor_plan_id'] ),
		'property_id'  => sanitize_text_field( (string) $args['property_id'] ),
		'unit_source'  => 'engrain',
		'unit_number'  => sanitize_text_field( (string) ( $unit['unit_number'] ?? '' ) ),
		'sqrft'        => $area > 0 ? $area : null,
		'beds'         => isset( $floorplan['bedroom_count'] ) ? (float) $floorplan['bedroom_count'] : 0,
		'baths'        => isset( $floorplan['bathroom_count'] ) ? (float) $floorplan['bathroom_count'] : 0,
		'updated'      => current_time( 'mysql' ),
	);
	if ( is_array( $pricing_context ) && ! empty( $pricing_context['authoritative'] ) ) {
		$meta['minimum_rent']                = $pricing_context['minimum_rent'] ?? null;
		$meta['maximum_rent']                = $pricing_context['maximum_rent'] ?? null;
		$meta['minimum_total_monthly_price'] = $pricing_context['all_in_min_price'] ?? null;
		$meta['maximum_total_monthly_price'] = $pricing_context['all_in_max_price'] ?? null;
		$meta['availability_date']           = rfs_engrain_format_availability_date_meta( $pricing_context['availability_date'] ?? '', 'units' );
		$meta['specials']                    = sanitize_text_field( (string) ( $pricing_context['specials'] ?? '' ) );
	}
	if (
		is_array( $pricing_context )
		&& ! empty( $pricing_context['apply_online_url_authoritative'] )
	) {
		$meta['apply_online_url'] = esc_url_raw( (string) ( $pricing_context['apply_online_url'] ?? '' ) );
	}
	if ( is_array( $description_context ) && ! empty( $description_context['authoritative'] ) ) {
		$meta['amenities'] = sanitize_text_field( (string) ( $description_context['amenities'] ?? '' ) );
	}

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	rfs_engrain_delete_unused_meta(
		$post_id,
		array(
			'unit_image_urls',
			'engrain_building_id',
			'engrain_floor_id',
			'engrain_map_id',
			'engrain_provider_group_id',
			'engrain_is_affordable_housing_unit',
			'engrain_unit_references',
			'engrain_reference_values',
			'engrain_yardi_rentcafe_unit_id',
			'engrain_pricing_process_id',
			'engrain_sightmap_id',
			'engrain_is_available',
			'engrain_show_pricing',
			'engrain_show_online_leasing',
			'engrain_pricing_status',
			'engrain_full_price',
			'engrain_pricing_unit',
			'engrain_pricing_entries',
			'engrain_all_in_pricing',
			'engrain_view_image_url',
			'engrain_secondary_view_image_url',
			'synced_engrain_unit_descriptions',
			'engrain_address_line1',
			'engrain_address_line2',
			'engrain_address_city',
			'engrain_address_state',
			'engrain_address_country',
			'engrain_address_postal_code',
		)
	);

	// Building/map IDs, address fragments, affordable flags, raw pricing rows,
	// and unit image URLs have no current Rent Fetch field or template consumer.
	$response = array(
		'success'     => true,
		'delete_safe' => false,
		'data'        => $unit,
		'reason'      => 'valid_unit',
		'status_code' => 200,
	);
	rfs_engrain_record_api_response(
		$post_id,
		'engrain_units_api',
		$response,
		array( 'unit_id' => sanitize_text_field( (string) $unit['id'] ) )
	);
}

/**
 * Show only available Engrain units in Rent Fetch unit lists.
 *
 * The structural units endpoint contains every apartment, including occupied
 * inventory. Pricing rows are the authoritative availability source.
 *
 * @param array $query_args Unit query arguments.
 * @return array
 */
function rfs_engrain_filter_floorplan_unit_display_args( $query_args ) {
	$floorplan_post_id = get_the_ID();
	if ( ! $floorplan_post_id || 'floorplans' !== get_post_type( $floorplan_post_id ) ) {
		return $query_args;
	}

	if ( 'engrain' !== get_post_meta( $floorplan_post_id, 'floorplan_source', true ) ) {
		return $query_args;
	}

	if ( ! isset( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) {
		$query_args['meta_query'] = array();
	}
	$query_args['meta_query'][] = array(
		'key'     => 'availability_date',
		'value'   => '',
		'compare' => '!=',
	);

	return $query_args;
}
add_filter( 'rentfetch_floorplan_unit_display_args', 'rfs_engrain_filter_floorplan_unit_display_args', 20 );

/**
 * Delete source records missing from an authoritative Engrain list.
 *
 * @param string $post_type Post type.
 * @param string $source_key Source meta key.
 * @param string $id_key ID meta key.
 * @param string $property_id Engrain asset ID.
 * @param array  $current_ids IDs in the authoritative response.
 * @return void
 */
function rfs_engrain_delete_orphan_records( $post_type, $source_key, $id_key, $property_id, $current_ids ) {
	$meta_query = array(
		'relation' => 'AND',
		array(
			'key'     => $source_key,
			'value'   => 'engrain',
			'compare' => '=',
		),
		array(
			'key'     => 'property_id',
			'value'   => (string) $property_id,
			'compare' => '=',
		),
	);

	$current_ids = array_values( array_unique( array_filter( array_map( 'strval', (array) $current_ids ) ) ) );
	if ( ! empty( $current_ids ) ) {
		$meta_query[] = array(
			'key'     => $id_key,
			'value'   => $current_ids,
			'compare' => 'NOT IN',
		);
	}

	$post_ids = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => $meta_query,
		)
	);

	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}
}

/**
 * Delete Engrain content whose asset was removed from settings.
 *
 * @param array $configured_asset_ids Authoritative configured asset IDs.
 * @return void
 */
function rfs_engrain_delete_unconfigured_assets( $configured_asset_ids ) {
	if ( ! is_array( $configured_asset_ids ) ) {
		return;
	}

	$configured_asset_ids = array_values( array_unique( array_filter( array_map( 'strval', $configured_asset_ids ) ) ) );
	$types                = array(
		'properties' => 'property_source',
		'floorplans' => 'floorplan_source',
		'units'      => 'unit_source',
	);

	foreach ( $types as $post_type => $source_key ) {
		$meta_query = array(
			array(
				'key'     => $source_key,
				'value'   => 'engrain',
				'compare' => '=',
			),
		);

		if ( ! empty( $configured_asset_ids ) ) {
			$meta_query[] = array(
				'key'     => 'property_id',
				'value'   => $configured_asset_ids,
				'compare' => 'NOT IN',
			);
		}

		$post_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
			)
		);

		foreach ( $post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}
}
add_action( 'rfs_engrain_do_delete_orphans', 'rfs_engrain_delete_unconfigured_assets', 10, 1 );
