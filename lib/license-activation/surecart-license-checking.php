<?php

/**
 * SureCart License Activation
 *
 * @package RentFetch_Sync
 */
function rfs_check_sc_license() {
	
	// Check if the transient exists (this will autodelete whenever the sync settings are saved)
	$properties_limit = get_transient( 'rentfetchsync_properties_limit' );
	
	// Clear the transient
	// delete_transient('rentfetchsync_properties_limit');

	// Check if the transient exists, and bail out if it does
	if ( !empty($properties_limit) ) {
		// rentfetch_console_log( 'Transient exists: ' . $properties_limit ); 
		// Transient exists, use the value
		$properties_limit = (int) $properties_limit;
		return $properties_limit;
	}
		
	$license_info = get_option( 'rentfetchsync_license_options');
	
	//! bail if there's no license key entered
	if ( !$license_info ) {
		return;
	}

	if ( $license_info && is_array( $license_info ) ) {
		$license_key = $license_info['sc_license_key'];
		$license_id = $license_info['sc_license_id'];
		$activation_id = $license_info['sc_activation_id'];
	}
	
	// $activation_id_response = rfs_get_info_from_activation_id( $activation_id );
	$license_key_response = rfs_get_info_from_license_key( $license_key );
	
	$product_id = $license_key_response->product;
	$properties_limit = rfs_check_product_properties_number( $product_id );
	
	// Set the transient for rentfetchsync_properties_limit
	set_transient( 'rentfetchsync_properties_limit', (int) $properties_limit, DAY_IN_SECONDS );

	if ( $license_key_response->status === 'active' && $properties_limit ) {
		// add_action( 'admin_notices', 'rfs_notice_licensing_active' );
	} else {
		// add_action( 'admin_notices', 'rfs_notice_licensing_inactive' );
	}
	
	return $properties_limit;
}
add_action( 'init', 'rfs_check_sc_license' );
// do_action( 'rfs_do_check_sc_license' );

/**
 * Check the number of properties allowed for the purchased product
 *
 * @param   string  $product_id  The product id.
 *
 * @return  int  The number of properties allowed.
 */
function rfs_check_product_properties_number( $product_id ) {
	
	$products = array(
		'f550b18f-3b50-479a-9b9f-31f085f7802a' => 1, // Single property
		'd1e45bb0-578b-4b49-8428-bda9373cf6b7' => 5, // 5 properties
		'38757479-7914-4e9e-aacd-62f065ee9603' => 10,
		'dbc217f1-7efe-427d-9e98-4d3d136eb9ae' => 20,
		'd6de98f5-a462-49b8-978e-14882600f561' => 50,
		'230d099b-29ae-4366-97a3-c005785eada3' => 100,
		'20b869a1-cc96-4ebd-8b19-eaf43d4bac18' => 999999, // Unlimited properties
	);
	
	if (isset($products[$product_id])) {
		return (int) $products[$product_id];
	} else {
		return 0;
	}
	
}

function rfs_notice_licensing_inactive() {
	?>
	<div class="notice notice-success is-dismissible">
		<p><?php _e('Ready to sync? <a href="/wp-admin/admin.php?page=rentfetch-sync-manage-license">Let\'s get your Rent Fetch Sync account activated.</a>', 'rentfetch-sync'); ?></p>
	</div>
	<?php
}

function rfs_notice_licensing_active() {
	$properties_limit = (int) get_transient( 'rentfetchsync_properties_limit' );
	// rentfetch_console_log( $properties_limit );
	if ( 1 === $properties_limit ) {
		echo '<div class="notice notice-success is-dismissible">';
			echo '<p>Your Rent Fetch Sync license level has been rechecked, and your license allows you to sync one property.</p>';
		echo '</div>';
	} elseif( 0 <= $properties_limit ) {		
		echo '<div class="notice notice-success is-dismissible">';
			printf( '<p>Your Rent Fetch Sync license level has been rechecked, and your license allows you to sync up to %s properties.</p>', $properties_limit );
		echo '</div>';
	} else {
		echo '<div class="notice notice-error is-dismissible">';
			echo '<p>Whoops! We couldn\'t find your Rent Fetch Sync license information. Please resave the sync settings page.</p>';
		echo '</div>';
	}
}

/**
 * Get the information we can get from the activation id.
 *
 * @param   string  $activation_id  The activation id.
 *
 * @return  array the response from the SureCart API.
 */
function rfs_get_info_from_activation_id( $activation_id ) {

	$curl = curl_init();

	$bearer_token = RENTFETCHSYNC_SURECART_PUBLIC_TOKEN;

	curl_setopt_array($curl, array(
		CURLOPT_URL => "https://api.surecart.com/v1/public/activations/$activation_id",
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => "",
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => "GET",
		CURLOPT_HTTPHEADER => array(
			"accept: application/json",
			"authorization: Bearer $bearer_token"
		),
	));

	$response = curl_exec($curl);
	$err = curl_error($curl);

	curl_close($curl);

	if ($err) {
		return "cURL Error #:" . $err;
	} else {
		return json_decode( $response, true );
	}

}

/**
 * Get the information we can get from the license key.
 *
 * @param   string  $license_key  The license key.
 *
 * @return  object the response from the SureCart API.
 */
function rfs_get_info_from_license_key( $license_key ) {

	$curl = curl_init();

	$bearer_token = RENTFETCHSYNC_SURECART_PUBLIC_TOKEN;

	curl_setopt_array($curl, array(
		CURLOPT_URL => "https://api.surecart.com/v1/public/licenses/$license_key",
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => "",
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => "GET",
		CURLOPT_HTTPHEADER => array(
			"accept: application/json",
			"authorization: Bearer $bearer_token"
		),
	));

	$response = curl_exec($curl);
	$err = curl_error($curl);

	curl_close($curl);

	if ($err) {
		return "cURL Error #:" . $err;
	} else {
		return json_decode( $response );
	}

}