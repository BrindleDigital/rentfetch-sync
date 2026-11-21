<?php
/** Current configuration for queued syncs and destructive cleanup. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/rentegi/compatibility-rentegi.php';

/** Other PHP workers can have changed options since this request bootstrapped. */
function rfs_get_fresh_option( $key, $default = false ) {
	wp_cache_delete( $key, 'options' );
	$alloptions = wp_cache_get( 'alloptions', 'options' );
	if ( is_array( $alloptions ) && array_key_exists( $key, $alloptions ) ) {
		wp_cache_delete( 'alloptions', 'options' );
	}
	$notoptions = wp_cache_get( 'notoptions', 'options' );
	if ( is_array( $notoptions ) && isset( $notoptions[ $key ] ) ) {
		unset( $notoptions[ $key ] );
		wp_cache_set( 'notoptions', $notoptions, 'options' );
	}
	return get_option( $key, $default );
}

function rfs_get_configured_property_ids( $integration ) {
	$keys = array(
		'yardi' => 'rentfetch_options_yardi_integration_creds_yardi_property_code',
		'entrata' => 'rentfetch_options_entrata_integration_creds_entrata_property_ids',
		'engrain' => 'rentfetch_options_engrain_integration_creds_engrain_asset_ids',
		'rentegi' => 'rentfetch_options_rentegi_property_codes',
	);
	if ( 'rentmanager' === $integration ) {
		rfs_get_fresh_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames' );
		rfs_get_fresh_option( 'rentfetch_options_rentmanager_disabled_property_shortnames' );
		return array_map( 'strval', array_column( rfs_get_enabled_rentmanager_properties(), 'ShortName' ) );
	}
	if ( ! isset( $keys[ $integration ] ) ) { return array(); }
	$value = rfs_get_fresh_option( $keys[ $integration ], '' );
	$ids = is_array( $value ) ? array_filter( $value, 'is_scalar' ) : preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
	$ids = array_map( static function( $id ) { return trim( (string) $id ); }, $ids );
	return array_values( array_unique( array_filter( $ids, 'strlen' ) ) );
}

function rfs_is_property_configured_for_sync( $integration, $property_id ) {
	if ( 'rentegi' === $integration && ! rfs_rentegi_core_is_compatible() ) {
		return false;
	}
	$enabled = rfs_get_fresh_option( 'rentfetch_options_enabled_integrations', array() );
	return is_array( $enabled ) && in_array( $integration, $enabled, true )
		&& in_array( (string) $property_id, rfs_get_configured_property_ids( $integration ), true );
}

/** Cancel removed recurring jobs once, when the configured property manifest changes. */
function rfs_prune_removed_property_sync_actions( $enabled ) {
	$current = array();
	foreach ( array_intersect( (array) $enabled, array( 'yardi', 'entrata', 'engrain', 'rentmanager', 'rentegi' ) ) as $source ) {
		if ( 'rentegi' === $source && ! rfs_rentegi_core_is_compatible() ) {
			continue;
		}
		foreach ( rfs_get_configured_property_ids( $source ) as $id ) {
			$current[ $source . ':' . $id ] = array( 'integration' => $source, 'property_id' => $id );
		}
	}
	$previous = get_option( 'rfs_scheduled_property_manifest', array() );
	$previous = is_array( $previous ) ? $previous : array();
	foreach ( array_diff_key( $previous, $current ) as $args ) {
		as_unschedule_all_actions( 'rfs_do_sync', array( $args ), 'rentfetch' );
	}
	if ( $previous !== $current ) { update_option( 'rfs_scheduled_property_manifest', $current, false ); }
}
