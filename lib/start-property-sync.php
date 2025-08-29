<?php
/**
 * Start the property sync
 *
 * @package rentfetchsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Sync a single property
 *
 * @param   string  $property_id  the property ID to sync (this is the property ID from the API, not the wordpress post ID)
 * @param   string  $integration  a predetermined integration name
 *
 * @return  void.
 */
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

	// initialize progress (0 of 1 until integrations update to finer-grained steps)
	rfs_set_sync_progress( $integration, $property_id, 0, 1, 'Starting sync' );

	do_action( 'rfs_do_sync', $args );

	// finalize progress (ensure completion if integrations didn't set finer steps)
	rfs_set_sync_progress( $integration, $property_id, 1, 1, 'Completed' );
}


/**
 * Store sync progress for polling from the admin UI.
 *
 * @param string $integration
 * @param string $property_id
 * @param int    $step
 * @param int    $total
 * @param string $message
 *
 * @return void
 */
function rfs_set_sync_progress( $integration, $property_id, $step, $total, $message = '' ) {
	if ( ! $integration || ! $property_id ) {
		return;
	}

	$key = 'rfs_sync_progress_' . md5( $integration . '_' . $property_id );

	$data = array(
		'integration' => $integration,
		'property_id' => $property_id,
		'step' => (int) $step,
		'total' => (int) $total,
		'message' => $message,
		'updated' => time(),
	);

	// store for 5 minutes
	$stored = set_transient( $key, $data, 5 * MINUTE_IN_SECONDS );

	// Helpful debugging output when WP_DEBUG is enabled — this will show in php error log/local tooling
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $stored ) ) {
		error_log( sprintf( 'rfs_set_sync_progress: key=%s step=%d total=%d msg=%s', $key, (int) $step, (int) $total, $message ) );
	}

	return $stored;
}


/**
 * AJAX handler to fetch current progress for a given property+integration.
 * Returns JSON.
 */
function rfs_get_sync_progress_ajax_handler() {
	check_ajax_referer('rfs_ajax_nonce', '_ajax_nonce');

	if ( ! isset( $_POST['property_id'] ) || ! isset( $_POST['integration'] ) ) {
		wp_send_json_error( array( 'message' => 'Invalid request' ), 400 );
	}

	$property_id = sanitize_text_field( wp_unslash( $_POST['property_id'] ) );
	$integration = sanitize_text_field( wp_unslash( $_POST['integration'] ) );

	$key = 'rfs_sync_progress_' . md5( $integration . '_' . $property_id );
	$data = get_transient( $key );

	if ( ! $data ) {
		wp_send_json_error( array( 'message' => 'no-progress' ), 404 );
	}

	wp_send_json_success( $data );
	
	// If the progress indicates completion, clear the stored transient so it doesn't linger.
	$step = isset( $data['step'] ) ? (int) $data['step'] : 0;
	$total = isset( $data['total'] ) ? (int) $data['total'] : 0;
	if ( $total > 0 && $step >= $total ) {
		// delete the transient after capturing the data to return to the client
		delete_transient( $key );
	}

	
}
add_action('wp_ajax_rfs_get_sync_progress', 'rfs_get_sync_progress_ajax_handler');

/**
 * Do the syncs
 *
 * @return  void.
 */
function rfs_perform_syncs() {
	
	// --- Throttle and context guard ------------------------------------------------
    // Run immediately when in admin, WP-CLI, or during cron jobs.
    // For front-end hits, only run once per hour (transient guard) to avoid
    // repeating expensive scheduling checks on every page load.
	$is_admin_or_cron = is_admin() || ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) || ( defined( 'DOING_CRON' ) && DOING_CRON );

    if ( ! $is_admin_or_cron ) {
        // If we've run recently, skip doing work for this request.
        if ( false !== get_transient( 'rfs_perform_syncs_last_run' ) ) {
            return;
        }
        // Mark we've run — TTL controls how often front-end requests can trigger this.
        // Use 1 hour as a reasonable default; adjust if needed.
        set_transient( 'rfs_perform_syncs_last_run', 1, 15 * MINUTE_IN_SECONDS );
    }
    // ------------------------------------------------------------------------------

	
	// if data syncing is not enabled, kill all the scheduled actions and bail
	$data_sync_enabled = get_option( 'rentfetch_options_data_sync' );
	if ( $data_sync_enabled != 'updatesync' ) {
		as_unschedule_all_actions( 'rfs_do_sync' );
		as_unschedule_all_actions( 'rfs_yardi_do_delete_orphans' );
		
		return;
	}
	
	// Get the enabled integrations
	$enabled_integrations = get_option( 'rentfetch_options_enabled_integrations' );	
	
	// Get the sync timeline, setting it to hourly as a default
	$sync_time = (int) apply_filters( 'rentfetch_sync_timeline', '86400' );
		
	//* Yardi
	
	if ( in_array( 'yardi', $enabled_integrations ) ) {
		
		// get the properties for yardi, then turn it into an array
		$yardi_properties = get_option( 'rentfetch_options_yardi_integration_creds_yardi_property_code' );
		$yardi_properties = str_replace( ' ', '', $yardi_properties );
		$yardi_properties = explode( ',', $yardi_properties );

		// remove orphaned properties, floorplans, and units (this only deletes properties that are no longer in the settings and their associated floorplans and units)
		if ( false === as_has_scheduled_action( 'rfs_yardi_do_delete_orphans', array( $yardi_properties ), 'rentfetch' ) ) {
			as_schedule_recurring_action( time(), (int) $sync_time, 'rfs_yardi_do_delete_orphans', array( $yardi_properties ), 'rentfetch' );
		}	
		
		// cycle through the properties and schedule a sync for each one			
		foreach( $yardi_properties as $yardi_property ) {
			$args = [
				'integration' => 'yardi',
				'property_id' => $yardi_property,
				'credentials' => rfs_get_credentials(),
			];
			
			if ( false === as_has_scheduled_action( 'rfs_do_sync', array( $args ), 'rentfetch' ) ) {
				// need to pass the $args inside an array
				as_schedule_recurring_action( time(), (int) $sync_time, 'rfs_do_sync', array( $args ), 'rentfetch' );
			}	
		}
		
	}
	
	//* Entrata
	
	if ( in_array( 'entrata', $enabled_integrations ) ) {
		
		// get the properties for yardi, then turn it into an array
		$entrata_properties = get_option( 'rentfetch_options_entrata_integration_creds_entrata_property_ids' );
		$entrata_properties = str_replace( ' ', '', $entrata_properties );
		$entrata_properties = explode( ',', $entrata_properties );

		// TODO orphan detection
		// // remove orphaned properties, floorplans, and units (this only deletes properties that are no longer in the settings and their associated floorplans and units)
		// if ( false === as_has_scheduled_action( 'rfs_yardi_do_delete_orphans', array( $yardi_properties ), 'rentfetch' ) ) {
		// 	as_schedule_recurring_action( time(), (int) $sync_time, 'rfs_yardi_do_delete_orphans', array( $yardi_properties ), 'rentfetch' );
		// }	
		
		// cycle through the properties and schedule a sync for each one			
		foreach( $entrata_properties as $entrata_property ) {
			$args = [
				'integration' => 'entrata',
				'property_id' => $entrata_property,
				'credentials' => rfs_get_credentials(),
			];
			
			if ( false === as_has_scheduled_action( 'rfs_do_sync', array( $args ), 'rentfetch' ) ) {
				// need to pass the $args inside an array
				as_schedule_recurring_action( time(), (int) $sync_time, 'rfs_do_sync', array( $args ), 'rentfetch' );
			}	
		}
		
	}
	
	//* Realpage
	
	if ( in_array( 'realpage', $enabled_integrations ) ) {
		
		// get the properties for yardi, then turn it into an array
		$realpage_properties = get_option( 'rentfetch_options_realpage_integration_creds_realpage_site_ids' );
		$realpage_properties = str_replace( ' ', '', $realpage_properties );
		$realpage_properties = explode( ',', $realpage_properties );
			
		foreach( $realpage_properties as $realpage_property ) {
			$args = [
				'integration' => 'realpage',
				'property_id' => $realpage_property,
				'credentials' => rfs_get_credentials(),
			];
			
			if ( false === as_has_scheduled_action( 'rfs_do_sync', array( $args ), 'rentfetch' ) ) {
				// need to pass the $args inside an array
				as_schedule_recurring_action( time(), (int) $sync_time, 'rfs_do_sync', array( $args ), 'rentfetch' );
			}	
		}
	}
	
	//* Rent Manager
	
	if ( in_array( 'rentmanager', $enabled_integrations ) ) {
		
		// get the properties for rent manager, then turn it into an array
		$rentmanager_properties = get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_property_shortnames' );
		
		if ( !is_array( $rentmanager_properties ) ) {
			$rentmanager_properties = [];
		}
			
		foreach( $rentmanager_properties as $rentmanager_property ) {
			if ( isset( $rentmanager_property['ShortName'] ) && $rentmanager_property['ShortName'] ) {
				$args = [
					'integration' => 'rentmanager',
					'property_id' => $rentmanager_property['ShortName'],
					'credentials' => rfs_get_credentials(),
				];
				
				if ( false === as_has_scheduled_action( 'rfs_do_sync', array( $args ), 'rentfetch' ) ) {
					// need to pass the $args inside an array
					as_schedule_recurring_action( time(), (int) $sync_time, 'rfs_do_sync', array( $args ), 'rentfetch' );
				}
			}
		}
	}
	
	
}
add_action( 'wp_loaded', 'rfs_perform_syncs' );

/**
 * Trigger a specific function after we determine which integration to use
 *
 * @param   array  $args  the args to pass to the function
 *
 * @return  void.
 */
function rfs_trigger_specific_api_sync( $args ) {
	
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
		case 'realpage':
			
			rfs_do_realpage_sync( $args );
						
			break;
		case 'rentmanager':
			
			rfs_do_rentmanager_sync( $args );
						
			break;
		case 'entrata':
			
			rfs_do_entrata_sync( $args );
			
			break;
		default:
			// Handle other integration cases or show an error message.
			break;
			
	}
	
}
add_action( 'rfs_do_sync', 'rfs_trigger_specific_api_sync', 10, 1 );
