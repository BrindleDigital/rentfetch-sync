<?php

/**
 * SureCart License Activation
 *
 * @package RentFetch_Sync
 */
function rfs_check_sc_license() {
	
	$license_info = get_option( 'rentfetchsync_license_options');

	if ( $license_info && is_array( $license_info ) ) {
		$license_key = $license_info['sc_license_key'];
		$license_id = $license_info['sc_license_id'];
		$activation_id = $license_info['sc_activation_id'];
	}
	
	$activation_id_response = rfs_get_info_from_activation_id( $activation_id );
	$license_key_response = rfs_get_info_from_license_key( $license_key );
	
	$product_id = $license_key_response->product;
	
	$properties_limit = rfs_check_product_properties_number( $product_id );
	
	if ( $license_key_response->status === 'active' && $properties_limit ) {
		add_action( 'admin_notices', 'rfs_notice_licensing_active' );
	} else {
		add_action( 'admin_notices', 'rfs_notice_licensing_inactive' );
	}
	
}
add_action( 'rfs_do_check_sc_license', 'rfs_check_sc_license' );
do_action( 'rfs_do_check_sc_license' );

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
		'38757479-7914-4e9e-aacd-62f065ee9603' => 10, // 10 properties
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
		<p><?php _e('Ready to sync? <a href="/wp-admin/admin.php?page=rentfetch-sync-manage-license">Let\'s get your account activated.</a>', 'rentfetch-sync'); ?></p>
	</div>
	<?php
}

function rfs_notice_licensing_active() {
	?>x
	<div class="notice notice-success is-dismissible">
		<p><?php _e('Looks like you\'re all set! Syncing is enabled.', 'rentfetch-sync'); ?></p>
	</div>
	<?php
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