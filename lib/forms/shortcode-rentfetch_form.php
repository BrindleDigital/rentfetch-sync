<?php
/**
 * Set up the lead form shortcode for RentFetch.
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Outputs the RentFetch form shortcode.
 *
 * This function generates a form for collecting user information and optionally selecting a property.
 * It uses the provided shortcode attributes and URL parameters to customize the form's behavior.
 *
 * @param array $atts Shortcode attributes.
 *
 * @return string The HTML output of the form.
 */
function rfs_output_form( $atts ) {
	
	
	
	$a = shortcode_atts(
		array(
			'type'               => 'leads',
			'confirmation'       => 'Thanks for your submission. We will get back to you shortly.',
			'property'           => null,
			'lead_source'        => null,
			'submit_label'       => null,
			'redirection_url'    => null,
		),
		$atts
	);
	
	ob_start();
	    	
	// let's also look in the URL for properties, using the same format as the shortcode
	if ( isset( $_GET['property'] ) ) {
		$a['property'] = sanitize_text_field( $_GET['property'] );
	}

	// NOTE: We intentionally do NOT set or embed a lead_source server-side (from URL or cookie)
	// because that value may be cached into HTML. The hidden lead_source input is always
	// rendered empty and will be populated client-side by a non-minified runtime script.

	// Get properties using the new function
	$properties_data = rentfetch_get_properties_for_form( $a['property'] );

	$single_property = false;
	if ( count( $properties_data ) === 1 ) {
		$single_property = true;
	}
	
	// Enqueue form handler and populate script. We intentionally do NOT print cookie/URL-derived
	// lead_source values into the HTML to avoid caching those values into pages.
	wp_enqueue_script( 'rentfetch-form-script' ); // Enqueue the registered handler
	wp_enqueue_script( 'rentfetch-form-populate' ); // Enqueue the runtime-populate script (not minified)

	// Provide data to the frontend. Use wp_localize_script to emit the JS object for both scripts
	$localized_data = array(
		'ajaxurl' => esc_url( admin_url( 'admin-ajax.php' ) ),
		'nonce'   => wp_create_nonce( 'rentfetch_form_submit' ),
		'shortcode_lead_source' => $a['lead_source'],
	);
	
	wp_localize_script( 'rentfetch-form-script', 'rentfetchFormAjax', $localized_data );
	wp_localize_script( 'rentfetch-form-populate', 'rentfetchFormAjax', $localized_data );
	
	$classes = 'rentfetch-form-' . esc_attr( $a['type'] );

	printf( '<form id="rentfetch-form" class="rentfetch-form %s" method="post">', $classes );
		echo '<div class="rentfetch-form-body">';
		
			if ( ! empty( $properties_data ) ) {
				$property_field_class = 'rentfetch-form-field-group rentfetch-form-field-property';
				if ( $single_property ) {
					$property_field_class .= ' rentfetch-form-field-hidden';
				}
				printf( '<div class="%s">', esc_attr( $property_field_class ) );
					echo '<label for="rentfetch-form-property" class="rentfetch-form-label">Property <span class="rentfetch-form-required-label">(Required)</span></label>';
					echo '<select required id="rentfetch-form-property" name="rentfetch_property" class="rentfetch-form-select" required>';
						echo '<option value="">Select a property</option>';
						foreach ( $properties_data as $property ) {
							$selected = '';
							if ( $single_property && isset( $property['property_id'] ) ) {
								$selected = ' selected="selected"';
							}
							// Add the data-property-source attribute
							$data_source = isset( $property['property_source'] ) ? ' data-property-source="' . esc_attr( $property['property_source'] ) . '"' : '';
							printf( '<option value="%s"%s%s>%s</option>', esc_attr( $property['property_id'] ), $selected, $data_source, esc_html( $property['property_title'] ) );
						}
					echo '</select>';
				echo '</div>';
			} else {
				echo '<div class="rentfetch-form-field-group rentfetch-form-field-property">';
					echo '<label for="rentfetch-form-property" class="rentfetch-form-label">Property not found.</label>';
				echo '</div>';
			}
			
			if ( 'tour' === $a['type'] ) {
				
				wp_enqueue_style( 'blaze-style' );
				wp_enqueue_script( 'blaze-script' );
				wp_enqueue_script( 'rentfetch-form-availability-blaze-slider-init' );
				
				// Enqueue and localize the script to fetch availability for Entrata
				wp_enqueue_script( 'rentfetch-form-entrata-availability' );
				
				wp_localize_script(
					'rentfetch-form-entrata-availability',
					'rentfetchEntrataTourAvailabilityAjax',
					array(
						'ajaxurl' => esc_url( admin_url( 'admin-ajax.php' ) ),
						'nonce'   => wp_create_nonce( 'rentfetch_entrata_tour_availability_check' ),
					)
				);
				
				echo '<div class="rentfetch-availability-dates" style="display:none;"></div>';
				echo '<div class="rentfetch-availability-times" style="display:none;"></div>';
				
				// Appointment date field.
				echo '<div class="rentfetch-form-field-group rentfetch-form-field-appointment_date" style="display:none;">';
					echo '<label for="rentfetch-form-appointment_date" class="rentfetch-form-label">Appointment Date</label>';
					printf('<input type="text" id="rentfetch-form-appointment_date" name="rentfetch_appointment_date" class="rentfetch-form-input" value="" readonly>');
				echo '</div>';
				
				// Appointment start time field.
				echo '<div class="rentfetch-form-field-group rentfetch-form-field-appointment_start_time" style="display:none;">';
					echo '<label for="rentfetch-form-appointment_start_time" class="rentfetch-form-label">Appointment Start Time</label>';
					printf('<input type="text" id="rentfetch-form-appointment_start_time" name="rentfetch_appointment_start_time" class="rentfetch-form-input" value="" readonly>');
				echo '</div>';
				
				// Appointment end time field.
				echo '<div class="rentfetch-form-field-group rentfetch-form-field-appointment_end_time" style="display:none;">';
					echo '<label for="rentfetch-form-appointment_end_time" class="rentfetch-form-label">Appointment End Time</label>';
					printf('<input type="text" id="rentfetch-form-appointment_end_time" name="rentfetch_appointment_end_time" class="rentfetch-form-input" value="" readonly>');
				echo '</div>';
			}
			
			// First and last name fields.
			echo '<fieldset class="rentfetch-form-fieldset rentfetch-form-fieldset-name">';
				echo '<legend class="rentfetch-form-label">Your Name <span class="rentfetch-form-required-label">(Required)</span></legend>';
				echo '<div class="rentfetch-form-field-group rentfetch-form-field-first-name">';
					echo '<input type="text" id="rentfetch-form-first-name" name="rentfetch_first_name" class="rentfetch-form-input" required>';
					echo '<label for="rentfetch-form-first-name" class="rentfetch-form-label-subfield">First Name</label>';
				echo '</div>';

				echo '<div class="rentfetch-form-field-group rentfetch-form-field-last-name">';
					echo '<input type="text" id="rentfetch-form-last-name" name="rentfetch_last_name" class="rentfetch-form-input" required>';
					echo '<label for="rentfetch-form-last-name" class="rentfetch-form-label-subfield">Last Name</label>';
				echo '</div>';

			echo '</fieldset>';

			// Email field.
			echo '<div class="rentfetch-form-field-group rentfetch-form-field-email">';
				echo '<label for="rentfetch-form-email" class="rentfetch-form-label">Email <span class="rentfetch-form-required-label">(Required)</span></label>';
				echo '<input type="email" id="rentfetch-form-email" name="rentfetch_email" class="rentfetch-form-input" required>';
			echo '</div>';

			// Phone field.
			echo '<div class="rentfetch-form-field-group rentfetch-form-field-phone">';
				echo '<label for="rentfetch-form-phone" class="rentfetch-form-label">Phone <span class="rentfetch-form-required-label">(Required)</span></label>';
				echo '<input type="tel" id="rentfetch-form-phone" name="rentfetch_phone" class="rentfetch-form-input" required>';
			echo '</div>';

			// Lead source field (always empty in server-rendered HTML to avoid caching values).
			// Use a hidden input so scripts can set its value reliably and it will be submitted with the form.
			echo '<div class="rentfetch-form-field-group rentfetch-form-field-lead_source" style="display: none;">';
				echo '<label for="rentfetch-form-lead_source" class="rentfetch-form-label">Lead source</label>';
				// Intentionally render an empty hidden input; the runtime script will populate from URL or cookie.
				echo '<input type="hidden" id="rentfetch-form-lead_source" name="rentfetch_lead_source" value="">';
				echo '</div>';

			// Message field.
			echo '<div class="rentfetch-form-field-group rentfetch-form-field-message">';
				echo '<label for="rentfetch-form-message" class="rentfetch-form-label">Message</label>';
				echo '<textarea id="rentfetch-form-message" name="rentfetch_message" rows="3" class="rentfetch-form-textarea"></textarea>';
			echo '</div>';
			
			// Honeypot field for spam prevention.
			echo '<div class="rentfetch-form-honeypot" style="display: none;">';
				echo '<label for="rentfetch-form-address">Street Address:</label>';
				echo '<input type="text" id="rentfetch-form-address" name="rentfetch_address" tabindex="-1" autocomplete="off">';
			echo '</div>';
			
			// Confirmation text set in the shortcode.
			if ( isset( $a['confirmation'] ) ) {
				echo '<div class="rentfetch-form-field-group rentfetch-form-field-confirmation" style="display: none;">';
					echo '<label for="rentfetch-form-confirmation" class="rentfetch-form-label">Confirmation</label>';
					printf('<input type="text" id="rentfetch-form-confirmation" name="rentfetch_confirmation" class="rentfetch-form-input" value="%s" readonly>', esc_html( $a['confirmation'] ) );
				echo '</div>';
			}
			
			// Redirection URL set in the shortcode.
			if ( isset( $a['redirection_url'] ) ) {
				echo '<div class="rentfetch-form-field-group rentfetch-form-field-redirection_url" style="display: none;">';
					echo '<label for="rentfetch-form-redirection-url" class="rentfetch-form-label">Redirection URL</label>';
					printf('<input type="text" id="rentfetch-form-redirection-url" name="rentfetch_redirection_url" class="rentfetch-form-input" value="%s" readonly>', esc_url( $a['redirection_url'] ) );
				echo '</div>';
			}
			
			// Debug field for verifying API response (add ?debug to the URL to use this field).
			if ( isset( $_GET['debug'] ) ) {
				echo '<div class="rentfetch-form-field-group rentfetch-form-field-debug" style="display: none;">';
					echo '<label for="rentfetch-form-debug" class="rentfetch-form-label">Debug</label>';
					echo '<input type="text" id="rentfetch-form-debug" name="rentfetch_debug" tabindex="-1" autocomplete="off" value="1" readonly>';
				echo '</div>';
			}

		echo '</div>'; // Close form body

		echo '<div class="rentfetch-form-submit-group">';

			// Set up the submit button label based on the form type.
			if ( 'leads' === $a['type'] ) {
				$submit_label = 'Send Message';
			} elseif ( 'tour' === $a['type'] ) {
				$submit_label = 'Schedule Tour';
			}
			
			if ( isset( $a['submit_label'] ) ) {
				$submit_label = $a['submit_label'];
			}
		
			printf( '<button type="submit" class="rentfetch-form-button">%s</button>', esc_html( $submit_label ) );
			
		echo '</div>';

	echo '</form>';

	return ob_get_clean();
}
add_shortcode( 'rentfetch_form', 'rfs_output_form' );

/**
 * Get the properties and return the appropriate information for each to the form.
 *
 * @param string $property_ids_string A comma-separated string of property IDs to filter by.
 *
 * @return array An array of properties with their ID, source, and title.
 */
function rentfetch_get_properties_for_form( $property_ids_string = '' ) {
	$args = array(
		'post_type'      => 'properties', // Corrected post type
		'posts_per_page' => -1,        // Get all matching properties
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'     => 'property_id', // Your meta key for property ID
				'compare' => 'EXISTS',
			),
			array(
				'key'     => 'property_source', // Your meta key for property source
				'compare' => 'EXISTS',
			),
		),
		'orderby'        => 'title',    // Order by post title
		'order'          => 'ASC',      // Alphabetical order
		'fields'         => 'ids',      // Optimize by only getting post IDs
	);

	// If specific property IDs (meta values) are provided, modify the meta_query
	if ( ! empty( $property_ids_string ) ) {
		$property_ids_meta = array_filter( array_map( 'trim', explode( ',', $property_ids_string ) ) );
		if ( ! empty( $property_ids_meta ) ) {
			// Add a new clause to the meta_query to filter by property_id meta values
			$args['meta_query'][] = array(
				'key'     => 'property_id',
				'value'   => $property_ids_meta,
				'compare' => 'IN',
			);
		}
	}

	$property_posts = get_posts( $args );

	$properties_data = array();

	if ( ! empty( $property_posts ) ) {
		foreach ( $property_posts as $post_id ) {
			$property_id    = get_post_meta( $post_id, 'property_id', true );
			$property_source = get_post_meta( $post_id, 'property_source', true );
			$property_title = get_the_title( $post_id );

			// Only add to the array if property_id and property_source exist (should be true due to meta_query)
			if ( ! empty( $property_id ) && ! empty( $property_source ) ) {
				$properties_data[] = array(
					'property_id'     => $property_id,
					'property_source' => $property_source,
					'property_title'  => $property_title,
				);
			}
		}
	}

	return $properties_data;
}

/**
 * Handles the rentfetch form AJAX submission, validation, and API submission.
 */
function rentfetch_handle_ajax_form_submit() {
	
	// Verify the nonce
	if ( ! isset( $_POST['rentfetch_form_nonce'] ) || ! wp_verify_nonce( $_POST['rentfetch_form_nonce'], 'rentfetch_form_submit' ) ) {
		// Save the failed submission
		$raw_form_data = array(
			'first_name' => isset( $_POST['rentfetch_first_name'] ) ? sanitize_text_field( $_POST['rentfetch_first_name'] ) : '',
			'last_name'  => isset( $_POST['rentfetch_last_name'] ) ? sanitize_text_field( $_POST['rentfetch_last_name'] ) : '',
			'email'      => isset( $_POST['rentfetch_email'] ) ? sanitize_email( $_POST['rentfetch_email'] ) : '',
			'phone'      => isset( $_POST['rentfetch_phone'] ) ? sanitize_text_field( $_POST['rentfetch_phone'] ) : '',
			'property'   => isset( $_POST['rentfetch_property'] ) ? sanitize_text_field( $_POST['rentfetch_property'] ) : '',
			'message'    => isset( $_POST['rentfetch_message'] ) ? sanitize_textarea_field( $_POST['rentfetch_message'] ) : '',
		);
		$entry_id = rentfetch_save_form_entry( $raw_form_data, 'nonce_failed', 'security_check_failed', array( 'error_message' => 'Security check failed.' ) );
		
		wp_send_json_error( array( 'errors' => array( 'Security check failed.' ), 'entry_id' => $entry_id ) );
	}

	// Basic honeypot check
	if ( ! empty( $_POST['rentfetch_address'] ) ) {
		// Save the failed submission
		$raw_form_data = array(
			'first_name' => isset( $_POST['rentfetch_first_name'] ) ? sanitize_text_field( $_POST['rentfetch_first_name'] ) : '',
			'last_name'  => isset( $_POST['rentfetch_last_name'] ) ? sanitize_text_field( $_POST['rentfetch_last_name'] ) : '',
			'email'      => isset( $_POST['rentfetch_email'] ) ? sanitize_email( $_POST['rentfetch_email'] ) : '',
			'phone'      => isset( $_POST['rentfetch_phone'] ) ? sanitize_text_field( $_POST['rentfetch_phone'] ) : '',
			'property'   => isset( $_POST['rentfetch_property'] ) ? sanitize_text_field( $_POST['rentfetch_property'] ) : '',
			'message'    => isset( $_POST['rentfetch_message'] ) ? sanitize_textarea_field( $_POST['rentfetch_message'] ) : '',
		);
		$entry_id = rentfetch_save_form_entry( $raw_form_data, 'spam_detected', 'honeypot_triggered', array( 'error_message' => 'Spam detected.' ) );
		
		wp_send_json_error( array( 'errors' => array( 'Spam detected.' ), 'entry_id' => $entry_id ) );
	}

	// Sanitize and validate form data
	$first_name   = isset( $_POST['rentfetch_first_name'] ) ? sanitize_text_field( $_POST['rentfetch_first_name'] ) : '';
	$last_name    = isset( $_POST['rentfetch_last_name'] ) ? sanitize_text_field( $_POST['rentfetch_last_name'] ) : '';
	$email        = isset( $_POST['rentfetch_email'] ) ? sanitize_email( $_POST['rentfetch_email'] ) : '';
	$phone        = isset( $_POST['rentfetch_phone'] ) ? sanitize_text_field( $_POST['rentfetch_phone'] ) : '';
	$property     = isset( $_POST['rentfetch_property'] ) ? sanitize_text_field( $_POST['rentfetch_property'] ) : '';
	$lead_source  = isset( $_POST['rentfetch_lead_source'] ) ? sanitize_textarea_field( $_POST['rentfetch_lead_source'] ) : '';
	$message      = isset( $_POST['rentfetch_message'] ) ? sanitize_textarea_field( $_POST['rentfetch_message'] ) : '';
	$debug        = isset( $_POST['rentfetch_debug'] ) ? sanitize_textarea_field( $_POST['rentfetch_debug'] ) : '';
	$confirmation = isset( $_POST['rentfetch_confirmation'] ) ? sanitize_textarea_field( $_POST['rentfetch_confirmation'] ) : '';
	$appointment_date = isset( $_POST['rentfetch_appointment_date'] ) ? sanitize_text_field( $_POST['rentfetch_appointment_date'] ) : '';
	$appointment_start_time = isset( $_POST['rentfetch_appointment_start_time'] ) ? sanitize_text_field( $_POST['rentfetch_appointment_start_time'] ) : '';
	$appointment_end_time = isset( $_POST['rentfetch_appointment_end_time'] ) ? sanitize_text_field( $_POST['rentfetch_appointment_end_time'] ) : '';

	$errors = array();

	if ( empty( $first_name ) ) {
		$errors[] = 'First name is required.';
	}

	if ( empty( $last_name ) ) {
		$errors[] = 'Last name is required.';
	}

	if ( empty( $email ) || ! is_email( $email ) ) {
		$errors[] = 'A valid email address is required.';
	}
	
	// add validation for the phone number
	if ( empty( $phone ) ) {
		$errors[] = 'Phone number is required.';
	} elseif ( ! preg_match( '/^\+?[0-9\s\-()]+$/', $phone ) ) {
		$errors[] = 'Invalid phone number format.';
	} elseif ( preg_match_all( '/\d/', $phone ) < 10 ) {
		$errors[] = 'Phone number must contain at least 10 digits.';
	} elseif ( preg_match_all( '/\d/', $phone ) > 15 ) {
		$errors[] = 'Phone number must not contain more than 15 digits.';
	}

	if ( empty( $property ) ) {
		$errors[] = 'Property is required.';
	}

	// If there are validation errors, send JSON error response
	if ( ! empty( $errors ) ) {
		// Prepare partial form data for logging
		$partial_form_data = array(
			'first_name'    => ! empty( $first_name ) ? $first_name : null,
			'last_name'     => ! empty( $last_name ) ? $last_name : null,
			'email'         => ! empty( $email ) ? $email : null,
			'phone'         => ! empty( $phone ) ? $phone : null,
			'property'      => ! empty( $property ) ? $property : null,
			'message'       => ! empty( $message ) ? $message : null,
			'lead_source'   => ! empty( $lead_source ) ? $lead_source : null,
			'appointment_date' => ! empty( $appointment_date ) ? $appointment_date : null,
			'appointment_start_time' => ! empty( $appointment_start_time ) ? $appointment_start_time : null,
			'appointment_end_time' => ! empty( $appointment_end_time ) ? $appointment_end_time : null,
			'debug'         => ! empty( $debug ) ? $debug : null,
		);
		
		// Save the failed submission
		$entry_id = rentfetch_save_form_entry( $partial_form_data, 'validation_error', 'validation_failed', array( 'validation_errors' => $errors ) );
		
		wp_send_json_error( array( 'errors' => $errors, 'entry_id' => $entry_id ) );
	}

	// Validation successful. Prepare data for API submission.
	$form_data = array(
		'first_name'    => ! empty( $first_name ) ? $first_name : null,
		'last_name'     => ! empty( $last_name ) ? $last_name : null,
		'email'         => ! empty( $email ) ? $email : null,
		'phone'         => ! empty( $phone ) ? $phone : null,
		'property'      => ! empty( $property ) ? $property : null,
		'message'       => ! empty( $message ) ? $message : null,
		'lead_source'   => ! empty( $lead_source ) ? $lead_source : null,
		'appointment_date' => ! empty( $appointment_date ) ? $appointment_date : null,
		'appointment_start_time' => ! empty( $appointment_start_time ) ? $appointment_start_time : null,
		'appointment_end_time' => ! empty( $appointment_end_time ) ? $appointment_end_time : null,
		'debug'         => ! empty( $debug ) ? $debug : null,
		// Add any other necessary fields
	);
	
	// do a query for posts of the type 'properties' with the property_id as a meta key
	$property_post = get_posts( array(
		'post_type'   => 'properties',
		'meta_key'    => 'property_id',
		'meta_value'  => $property,
		'numberposts' => 1,
		'post_status' => 'publish', // Ensure the post is published
	) );
	
	if ( ! empty( $property_post ) ) {
		$property_post = $property_post[0];
	} else {
		// Save the failed submission
		$entry_id = rentfetch_save_form_entry( $form_data, 'property_not_found', 'property_not_found', array( 'error_message' => 'This property cannot be found in our database.' ) );
		
		wp_send_json_error( array( 'errors' => array( 'This property cannot be found in our database.' ), 'entry_id' => $entry_id ) );
	}
	
	// get the property_source for the property
	$property_source = get_post_meta( $property_post->ID, 'property_source', true );
	if ( empty( $property_source ) ) {
		// Save the failed submission
		$entry_id = rentfetch_save_form_entry( $form_data, 'no_api_configured', 'no_api_configured', array( 'error_message' => 'This property has no corresponding API to send data to.' ) );
		
		wp_send_json_error( array( 'errors' => array( 'This property has no corresponding API to send data to.' ), 'entry_id' => $entry_id ) );
	}
	
	if ( 'entrata' === $property_source ) {
		$response = rentfetch_send_lead_to_entrata( $form_data, $property_source, $property );
	} elseif ( 'yardi' === $property_source ) {
		$response = rentfetch_send_lead_to_yardi( $form_data, $property_source, $property );
	} elseif ( 'rentmanager' === $property_source ) {
		$response = rentfetch_send_lead_to_rentmanager( $form_data, $property_source, $property );
	} else {
		// Save the failed submission
		$entry_id = rentfetch_save_form_entry( $form_data, $property_source, array(
			'success' => false,
			'status_code' => 0,
			'message' => 'This property has no corresponding API to send data to.',
		), array( 'error_message' => 'This property has no corresponding API to send data to.' ) );
		
		wp_send_json_error( array( 'errors' => array( 'This property has no corresponding API to send data to.' ), 'entry_id' => $entry_id ) );	
	}

	// Check if the API call was successful
	if ( isset( $response['success'] ) && true === $response['success'] ) {
		$message = apply_filters( 'rentfetch_form_success_message', $confirmation );
		
		// Save the form entry to the database
		$entry_id = rentfetch_save_form_entry( $form_data, $property_source, $response );
		
		wp_send_json_success( array( 'message' => $message, 'data' => $form_data, 'entry_id' => $entry_id ) );
	} else {
		// Handle error response
		$error_msg = 'Your message was not received.';
		
		if ( isset( $response['status_code'] ) && $response['status_code'] > 0 ) {
			$error_msg = "API error (HTTP {$response['status_code']})";
			if ( ! empty( $response['message'] ) ) {
				$error_msg .= ": {$response['message']}";
			}
		} elseif ( isset( $response['message'] ) ) {
			$error_msg = "API error: {$response['message']}";
		}
		
		// Save the form entry to the database even on error
		$entry_id = rentfetch_save_form_entry( $form_data, $property_source, $response );
		
		wp_send_json_error( array( 'errors' => array( $error_msg ), 'entry_id' => $entry_id ) );
	}
	
}
add_action( 'wp_ajax_rentfetch_ajax_submit_form', 'rentfetch_handle_ajax_form_submit' );
add_action( 'wp_ajax_nopriv_rentfetch_ajax_submit_form', 'rentfetch_handle_ajax_form_submit' );

/**
 * Sends lead data to Entrata API.
 *
 * @param array  $form_data    The form data submitted by the user.
 * @param string $integration  The integration type (e.g., 'entrata').
 * @param string $property_id  The property ID associated with the lead.
 *
 * @return array Array with 'success' (bool), 'status_code' (int), and 'message' (string).
 */
function rentfetch_send_lead_to_entrata( $form_data, $integration, $property_id ) {
	
	$args = [
		'integration' => $integration,
		'property_id' => $property_id,
		'credentials' => rfs_get_credentials(),
		'floorplan_id' => null,
	];
	
	$api_key   = rfs_get_entrata_api_key();
	$subdomain = $args['credentials']['entrata']['subdomain'];
	$property_id = $args['property_id'];

	// Bail if required arguments are missing.
	$missing = [];
	
	if ( ! $api_key ) {
		$missing[] = 'API key';
	}
	
	if ( ! $subdomain ) {
		$missing[] = 'subdomain';
	}
	
	if ( ! $property_id ) {
		$missing[] = 'property ID';
	}
	
	if ( ! empty( $missing ) ) {
		return array(
			'success' => false,
			'status_code' => 0,
			'message' => 'Missing required API information: ' . implode(', ', $missing),
		);
	}
	
	// If the lead_source is not set, set it to the default value.
	if ( empty( $form_data['lead_source'] ) ) {
		$form_data['lead_source'] = '105949'; // Default lead source ID
	}
	
	//! GETLEADS API to check if there's already a lead with this email address.
	
	// Set the URL for the API request.
	$url = sprintf( 'https://apis.entrata.com/ext/orgs/%s/v1/leads', $subdomain );
	
	// Set the body for the request.
	$getleads_body_array = array(
		'auth'      => array(
			'type' => 'apikey',
		),
		'requestId' => '15',
		'method'    => array(
			'name'   => 'getLeads',
			'params' => array(
				'propertyId' => $property_id,
				'email' => $form_data['email'],
				'excludeAmenities' => '1', // Exclude amenities from the response
				'doNotSendConfirmationEmail' => '0',
			),
		),
	);
	
	$getleads_body_json = wp_json_encode( $getleads_body_array );
	
	// Set the headers for the request.
	$headers = array(
		'X-Api-Key'    => $api_key,
		'Content-Type' => 'application/json',
	);

	// Make the API request using wp_remote_post.
	$getleads_response = wp_remote_post(
		$url,
		array(
			'headers' => $headers,
			'body'    => $getleads_body_json,
			'timeout' => 10,
		)
	);
	
	// Retrieve and decode the response body.
	$getleads_response_body = wp_remote_retrieve_body( $getleads_response );
	
	// let's decode the response body
	$getleads_response_body = json_decode( $getleads_response_body, true );
	
	//! If there's an applicationId already, then we don't use the sendLeads API, but we'll instead use the updateLeads API.
	$application_id = (int) $getleads_response_body['response']['result']['prospects'][0]['prospect'][0]['applicationId'] ?? null;
	
	//! SENDLEADS API if this is a brand new lead, or UPDATELEADS API if this is an existing lead with an applicationId.
	
	// Set the URL for the API request.
	$url = sprintf( 'https://apis.entrata.com/ext/orgs/%s/v1/leads', $subdomain );
	
	// Set the body for the request.
	$body_array = array(
		'auth'      => array(
			'type' => 'apikey',
		),
		'requestId' => '15',
		'method'    => array(
			'name'   => $application_id ? 'updateLeads' : 'sendLeads',
			'params' => array(
				'propertyId' => (int) $property_id,
				'doNotSendConfirmationEmail' => '0',
				'isWaitList' => '0',
				'prospects' => array(
					'prospect' => array(
						'leadSource' => array(
							'originatingLeadSourceId' => $form_data['lead_source'],
						),
						'createdDate' => gmdate( 'm/d/Y\TH:i:s', strtotime( '-6 hours' ) ), // this API requires mountain time
						'customers' => array(
							'customer' => array(
								'name' => array(
									'firstName' => $form_data['first_name'],
									'lastName'  => $form_data['last_name'],
								),
								'phone' => array(
									'personalPhoneNumber' => $form_data['phone'],
								),
								'email' => $form_data['email'],
							),
						),
						'customerPreferences' => array(
							'desiredMoveInDate' => ! empty( $form_data['desired_move_in_date'] ) ? $form_data['desired_move_in_date'] : null,
							'comment'            => ! empty( $form_data['message'] ) ? $form_data['message'] : 'customer Preferences Comment',
						),
					),
				),
			),
		),
	);
	
	// If the applicationId is set, add it to the body
	if ( ! empty( $application_id ) ) {
		
		// add the applicationId to the body.
		$body_array['method']['params']['prospects']['prospect']['applicationId'] = $application_id;
		
		// add the version 'r2' to the method.
		$body_array['method']['version'] = 'r2';
		
	}
	
	// If  $form_data['schedule'] is set, add it to the body
	if ( ! empty( $form_data['appointment_date'] ) && ! empty( $form_data['appointment_start_time'] ) && ! empty( $form_data['appointment_end_time'] ) ) {
		$body_array['method']['params']['prospects']['prospect']['events']['event'] = array(
			array(
				'type'            => 'Appointment',
				'eventTypeId'     => '17', // this is the nomenclature for sendLeads.
				'subtypeId'       => '454',
				'date'            => gmdate( 'm/d/Y\TH:i:s', strtotime( '-6 hours' ) ), // this API requires mountain time
				'appointmentDate' => $form_data['appointment_date'],
				'timeFrom'        => $form_data['appointment_start_time'],
				'timeTo'          => $form_data['appointment_end_time'],
				'eventReasons'    => 'Tour',
				'typeId'          => '17', // required for updateLeads.
				'title'           => 'Appointment', // required for updateLeads.
				'comments'        => 'n/a', // required to have a value for updateLeads. For sendLeads, a value is not required.
			),
		);
	}

	// Convert the body to JSON format.
	$body_json = wp_json_encode( $body_array );
	
	// Set the headers for the request.
	$headers = array(
		'X-Api-Key'    => $api_key,
		'Content-Type' => 'application/json',
	);

	// Make the API request using wp_remote_post.
	$response = wp_remote_post(
		$url,
		array(
			'headers' => $headers,
			'body'    => $body_json,
			'timeout' => 10,
		)
	);
	
	// Retrieve and decode the response body.
	$response_body = wp_remote_retrieve_body( $response );
	
	//! This is important for every API. Needs to work similarly once those are implemented.
	// if the debug parameter is 1, output the response body
	if ( isset( $form_data['debug'] ) && 1 === (int) $form_data['debug'] ) {
		wp_send_json( $response_body );
	}

	// let's decode the response body
	$response_body = json_decode( $response_body, true );
	
	// Check if the request was successful
	$http_status = (int) $response_body['response']['code'];
	
	// Any non-200 HTTP status is an error
	if ( 200 !== $http_status ) {
		error_log( 'Entrata API Error - HTTP Status: ' . $http_status . ', Response: ' . wp_json_encode( $response_body ) );
		
		$error_message = '';
		if ( isset( $response_body['response']['result']['prospects']['prospect'][0]['message'] ) ) {
			$error_message = $response_body['response']['result']['prospects']['prospect'][0]['message'];
		} elseif ( isset( $response_body['response']['result']['prospects'][0]['message'] ) ) {
			$error_message = $response_body['response']['result']['prospects'][0]['message'];
		} elseif ( isset( $response_body['response']['error']['message'] ) ) {
			$error_message = $response_body['response']['error']['message'];
			// Append Entrata error code if available
			if ( isset( $response_body['response']['error']['code'] ) ) {
				$error_message .= ' (Error Code: ' . $response_body['response']['error']['code'] . ')';
			}
		} elseif ( isset( $response_body['error'] ) ) {
			$error_message = $response_body['error'];
		}
		
		return array(
			'success' => false,
			'status_code' => $http_status,
			'message' => $error_message ?: 'HTTP ' . $http_status . ' error',
		);
	}
	
	// HTTP 200 - check for error indicators in the response body
	$has_error = false;
	$error_message = '';
	if ( isset( $response_body['response']['result']['prospects']['prospect'][0]['message'] ) ) {
		$message = $response_body['response']['result']['prospects']['prospect'][0]['message'];
		// Check if the message indicates an error
		if ( stripos( $message, 'error' ) !== false || 
			 stripos( $message, 'failed' ) !== false || 
			 stripos( $message, 'invalid' ) !== false ||
			 stripos( $message, 'denied' ) !== false ) {
			$has_error = true;
			$error_message = $message;
		}
	}
	
	if ( ! $has_error ) {
		return array(
			'success' => true,
			'status_code' => 200,
			'message' => 'Lead submitted successfully',
		);
	}
	
	// Error found in 200 response
	error_log( 'Entrata API Error in 200 response - Response: ' . wp_json_encode( $response_body ) );
	
	return array(
		'success' => false,
		'status_code' => 200,
		'message' => $error_message ?: 'Unknown error in successful response',
	);
	
}

function rentfetch_send_lead_to_yardi( $form_data, $property_source, $property ) {
	return array(
		'success' => false,
		'status_code' => 0,
		'message' => 'Yardi API not currently implemented for leads',
	);
}

function rentfetch_send_lead_to_rentmanager( $form_data, $property_source, $property ) {
	return array(
		'success' => false,
		'status_code' => 0,
		'message' => 'RentManager API not currently implemented for leads',
	);
}