<?php
/**
 * Start the property sync
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

function rfs_sync_single_property( $property_id, $integration ) {
		
	// bail if there's no property id
	if ( !$property_id )
		return;
		
	// bail if there's no integration
	if ( !$integration )
		return;
		
	$args = [
		'integration' => $integration,
		'property_id' => $property_id,
		'credentials' => rfs_get_credentials(),
		'floorplan_id' => null,
	];
	
	do_action( 'rfs_do_sync', $args );
}

function rfs_perform_syncs() {
	
	// if data syncing is not enabled, kill all the scheduled actions and bail
	$data_sync_enabled = get_option( 'rentfetch_options_data_sync' );
	if ( $data_sync_enabled != 'updatesync' ) {
		as_unschedule_all_actions( 'rfs_do_sync' );
		as_unschedule_all_actions( 'rfs_yardi_do_delete_orphans' );
		
		return;
	}
	
	// Get the enabled integrations
	$enabled_integrations = get_option( 'rentfetch_options_enabled_integrations' );	
	
	// Get the sync timeline, setting it to hourly as a default
	$sync_time = get_option( 'rentfetch_options_sync_timeline', '3600' );
		
	//* Yardi
	
	if ( in_array( 'yardi', $enabled_integrations ) ) {
		
		// get the properties for yardi, then turn it into an array
		$yardi_properties = get_option( 'rentfetch_options_yardi_integration_creds_yardi_property_code' );
		$yardi_properties = str_replace( ' ', '', $yardi_properties );
		$yardi_properties = explode( ',', $yardi_properties );

		// remove orphaned properties, floorplans, and units (this only deletes properties that are no longer in the settings and their associated floorplans and units)
		if ( false === as_has_scheduled_action( 'rfs_yardi_do_delete_orphans', array( $yardi_properties ), 'rentfetch' ) ) {
			as_schedule_recurring_action( time(), (int) $sync_time, 'rfs_yardi_do_delete_orphans', array( $yardi_properties ), 'rentfetch' );
		}	
		
		// cycle through the properties and schedule a sync for each one			
		foreach( $yardi_properties as $yardi_property ) {
			$args = [
				'integration' => 'yardi',
				'property_id' => $yardi_property,
				'credentials' => rfs_get_credentials(),
			];
			
			if ( false === as_has_scheduled_action( 'rfs_do_sync', array( $args ), 'rentfetch' ) ) {
				// need to pass the $args inside an array
				// as_enqueue_async_action( 'rfs_do_sync', array( $args ), 'rentfetch' );
				as_schedule_recurring_action( time(), (int) $sync_time, 'rfs_do_sync', array( $args ), 'rentfetch' );
			}	
		}
		
	}
	
	//* Realpage
	
	if ( in_array( 'realpage', $enabled_integrations ) ) {
		
		// get the properties for yardi, then turn it into an array
		$realpage_properties = get_option( 'rentfetch_options_realpage_integration_creds_realpage_site_ids' );
		$realpage_properties = str_replace( ' ', '', $realpage_properties );
		$realpage_properties = explode( ',', $realpage_properties );
			
		foreach( $realpage_properties as $realpage_property ) {
			$args = [
				'integration' => 'realpage',
				'property_id' => $realpage_property,
				'credentials' => rfs_get_credentials(),
			];
			
			if ( false === as_has_scheduled_action( 'rfs_do_sync', array( $args ), 'rentfetch' ) ) {
				// need to pass the $args inside an array
				// as_enqueue_async_action( 'rfs_do_sync', array( $args ), 'rentfetch' );
				as_schedule_recurring_action( time(), (int) $sync_time, 'rfs_do_sync', array( $args ), 'rentfetch' );
			}	
		}
	}
	
	//* Rent Manager
	
	if ( in_array( 'rentmanager', $enabled_integrations ) ) {
		
		// get the properties for rent manager, then turn it into an array
		$rentmanager_properties = get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames' );
		
		if ( !is_array( $rentmanager_properties ) ) {
			$rentmanager_properties = [];
		}
			
		foreach( $rentmanager_properties as $rentmanager_property ) {
			if ( isset( $rentmanager_property['ShortName'] ) && $rentmanager_property['ShortName'] ) {
				$args = [
					'integration' => 'rentmanager',
					'property_id' => $rentmanager_property['ShortName'],
					'credentials' => rfs_get_credentials(),
				];
				
				if ( false === as_has_scheduled_action( 'rfs_do_sync', array( $args ), 'rentfetch' ) ) {
					// need to pass the $args inside an array
					// as_enqueue_async_action( 'rfs_do_sync', array( $args ), 'rentfetch' );
					as_schedule_recurring_action( time(), (int) $sync_time, 'rfs_do_sync', array( $args ), 'rentfetch' );
				}
			}
		}
	}
	
	
}

add_action( 'rfs_do_sync', 'rfs_sync', 10, 1 );
function rfs_sync( $args ) {
	
	// bail if there's no integration
	if ( !isset($args['integration']) || !$args['integration'] )
		return;
		
	// bail if there's no property id
	if ( !isset($args['property_id']) || !$args['property_id'] )
		return;

	switch ( $args['integration'] ) {
		case 'yardi':
			
			rfs_do_yardi_sync( $args );
						
			break;
		case 'realpage':
			
			rfs_do_realpage_sync( $args );
						
			break;
		case 'rentmanager':
			
			rfs_do_rentmanager_sync( $args );
						
			break;
		case 'entrata':
			
			// TODO do the entrata sync
			
			break;
		default:
			// Handle other integration cases or show an error message.
			break;
			
	}
	
}
