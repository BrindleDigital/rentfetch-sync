<?php

add_action( 'wp_loaded', 'rfs_delete_third_party_properties_and_floorplans' );
function rfs_delete_third_party_properties_and_floorplans() {
        
    // bail if it's not set to delete. If it is, we'll proceed.
	$data_sync_enabled = get_option( 'options_data_sync' );
	if ( $data_sync_enabled != 'delete' )
		return;
    
    //* FLOORPLANS        
    // do a query to check and see if a post already exists with this ID 
    $args = array(
        'post_type' => 'floorplans',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'floorplan_source',
                'compare' => 'EXISTS',
            )
        )
    );
    $query = new WP_Query($args);
    
    $matchingposts = $query->posts;
    foreach ($matchingposts as $matchingpost) {
        wp_delete_post( $matchingpost->ID, true );
    }
    
    //* PROPERTIES    
    // do a query to check and see if a post already exists with this ID 
    $args = array(
        'post_type' => 'properties',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'property_source',
                'compare' => 'EXISTS',
            )
        )
    );
    $query = new WP_Query($args);
    
    $matchingposts = $query->posts;
    foreach ($matchingposts as $matchingpost) {
        wp_delete_post( $matchingpost->ID, true );
    }
    
}