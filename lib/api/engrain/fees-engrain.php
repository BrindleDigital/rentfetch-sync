<?php
/**
 * Engrain expense storage and provider-neutral fee normalization.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format an Engrain amount for fee-table display.
 *
 * @param mixed $amount Amount.
 * @return string
 */
function rfs_engrain_format_fee_amount( $amount ) {
	if ( ! is_numeric( $amount ) ) {
		return '';
	}

	$formatted = number_format( (float) $amount, 2, '.', '' );
	$formatted = preg_replace( '/\.00$/', '', $formatted );

	return '$' . $formatted;
}

/**
 * Normalize an Engrain frequency to a display label.
 *
 * @param mixed $frequency Frequency value.
 * @return string
 */
function rfs_engrain_get_fee_frequency_label( $frequency ) {
	$frequency = strtolower( trim( (string) $frequency ) );
	$map       = array(
		'monthly'   => 'Monthly',
		'one_time'  => 'One-Time',
		'one-time'  => 'One-Time',
		'annually'  => 'Annually',
		'annual'    => 'Annually',
		'weekly'    => 'Weekly',
		'daily'     => 'Daily',
		'quarterly' => 'Quarterly',
		'per_lease' => 'Per Lease',
		'per_stay'  => 'Per Stay',
	);

	if ( isset( $map[ $frequency ] ) ) {
		return $map[ $frequency ];
	}

	return '' === $frequency ? '' : ucwords( str_replace( '_', ' ', $frequency ) );
}

/**
 * Get an expense price display, optionally using scoped entries.
 *
 * @param array $expense Expense definition.
 * @param array $entries Expense entries.
 * @return string
 */
function rfs_engrain_get_fee_price_display( $expense, $entries = array() ) {
	$entry_amounts = array();
	foreach ( (array) $entries as $entry ) {
		if ( is_array( $entry ) && isset( $entry['amount'] ) && is_numeric( $entry['amount'] ) ) {
			$entry_amounts[] = (float) $entry['amount'];
		}
	}

	if ( ! empty( $entry_amounts ) ) {
		$minimum = min( $entry_amounts );
		$maximum = max( $entry_amounts );

		return $maximum > $minimum
			? rfs_engrain_format_fee_amount( $minimum ) . '-' . rfs_engrain_format_fee_amount( $maximum )
			: rfs_engrain_format_fee_amount( $minimum );
	}

	$amount     = $expense['amount'] ?? null;
	$min_amount = $expense['min_amount'] ?? null;
	$max_amount = $expense['max_amount'] ?? null;

	if ( is_numeric( $min_amount ) && is_numeric( $max_amount ) && (float) $max_amount > (float) $min_amount ) {
		return rfs_engrain_format_fee_amount( $min_amount ) . '-' . rfs_engrain_format_fee_amount( $max_amount );
	}

	if ( is_numeric( $amount ) ) {
		return rfs_engrain_format_fee_amount( $amount );
	}

	if ( is_numeric( $min_amount ) ) {
		return rfs_engrain_format_fee_amount( $min_amount );
	}

	if ( is_numeric( $max_amount ) ) {
		return rfs_engrain_format_fee_amount( $max_amount );
	}

	return sanitize_text_field( (string) ( $expense['text_amount'] ?? '' ) );
}

/**
 * Build a category compatible with Rent Fetch's existing fee tables.
 *
 * @param array  $expense Expense definition.
 * @param string $frequency_label Display frequency.
 * @return string
 */
function rfs_engrain_get_fee_category( $expense, $frequency_label ) {
	$required = ! empty( $expense['is_required'] );
	$monthly  = 'Monthly' === $frequency_label;

	if ( $required && $monthly ) {
		return 'Required Monthly Fees';
	}
	if ( $required ) {
		return 'Required One-Time Fees';
	}
	if ( $monthly ) {
		return 'Optional Monthly Fees';
	}

	return 'Optional One-Time Fees';
}

/**
 * Build readable notes for an Engrain expense.
 *
 * @param array  $expense Expense definition.
 * @param string $scope_note Optional scope description.
 * @return string
 */
function rfs_engrain_get_fee_notes( $expense, $scope_note = '' ) {
	$notes   = array( ! empty( $expense['is_required'] ) ? 'required' : 'optional' );
	$markers = array(
		'is_situational'   => 'situational',
		'is_included'      => 'included',
		'is_refundable'    => 'refundable',
		'is_third_party'   => 'third-party',
		'is_per_applicant' => 'per applicant',
	);

	foreach ( $markers as $key => $label ) {
		if ( ! empty( $expense[ $key ] ) ) {
			$notes[] = $label;
		}
	}

	if ( '' !== trim( $scope_note ) ) {
		$notes[] = trim( $scope_note );
	}

	return implode( '; ', array_unique( $notes ) );
}

/**
 * Normalize one expense to the provider-neutral flat row shape.
 *
 * @param array  $expense Expense definition.
 * @param array  $entries Scoped entries.
 * @param string $scope_note Scope description.
 * @return array|null
 */
function rfs_engrain_normalize_expense_row( $expense, $entries = array(), $scope_note = '' ) {
	if ( ! is_array( $expense ) || ( array_key_exists( 'is_enabled', $expense ) && ! $expense['is_enabled'] ) ) {
		return null;
	}

	$description = sanitize_text_field( (string) ( $expense['label'] ?? 'Fee' ) );
	$frequency   = rfs_engrain_get_fee_frequency_label( $expense['frequency'] ?? '' );
	$longnotes   = array();

	foreach ( array( 'tooltip_label', 'disclaimer' ) as $key ) {
		if ( ! empty( $expense[ $key ] ) ) {
			$longnotes[] = wp_kses_post( (string) $expense[ $key ] );
		}
	}
	if ( '' !== trim( $scope_note ) ) {
		$longnotes[] = sanitize_text_field( ucfirst( $scope_note ) ) . '.';
	}

	return array(
		'description' => $description,
		'price'       => rfs_engrain_get_fee_price_display( $expense, $entries ),
		'frequency'   => $frequency,
		'notes'       => rfs_engrain_get_fee_notes( $expense, $scope_note ),
		'category'    => rfs_engrain_get_fee_category( $expense, $frequency ),
		'longnotes'   => implode( "\n\n", array_unique( $longnotes ) ),
	);
}

/**
 * Get labels for scoped expense entries.
 *
 * @param array  $entries Entries.
 * @param string $entry_id_key Entry relationship key.
 * @param array  $labels Labels indexed by Engrain ID.
 * @return array
 */
function rfs_engrain_get_expense_entry_labels( $entries, $entry_id_key, $labels ) {
	$found = array();
	foreach ( (array) $entries as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry[ $entry_id_key ] ) ) {
			continue;
		}
		$id      = (string) $entry[ $entry_id_key ];
		$found[] = isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}
	$found = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $found ) ) ) );
	sort( $found );
	return $found;
}

/**
 * Sort provider-neutral fee rows in their shared display order.
 *
 * @param array $rows Fee rows.
 * @return array
 */
function rfs_engrain_sort_fee_rows( $rows ) {
	$category_order = array(
		'Required Monthly Fees'  => 0,
		'Required One-Time Fees' => 1,
		'Optional Monthly Fees'  => 2,
		'Optional One-Time Fees' => 3,
	);
	usort(
		$rows,
		static function ( $left, $right ) use ( $category_order ) {
			$left_order  = $category_order[ $left['category'] ?? '' ] ?? 999;
			$right_order = $category_order[ $right['category'] ?? '' ] ?? 999;
			return $left_order === $right_order
				? strcasecmp( (string) $left['description'], (string) $right['description'] )
				: $left_order <=> $right_order;
		}
	);

	return $rows;
}

/**
 * Normalize the expense rows assigned to one floorplan or unit.
 *
 * @param array $scoped_expenses Joined expense/entry rows.
 * @return array
 */
function rfs_engrain_normalize_scoped_expense_rows( $scoped_expenses ) {
	$rows = array();

	foreach ( (array) $scoped_expenses as $item ) {
		$expense = is_array( $item ) && isset( $item['expense'] ) && is_array( $item['expense'] ) ? $item['expense'] : array();
		$entry   = is_array( $item ) && isset( $item['entry'] ) && is_array( $item['entry'] ) ? $item['entry'] : array();
		$row     = rfs_engrain_normalize_expense_row( $expense, array( $entry ) );
		if ( $row ) {
			$rows[] = $row;
		}
	}

	return rfs_engrain_sort_fee_rows( $rows );
}

/**
 * Build normalized fee rows from the complete Engrain expense bundle.
 *
 * @param array $bundle Expense data bundle.
 * @param array $floorplans Floorplans indexed by ID.
 * @param array $units Units indexed by ID.
 * @return array
 */
function rfs_engrain_normalize_expense_bundle( $bundle, $floorplans, $units ) {
	$rows             = array();
	$floorplan_labels = array();
	$unit_labels      = array();

	foreach ( $floorplans as $id => $floorplan ) {
		$floorplan_labels[ (string) $id ] = sanitize_text_field( (string) ( $floorplan['filter_label'] ?? $floorplan['name'] ?? $id ) );
	}
	foreach ( $units as $id => $unit ) {
		$unit_labels[ (string) $id ] = sanitize_text_field( (string) ( $unit['label'] ?? $unit['unit_number'] ?? $id ) );
	}

	foreach ( (array) ( $bundle['property_expenses'] ?? array() ) as $expense ) {
		$row = rfs_engrain_normalize_expense_row( $expense );
		if ( $row ) {
			$rows[] = $row;
		}
	}

	foreach ( (array) ( $bundle['floorplan_expenses'] ?? array() ) as $expense ) {
		if ( empty( $expense['id'] ) ) {
			continue;
		}
		$entries = $bundle['floorplan_entries'][ (string) $expense['id'] ] ?? array();
		$labels  = rfs_engrain_get_expense_entry_labels( $entries, 'floor_plan_id', $floorplan_labels );
		$scope   = empty( $labels ) ? 'floorplan-specific' : 'applies to floorplans: ' . implode( ', ', $labels );
		$row     = rfs_engrain_normalize_expense_row( $expense, $entries, $scope );
		if ( $row ) {
			$rows[] = $row;
		}
	}

	foreach ( (array) ( $bundle['unit_expenses'] ?? array() ) as $expense ) {
		if ( empty( $expense['id'] ) ) {
			continue;
		}
		$entries = $bundle['unit_entries'][ (string) $expense['id'] ] ?? array();
		$labels  = rfs_engrain_get_expense_entry_labels( $entries, 'unit_id', $unit_labels );
		$scope   = empty( $labels ) ? 'unit-specific' : sprintf( 'applies to %d unit%s', count( $labels ), 1 === count( $labels ) ? '' : 's' );
		$row     = rfs_engrain_normalize_expense_row( $expense, $entries, $scope );
		if ( $row ) {
			$rows[] = $row;
		}
	}

	$disclaimer_parts = array();
	foreach ( (array) ( $bundle['pricing_disclaimers'] ?? array() ) as $disclaimer ) {
		if ( ! is_array( $disclaimer ) ) {
			continue;
		}
		$label = sanitize_text_field( (string) ( $disclaimer['label'] ?? $disclaimer['name'] ?? '' ) );
		$text  = trim( (string) ( $disclaimer['long_text'] ?? $disclaimer['short_text'] ?? $disclaimer['subtext'] ?? '' ) );
		if ( '' !== $text ) {
			$disclaimer_parts[] = ( '' !== $label ? $label . ': ' : '' ) . wp_kses_post( $text );
		}
	}
	if ( ! empty( $disclaimer_parts ) ) {
		$disclaimer_text = implode( "\n\n", array_unique( $disclaimer_parts ) );
		foreach ( $rows as &$row ) {
			$row['longnotes'] = trim( (string) $row['longnotes'] . "\n\n" . $disclaimer_text );
		}
		unset( $row );
	}

	return rfs_engrain_sort_fee_rows( $rows );
}

/**
 * Calculate the fixed property-wide mandatory monthly Engrain expense total.
 *
 * @param array $property_expenses Property-level expenses.
 * @return array
 */
function rfs_engrain_get_monthly_required_fee_summary( $property_expenses ) {
	$total        = 0.0;
	$contributors = array();

	foreach ( (array) $property_expenses as $expense ) {
		if (
			! is_array( $expense )
			|| empty( $expense['is_required'] )
			|| ( array_key_exists( 'is_enabled', $expense ) && ! $expense['is_enabled'] )
			|| ! empty( $expense['is_situational'] )
			|| ! empty( $expense['is_included'] )
			|| 'Monthly' !== rfs_engrain_get_fee_frequency_label( $expense['frequency'] ?? '' )
			|| ! isset( $expense['amount'] )
			|| ! is_numeric( $expense['amount'] )
		) {
			continue;
		}

		$amount = (float) $expense['amount'];
		if ( $amount <= 0 ) {
			continue;
		}

		$total         += $amount;
		$contributors[] = array(
			'description'   => sanitize_text_field( (string) ( $expense['label'] ?? 'Fee' ) ),
			'applied_price' => $amount,
		);
	}

	return array(
		'total'        => round( $total, 2 ),
		'contributors' => $contributors,
	);
}

/**
 * Calculate fixed required monthly fees already joined to a floorplan or unit.
 *
 * @param array $scoped_expenses Joined expense/entry rows.
 * @return array
 */
function rfs_engrain_get_scoped_monthly_required_fee_summary( $scoped_expenses ) {
	$total        = 0.0;
	$contributors = array();

	foreach ( (array) $scoped_expenses as $item ) {
		$expense = is_array( $item ) && isset( $item['expense'] ) && is_array( $item['expense'] ) ? $item['expense'] : array();
		$entry   = is_array( $item ) && isset( $item['entry'] ) && is_array( $item['entry'] ) ? $item['entry'] : array();
		if (
			empty( $expense['is_required'] )
			|| ( array_key_exists( 'is_enabled', $expense ) && ! $expense['is_enabled'] )
			|| ! empty( $expense['is_situational'] )
			|| ! empty( $expense['is_included'] )
			|| 'Monthly' !== rfs_engrain_get_fee_frequency_label( $expense['frequency'] ?? '' )
			|| ! isset( $entry['amount'] )
			|| ! is_numeric( $entry['amount'] )
		) {
			continue;
		}

		$amount = (float) $entry['amount'];
		if ( $amount <= 0 ) {
			continue;
		}

		$total         += $amount;
		$contributors[] = array(
			'description'   => sanitize_text_field( (string) ( $expense['label'] ?? 'Fee' ) ),
			'applied_price' => $amount,
		);
	}

	return array(
		'total'        => round( $total, 2 ),
		'contributors' => $contributors,
	);
}

/**
 * Get a numeric security-deposit range from an expense and optional entries.
 *
 * @param array $expense Expense definition.
 * @param array $entries Scoped entries.
 * @return array|null
 */
function rfs_engrain_get_deposit_range( $expense, $entries = array() ) {
	if (
		! is_array( $expense )
		|| 'deposit' !== strtolower( trim( (string) ( $expense['type'] ?? '' ) ) )
		|| ( array_key_exists( 'is_enabled', $expense ) && ! $expense['is_enabled'] )
	) {
		return null;
	}

	$amounts = array();
	foreach ( (array) $entries as $entry ) {
		foreach ( array( 'amount', 'min_amount', 'max_amount' ) as $key ) {
			if ( isset( $entry[ $key ] ) && is_numeric( $entry[ $key ] ) && (float) $entry[ $key ] >= 0 ) {
				$amounts[] = (float) $entry[ $key ];
			}
		}
	}

	if ( empty( $amounts ) ) {
		foreach ( array( 'amount', 'min_amount', 'max_amount' ) as $key ) {
			if ( isset( $expense[ $key ] ) && is_numeric( $expense[ $key ] ) && (float) $expense[ $key ] >= 0 ) {
				$amounts[] = (float) $expense[ $key ];
			}
		}
	}

	if ( empty( $amounts ) ) {
		return null;
	}

	return array(
		'minimum' => min( $amounts ),
		'maximum' => max( $amounts ),
	);
}

/**
 * Aggregate deposit ranges from expense definitions.
 *
 * @param array $definitions Expense definitions.
 * @return array|null
 */
function rfs_engrain_get_deposit_range_from_definitions( $definitions ) {
	$minimums = array();
	$maximums = array();
	foreach ( (array) $definitions as $definition ) {
		$range = rfs_engrain_get_deposit_range( $definition );
		if ( $range ) {
			$minimums[] = $range['minimum'];
			$maximums[] = $range['maximum'];
		}
	}

	return empty( $minimums )
		? null
		: array(
			'minimum' => min( $minimums ),
			'maximum' => max( $maximums ),
		);
}

/**
 * Build security-deposit ranges keyed by a scoped Engrain object ID.
 *
 * @param array  $definitions Expense definitions.
 * @param array  $entries_by_expense Entries keyed by expense ID.
 * @param string $entry_id_key Relationship key.
 * @return array
 */
function rfs_engrain_get_scoped_deposit_ranges( $definitions, $entries_by_expense, $entry_id_key ) {
	$definitions = rfs_engrain_index_rows_by_id( $definitions );
	$ranges      = array();

	foreach ( (array) $entries_by_expense as $expense_id => $entries ) {
		$expense = $definitions[ (string) $expense_id ] ?? null;
		if ( ! is_array( $expense ) ) {
			continue;
		}

		foreach ( (array) $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[ $entry_id_key ] ) ) {
				continue;
			}
			$range = rfs_engrain_get_deposit_range( $expense, array( $entry ) );
			if ( ! $range ) {
				continue;
			}

			$object_id = (string) $entry[ $entry_id_key ];
			if ( ! isset( $ranges[ $object_id ] ) ) {
				$ranges[ $object_id ] = $range;
			} else {
				$ranges[ $object_id ]['minimum'] = min( $ranges[ $object_id ]['minimum'], $range['minimum'] );
				$ranges[ $object_id ]['maximum'] = max( $ranges[ $object_id ]['maximum'], $range['maximum'] );
			}
		}
	}

	return $ranges;
}

/**
 * Store Engrain security deposits in Rent Fetch's shared deposit keys.
 *
 * @param array $args Sync args.
 * @param array $bundle Complete expense bundle.
 * @return void
 */
function rfs_engrain_update_shared_deposit_meta( $args, $bundle ) {
	$property_range   = rfs_engrain_get_deposit_range_from_definitions( $bundle['property_expenses'] ?? array() );
	$floorplan_ranges = rfs_engrain_get_scoped_deposit_ranges(
		$bundle['floorplan_expenses'] ?? array(),
		$bundle['floorplan_entries'] ?? array(),
		'floor_plan_id'
	);
	$unit_ranges      = rfs_engrain_get_scoped_deposit_ranges(
		$bundle['unit_expenses'] ?? array(),
		$bundle['unit_entries'] ?? array(),
		'unit_id'
	);

	foreach ( array(
		'floorplans' => 'floorplan',
		'units'      => 'unit',
	) as $post_type => $prefix ) {
		$post_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => $prefix . '_source',
						'value'   => 'engrain',
						'compare' => '=',
					),
					array(
						'key'     => 'property_id',
						'value'   => (string) $args['property_id'],
						'compare' => '=',
					),
				),
			)
		);

		foreach ( $post_ids as $post_id ) {
			$engrain_id = (string) get_post_meta( $post_id, $prefix . '_id', true );
			$range      = 'floorplans' === $post_type
				? ( $floorplan_ranges[ $engrain_id ] ?? $property_range )
				: ( $unit_ranges[ $engrain_id ] ?? $property_range );

			if ( 'floorplans' === $post_type ) {
				if ( $range ) {
					update_post_meta( $post_id, 'minimum_deposit', $range['minimum'] );
					update_post_meta( $post_id, 'maximum_deposit', $range['maximum'] );
				} else {
					delete_post_meta( $post_id, 'minimum_deposit' );
					delete_post_meta( $post_id, 'maximum_deposit' );
				}
			} elseif ( $range ) {
				update_post_meta( $post_id, 'deposit', $range['minimum'] );
			} else {
				delete_post_meta( $post_id, 'deposit' );
			}
		}
	}
}

/**
 * Store the applicable scoped expense definitions and entry values per record.
 *
 * @param array  $args Sync args.
 * @param string $post_type Floorplans or units.
 * @param string $source_key Source meta key.
 * @param string $id_meta_key Engrain ID meta key.
 * @param string $entry_id_key Entry relationship key.
 * @param array  $definitions Expense definitions.
 * @param array  $entries_by_expense Entries keyed by expense ID.
 * @return void
 */
function rfs_engrain_store_scoped_expenses( $args, $post_type, $source_key, $id_meta_key, $entry_id_key, $definitions, $entries_by_expense ) {
	$definition_index = rfs_engrain_index_rows_by_id( $definitions );
	$by_object_id     = array();

	foreach ( (array) $entries_by_expense as $expense_id => $entries ) {
		if ( ! isset( $definition_index[ (string) $expense_id ] ) ) {
			continue;
		}

		foreach ( (array) $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[ $entry_id_key ] ) ) {
				continue;
			}

			$object_id                    = (string) $entry[ $entry_id_key ];
			$by_object_id[ $object_id ][] = array(
				'expense' => $definition_index[ (string) $expense_id ],
				'entry'   => $entry,
			);
		}
	}

	$post_ids = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => $source_key,
					'value'   => 'engrain',
					'compare' => '=',
				),
				array(
					'key'     => 'property_id',
					'value'   => (string) $args['property_id'],
					'compare' => '=',
				),
			),
		)
	);

	foreach ( $post_ids as $post_id ) {
		$engrain_id = (string) get_post_meta( $post_id, $id_meta_key, true );
		$expenses   = $by_object_id[ $engrain_id ] ?? array();
		rfs_engrain_delete_unused_meta( $post_id, array( 'synced_engrain_expenses' ) );
		update_post_meta( $post_id, 'synced_scoped_fee_source', 'engrain' );
		update_post_meta( $post_id, 'synced_scoped_fee_rows', rfs_engrain_normalize_scoped_expense_rows( $expenses ) );
		update_post_meta( $post_id, 'synced_scoped_fee_monthly_summary', rfs_engrain_get_scoped_monthly_required_fee_summary( $expenses ) );
	}
}

/**
 * Store Engrain expenses and provider-neutral normalized fee data.
 *
 * @param array $args Sync args.
 * @param array $responses Structured endpoint responses.
 * @param array $floorplans Floorplans indexed by ID.
 * @param array $units Units indexed by ID.
 * @return bool Whether the complete expense bundle was updated.
 */
function rfs_engrain_update_expenses( $args, $responses, $floorplans, $units ) {
	$post_id = isset( $args['wordpress_property_post_id'] ) ? absint( $args['wordpress_property_post_id'] ) : 0;
	if ( ! $post_id ) {
		return false;
	}
	rfs_engrain_delete_unused_meta(
		$post_id,
		array(
			'synced_engrain_pricing_disclaimers',
			'synced_engrain_property_expenses',
			'synced_engrain_floorplan_expenses',
			'synced_engrain_floorplan_expense_entries',
			'synced_engrain_unit_expenses',
			'synced_engrain_unit_expense_entries',
		)
	);

	$required_keys = array( 'property_expenses', 'floorplan_expenses', 'floorplan_entries', 'unit_expenses', 'unit_entries' );
	$success       = true;
	$bundle        = array();

	foreach ( $required_keys as $key ) {
		$response = $responses[ $key ] ?? array();
		if ( empty( $response['success'] ) ) {
			$success = false;
			continue;
		}
		$bundle[ $key ] = $response['data'];
	}

	$disclaimer_response = $responses['pricing_disclaimers'] ?? array();
	if ( ! empty( $disclaimer_response['success'] ) ) {
		$bundle['pricing_disclaimers'] = $disclaimer_response['data'];
	} else {
		$bundle['pricing_disclaimers'] = array();
	}
	rfs_engrain_record_api_response( $post_id, 'engrain_pricing_disclaimers_api', $disclaimer_response, null, true );

	$combined_response = array(
		'success'       => $success,
		'delete_safe'   => $success,
		'data'          => $bundle,
		'reason'        => $success ? 'valid_expense_bundle' : 'incomplete_expense_bundle',
		'status_code'   => $success ? 200 : 0,
		'error_message' => '',
	);
	$expense_summary   = array();
	foreach ( $required_keys as $key ) {
		$expense_summary[ $key . '_count' ] = isset( $bundle[ $key ] ) && is_array( $bundle[ $key ] ) ? count( $bundle[ $key ] ) : null;
	}
	rfs_engrain_record_api_response( $post_id, 'engrain_expenses_api', $combined_response, $expense_summary, true );

	if ( ! $success ) {
		return false;
	}

	rfs_engrain_store_scoped_expenses(
		$args,
		'floorplans',
		'floorplan_source',
		'floorplan_id',
		'floor_plan_id',
		$bundle['floorplan_expenses'],
		$bundle['floorplan_entries']
	);
	rfs_engrain_store_scoped_expenses(
		$args,
		'units',
		'unit_source',
		'unit_id',
		'unit_id',
		$bundle['unit_expenses'],
		$bundle['unit_entries']
	);
	rfs_engrain_update_shared_deposit_meta( $args, $bundle );

	$rows    = rfs_engrain_normalize_expense_bundle( $bundle, $floorplans, $units );
	$summary = rfs_engrain_get_monthly_required_fee_summary( $bundle['property_expenses'] );

	if ( empty( $rows ) ) {
		if ( 'engrain' === get_post_meta( $post_id, 'synced_property_fee_source', true ) ) {
			delete_post_meta( $post_id, 'synced_property_fee_source' );
			delete_post_meta( $post_id, 'synced_property_fee_rows' );
			delete_post_meta( $post_id, 'synced_property_fee_monthly_summary' );
		}
	} else {
		update_post_meta( $post_id, 'synced_property_fee_source', 'engrain' );
		update_post_meta( $post_id, 'synced_property_fee_rows', $rows );
		update_post_meta( $post_id, 'synced_property_fee_monthly_summary', $summary );
	}

	return true;
}
