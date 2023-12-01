<?php

/**
 * Create a unit if it's needed, return the args with the wordpress unit post ID added either way
 *
 * @param   [type]  $args  [$args description]
 *
 * @return  [type]  $args   return the args with the wordpress unit ID added (whether we created one or just found that there was one already)
 */
function rfs_maybe_create_unit( $args ) {
	
	// bail if there's no unit ID
	if ( !isset( $args['unit_id'] ) || !$args['unit_id'] )
		return;
		
	// bail if there's no floorplan ID
	if ( !isset( $args['floorplan_id'] ) || !$args['floorplan_id'] )
		return;
		
	// bail if there's no property ID
	if ( !isset( $args['property_id'] ) || !$args['property_id'] )
		return;
		
	// bail if there's no integration
	if ( !isset( $args['integration'] ) || !$args['integration'] )
		return;
		
	$unit_id = $args['unit_id'];
	$floorplan_id = $args['floorplan_id'];
	$property_id = $args['property_id'];
	$integration = $args['integration'];
		
	$query_args = array(
		'post_type' => 'units',
		'posts_per_page' => '-1',
		'meta_query' => [
			'relation' => 'AND',
			[
				'key' => 'unit_id',
				'value' => $unit_id,
				'compare' => '=',
			],
			[
				'key' => 'floorplan_id',
				'value' => $floorplan_id,
				'compare' => '=',
			],
			[
				'key' => 'property_id',
				'value' => $property_id,
				'compare' => '=',
			],
			[
				'key' => 'floorplan_source',
				'value' => $integration,
				'compare' => '=',
			],
		],
		
	);

	$unit_posts = get_posts( $query_args );
		
	// bail if we found a post already with this ID, returning the post ID
	if ( $unit_posts ) {
		$args['wordpress_unit_post_id'] = $unit_posts[0]->ID;
		return $args;
	}
		
	// if we're here, we need to create the property post
	$new_unit_post = array(
		'post_title' => $unit_id,
		'post_type' => 'units',
		'post_status' => 'publish',
		'meta_input' => array(
			'unit_id' => $unit_id,
			'floorplan_id' => $floorplan_id,
			'property_id' => $property_id,
			'floorplan_source' => $integration,
		)
	);
	
	$new_unit_post_id = wp_insert_post( $new_unit_post );
	
	$args['wordpress_unit_post_id'] = $new_unit_post_id;
	return $args;
}
