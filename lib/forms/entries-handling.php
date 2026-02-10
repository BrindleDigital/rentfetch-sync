<?php


/**
 * Save form submission data and API response to rentfetchentries post type
 *
 * @param array $form_data The sanitized form data
 * @param string $property_source The property source (entrata, yardi, etc.)
 * @param mixed $api_response The API response (int for status code or string for error)
 * @param array $additional_data Additional data like property post ID, validation errors, etc.
 * @return int The post ID of the created entry
 */
function rentfetch_save_form_entry( $form_data, $property_source, $api_response, $additional_data = array() ) {
	
	// Create a title for the entry
	$title = sprintf( '%s %s - %s', $form_data['first_name'], $form_data['last_name'], date( 'M j, Y g:i A' ) );
	
	// Create the post
	$post_data = array(
		'post_title'   => $title,
		'post_content' => '', // Keep content empty, data is in meta boxes
		'post_type'    => 'rentfetchentries',
		'post_status'  => 'publish',
		'meta_input'   => array(
			'form_data'       => $form_data,
			'property_source' => $property_source,
			'api_response'    => $api_response,
			'submission_time' => current_time( 'mysql' ),
			'submission_ip'   => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'user_agent'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		),
	);
	
	// Add additional meta data
	if ( ! empty( $additional_data ) ) {
		$post_data['meta_input'] = array_merge( $post_data['meta_input'], $additional_data );
	}
	
	$post_id = wp_insert_post( $post_data );
	
	return $post_id;
}

/**
 * Get property name from property ID with fallback to ID
 *
 * @param string|int $property_id The external property ID (stored as post meta on property posts)
 * @return string The property name or ID if not found
 */
function rentfetch_get_property_name( $property_id ) {
	if ( empty( $property_id ) ) {
		return '';
	}
	
	// Find the property post where the 'property_id' meta matches the given property_id
	$property_posts = get_posts( array(
		'post_type'   => 'properties',
		'meta_key'    => 'property_id',
		'meta_value'  => $property_id,
		'numberposts' => 1,
	) );
	
	if ( ! empty( $property_posts ) ) {
		return $property_posts[0]->post_title;
	}
	
	// Fallback to the ID if property not found
	return $property_id;
}



/**
 * Add meta boxes for rentfetchentries post type
 */
function rentfetch_entries_meta_boxes() {
	add_meta_box(
		'rentfetch_entry_details',
		'Form Submission Details',
		'rentfetch_entry_details_meta_box',
		'rentfetchentries',
		'normal',
		'high'
	);
	
	add_meta_box(
		'rentfetch_entry_api_response',
		'API Response',
		'rentfetch_entry_api_response_meta_box',
		'rentfetchentries',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'rentfetch_entries_meta_boxes' );

/**
 * Display the form submission details meta box
 */
function rentfetch_entry_details_meta_box( $post ) {
	$form_data = get_post_meta( $post->ID, 'form_data', true );
	$submission_time = get_post_meta( $post->ID, 'submission_time', true );
	$submission_ip = get_post_meta( $post->ID, 'submission_ip', true );
	$user_agent = get_post_meta( $post->ID, 'user_agent', true );
	
	if ( empty( $form_data ) ) {
		echo '<p>No form data available.</p>';
		return;
	}
	
	echo '<table class="widefat fixed" style="border: none;">';
	echo '<tbody>';
	
	foreach ( $form_data as $key => $value ) {
		if ( empty( $value ) ) continue;
		
		$label = ucfirst( str_replace( '_', ' ', $key ) );
		
		// Special handling for property field - show name instead of ID
		if ( 'property' === $key ) {
			$value = rentfetch_get_property_name( $value );
		}
		
		echo '<tr>';
		echo '<td style="width: 150px; font-weight: bold;">' . esc_html( $label ) . ':</td>';
		echo '<td>' . esc_html( $value ) . '</td>';
		echo '</tr>';
	}
	
	if ( ! empty( $submission_time ) ) {
		echo '<tr>';
		echo '<td style="font-weight: bold;">Submission Time:</td>';
		echo '<td>' . esc_html( $submission_time ) . '</td>';
		echo '</tr>';
	}
	
	if ( ! empty( $submission_ip ) ) {
		echo '<tr>';
		echo '<td style="font-weight: bold;">IP Address:</td>';
		echo '<td>' . esc_html( $submission_ip ) . '</td>';
		echo '</tr>';
	}
	
	if ( ! empty( $user_agent ) ) {
		echo '<tr>';
		echo '<td style="font-weight: bold;">User Agent:</td>';
		echo '<td style="word-break: break-all;">' . esc_html( $user_agent ) . '</td>';
		echo '</tr>';
	}
	
	echo '</tbody>';
	echo '</table>';
}

/**
 * Display the API response meta box
 */
function rentfetch_entry_api_response_meta_box( $post ) {
	$api_response = get_post_meta( $post->ID, 'api_response', true );
	$property_source = get_post_meta( $post->ID, 'property_source', true );
	
	echo '<table class="widefat fixed" style="border: none;">';
	echo '<tbody>';
	
	echo '<tr>';
	echo '<td style="width: 150px; font-weight: bold;">Property Source:</td>';
	echo '<td>' . esc_html( $property_source ) . '</td>';
	echo '</tr>';
	
	echo '<tr>';
	echo '<td style="font-weight: bold;">API Response:</td>';
	echo '<td>';
	
	if ( is_array( $api_response ) ) {
		if ( isset( $api_response['success'] ) && true === $api_response['success'] ) {
			echo '<span style="color: green; font-weight: bold;">✓ Success';
			if ( isset( $api_response['status_code'] ) ) {
				echo ' (HTTP ' . esc_html( $api_response['status_code'] ) . ')';
			}
			echo '</span>';
			if ( ! empty( $api_response['message'] ) ) {
				echo '<br><strong>Message:</strong> ' . esc_html( $api_response['message'] );
			}
		} else {
			echo '<span style="color: red; font-weight: bold;">✗ Error';
			if ( isset( $api_response['status_code'] ) && $api_response['status_code'] > 0 ) {
				echo ' (HTTP ' . esc_html( $api_response['status_code'] ) . ')';
			}
			echo '</span>';
			if ( ! empty( $api_response['message'] ) ) {
				echo '<br><strong>Message:</strong> ' . esc_html( $api_response['message'] );
			}
		}
	} else {
		// Fallback for old format
		if ( 200 === $api_response && is_int( $api_response ) ) {
			echo '<span style="color: green; font-weight: bold;">Success (HTTP 200)</span>';
		} elseif ( is_int( $api_response ) ) {
			echo '<span style="color: red; font-weight: bold;">Error (HTTP ' . esc_html( $api_response ) . ')</span>';
		} elseif ( is_string( $api_response ) && strpos( $api_response, ' - ' ) !== false ) {
			list( $status_code, $message ) = explode( ' - ', $api_response, 2 );
			echo '<span style="color: red; font-weight: bold;">Error (HTTP ' . esc_html( $status_code ) . ')</span><br>';
			echo '<strong>Message:</strong> ' . esc_html( $message );
		} else {
			echo '<span style="color: red; font-weight: bold;">Error:</span> ' . esc_html( $api_response );
		}
	}
	
	echo '</td>';
	echo '</tr>';
	
	// Display additional error information if available
	$validation_errors = get_post_meta( $post->ID, 'validation_errors', true );
	$error_message = get_post_meta( $post->ID, 'error_message', true );
	
	if ( ! empty( $validation_errors ) ) {
		echo '<tr>';
		echo '<td style="font-weight: bold;">Validation Errors:</td>';
		echo '<td>';
		if ( is_array( $validation_errors ) ) {
			echo '<ul>';
			foreach ( $validation_errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo esc_html( $validation_errors );
		}
		echo '</td>';
		echo '</tr>';
	}
	
	if ( ! empty( $error_message ) ) {
		echo '<tr>';
		echo '<td style="font-weight: bold;">Error Message:</td>';
		echo '<td>' . esc_html( $error_message ) . '</td>';
		echo '</tr>';
	}
	
	echo '</tbody>';
	echo '</table>';
}
