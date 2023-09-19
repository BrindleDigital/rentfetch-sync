<?php

// for testing, let's grab one of them and do that.
add_action( 'admin_init', 'rfs_init' );
function rfs_init() {
	
	//! Yardi notes
	// any fake property id return a 1020 error
	// p0556894 returns a 1050 error
	
	$args = [
		'integration' => 'yardi',
		'property_id' => 'p1158248',
		'credentials' => rfs_get_credentials(),
		'floorplan_id' => null,
	];
	
	rfs_do_sync( $args );
}

// for testing, let's try grabbing 10 of them at a time (just to see how fast the API responds)
// add_action( 'admin_footer', 'rfs_init2' );
function rfs_init2() {
	
	$properties = get_option( 'options_yardi_integration_creds_yardi_property_code' );
	
	// remove spaces
	$properties = str_replace( ' ', '', $properties );
	
	// split into array
	$properties = explode( ',', $properties );
	
	// limit array to first 10 items
	$properties = array_slice( $properties, 0, 10 );
	
	console_log( $properties );

	foreach( $properties as $property ) {
		$args = [
			'integration' => 'yardi',
			'property_id' => $property,
			'credentials' => rfs_get_credentials(),
		];
		
		rfs_do_sync( $args );
	}
	
}

function rfs_do_sync( $args ) {

	// bail if there's no integration
	if ( !isset($args['integration']) || !$args['integration'] )
		return;
		
	// bail if there's no property id
	if ( !isset($args['property_id']) || !$args['property_id'] )
		return;

	switch ( $args['integration'] ) {
		case 'yardi':
			
			//~ With just the property ID, we can get property data, property images, and the floorplan data.
			$propertydata = rfs_yardi_get_property_data( $args );
			
			// TODO process the property data
			
			$property_images = rfs_yard_get_property_images( $args );
			
			// TODO process the property images
			
			$floorplandata = rfs_yardi_get_floorplan_data( $args );	
			
			// TODO process the floorplan data
						
			//~ We'll need the floorplan ID to get the availablility information
			foreach ( $floorplandata as $floorplan ) {
				
				// skip if there's no floorplan id
				if ( !isset( $floorplan['FloorplanId'] ) )
					continue;
					
				$floorplan_id = $floorplan['FloorplanId'];
				
				$args['floorplan_id'] = $floorplan_id;
				
				$availabilitydata = rfs_yardi_get_floorplan_availability( $args );
				
				// TODO add the units based on the availability data
				
				// TODO process the availability data and add that to the floorplans
								
			}
			
			
			break;
		case 'entrata':
			rfs_entrata_get_property_data( $args );
			break;
		default:
			// Handle other integration cases or show an error message.
			break;
			
	}
	
}

function rfs_entrata_get_property_data( $args ) {
	// Implement the entrata integration logic here.
	// var_dump( $args );
}
