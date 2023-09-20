<?php

function rfs_maybe_create_property( $args ) {

    // bail if there's no property ID
    if ( !isset( $args['property_id'] ) || !$args['property_id'] )
        return;
        
    // bail if there's no integration
    if ( !isset( $args['integration'] ) || !$args['integration'] )
        return;
    
    $property_id = $args['property_id'];
    $integration = $args['integration'];
    
    $query_args = array(
        'post_type' => 'properties',
        'posts_per_page' => '-1',
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'property_id',
                'value' => $property_id,
                'compare' => '=',
            ],
            [
                'key' => 'property_source',
                'value' => $integration,
                'compare' => '=',
            ],
        ],
        
    );

    $property_posts = get_posts( $query_args );
        
    // bail if we found a post already with this ID, returning the post ID
    if ( $property_posts ) {
                        
        $args['wordpress_post_id'] = $property_posts[0]->ID;
        return $args;
    }
        
    // if we're here, we need to create the property post
    $new_property_post = array(
        'post_title' => $property_id,
        'post_type' => 'properties',
        'post_status' => 'publish',
        'meta_input' => array(
            'property_id' => $property_id,
            'property_source' => $integration,
        )
    );
    
    $new_property_post_id = wp_insert_post( $new_property_post );
    
    $args['wordpress_post_id'] = $new_property_post_id;
    return $args;
        
}