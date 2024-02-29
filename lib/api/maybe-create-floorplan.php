<?php

/**
 * Create a floorplan if it's needed, return the args with the WordPress floorplan post ID added either way
 *
 * @param   [type] $args the args passed to the function (should include the floorplan ID, property ID, and integration).
 *
 * @return  [type]  $args return the args with the WordPress floorplan ID added (whether we created one or just found that there was one already)
 */
function rfs_maybe_create_floorplan( $args ) {

	// bail if there's no floorplan ID.
	if ( ! isset( $args['floorplan_id'] ) || ! $args['floorplan_id'] ) {
		return;
	}

	// bail if there's no property ID.
	if ( ! isset( $args['property_id'] ) || ! $args['property_id'] ) {
		return;
	}

	// bail if there's no integration.
	if ( ! isset( $args['integration'] ) || ! $args['integration'] ) {
		return;
	}

	$floorplan_id = $args['floorplan_id'];
	$property_id  = $args['property_id'];
	$integration  = $args['integration'];

	$query_args = array(
		'post_type'      => 'floorplans',
		'posts_per_page' => '-1',
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'     => 'floorplan_id',
				'value'   => $floorplan_id,
				'compare' => '=',
			),
			array(
				'key'     => 'property_id',
				'value'   => $property_id,
				'compare' => '=',
			),
			array(
				'key'     => 'floorplan_source',
				'value'   => $integration,
				'compare' => '=',
			),
		),

	);

	$floorplan_posts = get_posts( $query_args );

	// bail if we found a post already with this ID, returning the post ID.
	if ( $floorplan_posts ) {
		$args['wordpress_floorplan_post_id'] = $floorplan_posts[0]->ID;
		return $args;
	}

	// if we're here, we need to create the floorplan post.
	$new_floorplan_post = array(
		'post_title'  => $floorplan_id,
		'post_type'   => 'floorplans',
		'post_status' => 'publish',
		'meta_input'  => array(
			'floorplan_id'     => $floorplan_id,
			'property_id'      => $property_id,
			'floorplan_source' => $integration,
		),
	);

	$new_floorplan_post_id = wp_insert_post( $new_floorplan_post );

	$args['wordpress_floorplan_post_id'] = $new_floorplan_post_id;
	return $args;
}
