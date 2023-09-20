<?php

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
	$data_sync_enabled = get_option( 'options_data_sync' );
	if ( $data_sync_enabled != 'updatesync' ) {
		as_unschedule_all_actions( 'rfs_do_sync' );
		return;
	}
		
	// get the properties for yardi, then turn it into an array
	$properties = get_option( 'options_yardi_integration_creds_yardi_property_code' );
	$properties = str_replace( ' ', '', $properties );
	$properties = explode( ',', $properties );
	
	// limit array to first 10 items
	// $properties = array_slice( $properties, 0, 3 );
	
	foreach( $properties as $property ) {
		$args = [
			'integration' => 'yardi',
			'property_id' => $property,
			'credentials' => rfs_get_credentials(),
		];
		
		if ( false === as_has_scheduled_action( 'rfs_do_sync', array( $args ), 'rentfetch' ) ) {
			// need to pass the $args inside an array
			// as_enqueue_async_action( 'rfs_do_sync', array( $args ), 'rentfetch' );
			as_schedule_recurring_action( time(), '3600', 'rfs_do_sync', array( $args ), 'rentfetch' );
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
		case 'entrata':
			
			// TODO do the entrata sync
			
			break;
		default:
			// Handle other integration cases or show an error message.
			break;
			
	}
	
}
