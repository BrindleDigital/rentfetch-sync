<?php
/** Save the property from a validated RentEGI inventory. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rfs_rentegi_update_property( $inventory ) {
	return rfs_rentegi_upsert( 'properties', 'property', $inventory['property']['id'], $inventory['property'] );
}
