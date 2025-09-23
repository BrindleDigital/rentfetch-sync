<?php
/**
 * Register custom post types for Rent Fetch Sync
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Register the rentfetchentries post type
 *
 * @return void
 */
function rfs_register_rentfetchentries_post_type() {
	$labels = array(
		'name'                  => _x( 'Form Entries', 'Post type general name', 'rentfetchsync' ),
		'singular_name'         => _x( 'Form Entry', 'Post type singular name', 'rentfetchsync' ),
		'menu_name'             => _x( 'Form Entries', 'Admin Menu text', 'rentfetchsync' ),
		'name_admin_bar'        => _x( 'Form Entry', 'Add New on Toolbar', 'rentfetchsync' ),
		'add_new'               => __( 'Add New', 'rentfetchsync' ),
		'add_new_item'          => __( 'Add New Form Entry', 'rentfetchsync' ),
		'new_item'              => __( 'New Form Entry', 'rentfetchsync' ),
		'edit_item'             => __( 'Edit Form Entry', 'rentfetchsync' ),
		'view_item'             => __( 'View Form Entry', 'rentfetchsync' ),
		'all_items'             => __( 'All Form Entries', 'rentfetchsync' ),
		'search_items'          => __( 'Search Form Entries', 'rentfetchsync' ),
		'parent_item_colon'     => __( 'Parent Form Entries:', 'rentfetchsync' ),
		'not_found'             => __( 'No form entries found.', 'rentfetchsync' ),
		'not_found_in_trash'    => __( 'No form entries found in Trash.', 'rentfetchsync' ),
		'featured_image'        => _x( 'Form Entry Image', 'Overrides the "Featured Image" phrase', 'rentfetchsync' ),
		'set_featured_image'    => _x( 'Set form entry image', 'Overrides the "Set featured image" phrase', 'rentfetchsync' ),
		'remove_featured_image' => _x( 'Remove form entry image', 'Overrides the "Remove featured image" phrase', 'rentfetchsync' ),
		'use_featured_image'    => _x( 'Use as form entry image', 'Overrides the "Use as featured image" phrase', 'rentfetchsync' ),
		'archives'              => _x( 'Form Entry archives', 'The post type archive label used in nav menus', 'rentfetchsync' ),
		'insert_into_item'      => _x( 'Insert into form entry', 'Overrides the "Insert into post" phrase', 'rentfetchsync' ),
		'uploaded_to_this_item' => _x( 'Uploaded to this form entry', 'Overrides the "Uploaded to this post" phrase', 'rentfetchsync' ),
		'filter_items_list'     => _x( 'Filter form entries list', 'Screen reader text for the filter links', 'rentfetchsync' ),
		'items_list_navigation' => _x( 'Form entries list navigation', 'Screen reader text for the pagination', 'rentfetchsync' ),
		'items_list'            => _x( 'Form entries list', 'Screen reader text for the items list', 'rentfetchsync' ),
	);

	// Check if Rent Fetch plugin is active to determine menu placement
	$show_in_menu = true; // Default to top-level menu
	if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'rentfetch/rentfetch.php' ) ) {
		$show_in_menu = 'rentfetch-options'; // Make it a submenu of Rent Fetch
	}

	$args = array(
		'labels'             => $labels,
		'public'             => false,
		'publicly_queryable' => false,
		'show_ui'            => true,
		'show_in_menu'       => $show_in_menu,
		'query_var'          => false,
		'rewrite'            => false,
		'capability_type'    => 'post',
		'has_archive'        => false,
		'hierarchical'       => false,
		'menu_position'      => null,
		'menu_icon'          => 'dashicons-clipboard',
		'supports'           => array(''),
		'show_in_rest'       => false,
	);

	register_post_type( 'rentfetchentries', $args );
}
add_action( 'init', 'rfs_register_rentfetchentries_post_type' );

/**
 * Reposition the Form Entries submenu to be last in the Rent Fetch menu
 */
function rfs_reposition_form_entries_submenu() {
	global $submenu;
	
	// Only proceed if Rent Fetch plugin is active and the submenu exists
	if ( ! function_exists( 'is_plugin_active' ) || ! is_plugin_active( 'rentfetch/rentfetch.php' ) ) {
		return;
	}
	
	if ( ! isset( $submenu['rentfetch-options'] ) ) {
		return;
	}
	
	// Find and move the Form Entries menu item to the end
	$form_entries_key = null;
	foreach ( $submenu['rentfetch-options'] as $key => $item ) {
		if ( isset( $item[2] ) && strpos( $item[2], 'edit.php?post_type=rentfetchentries' ) !== false ) {
			$form_entries_key = $key;
			break;
		}
	}
	
	// If found, move it to the end
	if ( $form_entries_key !== null ) {
		$form_entries_item = $submenu['rentfetch-options'][$form_entries_key];
		unset( $submenu['rentfetch-options'][$form_entries_key] );
		$submenu['rentfetch-options'][] = $form_entries_item;
	}
}
add_action( 'admin_menu', 'rfs_reposition_form_entries_submenu', 999 );

/**
 * Add custom columns to the rentfetchentries admin list
 *
 * @param array $columns The existing columns
 * @return array Modified columns
 */
function rentfetch_entries_custom_columns( $columns ) {
	// Remove default columns we don't need
	unset( $columns['title'] );
	unset( $columns['date'] );
	
	// Add our custom columns
	$columns['entry_date'] = 'Date/Time';
	$columns['property'] = 'Property';
	$columns['first_name'] = 'First Name';
	$columns['last_name'] = 'Last Name';
	$columns['email'] = 'Email';
	$columns['phone'] = 'Phone';
	$columns['lead_source'] = 'Lead Source';
	$columns['api_response'] = 'API Response';
	
	return $columns;
}
add_filter( 'manage_rentfetchentries_posts_columns', 'rentfetch_entries_custom_columns' );

/**
 * Populate custom columns with data
 *
 * @param string $column The column name
 * @param int $post_id The post ID
 */
function rentfetch_entries_custom_column_content( $column, $post_id ) {
	$form_data = get_post_meta( $post_id, 'form_data', true );
	$api_response = get_post_meta( $post_id, 'api_response', true );
	$submission_time = get_post_meta( $post_id, 'submission_time', true );
	
	switch ( $column ) {
		case 'entry_date':
			if ( $submission_time ) {
				echo esc_html( date( 'M j, Y g:i A', strtotime( $submission_time ) ) );
			} else {
				echo esc_html( get_the_date( 'M j, Y g:i A', $post_id ) );
			}
			break;
			
		case 'property':
			$property_id = isset( $form_data['property'] ) ? $form_data['property'] : '';
			if ( ! empty( $property_id ) ) {
				$property_name = rentfetch_get_property_name( $property_id );
				echo esc_html( $property_name );
			} else {
				echo '';
			}
			break;
			
		case 'first_name':
			echo isset( $form_data['first_name'] ) ? esc_html( $form_data['first_name'] ) : '';
			break;
			
		case 'last_name':
			echo isset( $form_data['last_name'] ) ? esc_html( $form_data['last_name'] ) : '';
			break;
			
		case 'email':
			echo isset( $form_data['email'] ) ? esc_html( $form_data['email'] ) : '';
			break;
			
		case 'phone':
			echo isset( $form_data['phone'] ) ? esc_html( $form_data['phone'] ) : '';
			break;
			
		case 'lead_source':
			echo isset( $form_data['lead_source'] ) ? esc_html( $form_data['lead_source'] ) : '';
			break;
			
		case 'api_response':
			if ( is_array( $api_response ) ) {
				if ( isset( $api_response['success'] ) && true === $api_response['success'] ) {
					echo '<span style="color: green;">✓ Success';
					if ( isset( $api_response['status_code'] ) ) {
						echo ' (' . esc_html( $api_response['status_code'] ) . ')';
					}
					echo '</span>';
				} else {
					echo '<span style="color: red;">✗ Error';
					if ( isset( $api_response['status_code'] ) && $api_response['status_code'] > 0 ) {
						echo ' (' . esc_html( $api_response['status_code'] ) . ')';
					}
					echo '</span>';
				}
			} else {
				// Fallback for old format
				if ( 200 === $api_response && is_int( $api_response ) ) {
					echo '<span style="color: green;">✓ Success</span>';
				} elseif ( is_int( $api_response ) ) {
					echo '<span style="color: red;">✗ Error (' . esc_html( $api_response ) . ')</span>';
				} elseif ( is_string( $api_response ) && strpos( $api_response, ' - ' ) !== false ) {
					list( $status_code, $message ) = explode( ' - ', $api_response, 2 );
					echo '<span style="color: red;">✗ Error (' . esc_html( $status_code ) . ')</span>';
				} else {
					echo '<span style="color: red;">✗ ' . esc_html( $api_response ) . '</span>';
				}
			}
			break;
	}
}
add_action( 'manage_rentfetchentries_posts_custom_column', 'rentfetch_entries_custom_column_content', 10, 2 );