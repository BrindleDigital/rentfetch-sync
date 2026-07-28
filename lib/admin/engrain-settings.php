<?php
/**
 * Engrain settings helpers and persistence.
 *
 * @package rentfetch-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse a comma/whitespace-delimited Engrain asset ID list.
 *
 * @param mixed $value Raw option value.
 * @return array
 */
function rfs_engrain_parse_asset_ids( $value ) {
	$parts = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
	$ids   = array();

	foreach ( (array) $parts as $part ) {
		$id = sanitize_text_field( trim( (string) $part ) );
		if ( '' !== $id ) {
			$ids[] = $id;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Get configured Engrain asset IDs.
 *
 * @return array
 */
function rfs_engrain_get_configured_asset_ids() {
	return rfs_engrain_parse_asset_ids(
		get_option( 'rentfetch_options_engrain_integration_creds_engrain_asset_ids', '' )
	);
}
