<?php

/**
 * Run through the full process
 *
 * @return 
 */
function rfs_get_license_status( $creds ) {
	
	$creds = array(
		'surecart_secret' => 'thisiswherethatgoes',
		'surecart_current_api_key' => 'thisiswherethecurrentapikeygoes',
	);
	
	$number_of_syncing_properties = rfs_get_property_number();
	
	$license_list = rfs_get_surecart_license_list( $creds );
	$license_id = rfs_get_current_licence_id( $license_list );
	$purchase_id = rfs_get_purchase_id_from_license_id( $license_id );
	
	$price_name = rfs_get_price_name_from_purchase_id( $purchase_id );
	
	$subscription_id = rfs_get_subscription_id_from_purchase( $purchase_id );
	$subscription_status = rfs_get_subscription_status_from_subscription_id( $subscription_id );
	
	$args = array(
		'number_of_syncing_properties' => $number_of_syncing_properties,
		'subscription_status'          => $subscription_status,
		'price_name'                   => $price_name,
	);
	$subscription_valid = rfs_check_if_subscription_valid( $args );
	
		
}

/**
 * Get the number of properties intending to sync
 */
function rfs_get_property_number() {
	
	// todo get the number of properties
	
	return $number_of_syncing_properties;
}

/**
 * Get the list of licenses from SureCart
 * https://developer.surecart.com/reference/list_license
 *
 * @return array list of licenses
 */
function rfs_get_surecart_license_list( $creds ) {
	
	// TODO do an API lookup to get the list of licenses
	
	return $license_list;
}

/**
 * Get the ID of the current key
 *
 * @param   [type]  $license_list  [$license_list description]
 *
 * @return  [type]                 [return description]
 */
function rfs_get_current_licence_id( $license_list ) {
	
	// look through the list and find the id associated with a given key.
	
	return $id;
}

/**
 * Get the purchase ID
 * https://developer.surecart.com/reference/retrieve_license
 *
 * @return  [type]  [return description]
 */
function rfs_get_purchase_id_from_license_id( $license_id ) {
	
	
	// TODO do an API lookup to get the purchase ID using the license ID
	
	return $purchase_id;
}

/**
 * Get the subscription ID
 * https://developer.surecart.com/reference/retrieve_purchase
 *
 * @param   [type]  $purchase_id  [$purchase_id description]
 *
 * @return  [type]                [return description]
 */
function rfs_get_subscription_id_from_purchase( $purchase_id ) {
	
	// todo do an API lookup to get the subscription ID using the purchase ID
	
	return $subscription_id;
}

/**
 * https://developer.surecart.com/reference/retrieve_price
 *
 * @return  [type]  [return description]
 */
function rfs_get_price_name_from_purchase_id() {
	
	// todo do an API call to get the price name from the purchase ID
	
	return $price_name;
}

/**
 * https://developer.surecart.com/reference/retrieve_subscription
 *
 * @param   [type]  $subscription_id  [$subscription_id description]
 *
 * @return  [type]                    [return description]
 */
function rfs_get_subscription_status_from_subscription_id( $subscription_id ) {
	
	
	// {
	// 	"id": "226fa467-4837-4e3c-9c3c-a437372f5ce3",
	// 	"object": "subscription",
	// 	"ad_hoc_amount": null,
	// 	"affiliation_expires_at": null,
	// 	"cancel_at_period_end": false,
	// 	"currency": "usd",
	// 	"current_period_end_at": 1715278026,
	// 	"current_period_start_at": 1713982026,
	// 	"end_behavior": "cancel",
	// 	"ended_at": null,
	// 	"finite": false,
	// 	"live_mode": true,
	// 	"manual_payment": false,
	// 	"metadata": {},
	// 	"pending_update": {},
	// 	"quantity": 1,
	// 	"remaining_period_count": null,
	// 	"restore_at": null,
	// 	"subtotal_amount": 10,
	// 	"status": "trialing",
	// 	"tax_enabled": false,
	// 	"trial_end_at": 1715278026,
	// 	"trial_start_at": 1713982026,
	// 	"variant_options": null,
	// 	"affiliation": null,
	// 	"current_cancellation_act": null,
	// 	"current_period": "a776eb43-94e5-4227-b5d2-2507d0c11e5b",
	// 	"customer": "60c98a12-223b-43c1-8f51-f97f34883af0",
	// 	"discount": null,
	// 	"manual_payment_method": null,
	// 	"payment_method": null,
	// 	"price": "51319b09-0e28-494d-a634-f87a6f46eae5",
	// 	"purchase": "c6c3a505-18a8-45a7-8547-6d85510f5289",
	// 	"shipping_method": null,
	// 	"variant": null,
	// 	"created_at": 1713982026,
	// 	"updated_at": 1713982027
	// }
	
	// todo do an API call to get the status of the current subscription
	
	// todo make sure it's current
	
	return $subscription_status;
	
}

function rfs_check_if_subscription_valid( $args ) {
	
	// todo based on the inputs, check if the subscription is valid
}