<?php
/** Shared central API transport and persistent remote sync lock. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rfs_is_sync_locked( $refresh = false ) {
	if ( $refresh ) {
		// A different PHP request may have locked the site while credentials were being refreshed.
		wp_cache_delete( 'rfs_api_sync_locked', 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions['rfs_api_sync_locked'] ) ) {
			unset( $notoptions['rfs_api_sync_locked'] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}
	return '1' === get_option( 'rfs_api_sync_locked', '0' );
}

function rfs_sync_locked_message() {
	return "You've exceeded your sync quota with Rent Fetch Sync. Contact support@brindledigital.com to start syncing again.";
}

function rfs_get_rentfetch_api_url( $route = 'data' ) {
	$url = defined( 'RENTFETCH_API_URL' ) ? constant( 'RENTFETCH_API_URL' ) : 'https://api.rentfetch.net/wp-json/rentfetchapi/v1/data';
	return preg_replace( '#/data/?$#', '', $url ) . '/' . ltrim( $route, '/' );
}

/** Remove the transient, in-request response, and persistent last-good fallback together. */
function rfs_purge_rentfetch_api_credentials() {
	rfs_clear_rentfetch_api_info_cache();
	delete_option( 'rentfetch_api_info_last_success' );
}

/** A lock also invalidates persistent and in-request credential fallbacks. */
function rfs_apply_sync_lock() {
	update_option( 'rfs_api_sync_locked', '1', false );
	update_option( 'rentfetch_options_data_sync', 'nosync' );
	rfs_purge_rentfetch_api_credentials();
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( array( 'rfs_do_sync', 'rfs_yardi_do_delete_orphans', 'rfs_entrata_do_delete_orphans', 'rfs_engrain_do_delete_orphans', 'rfs_accelerated_sync_step' ) as $hook ) {
			as_unschedule_all_actions( $hook );
		}
	}
	if ( function_exists( 'rfs_cancel_accelerated_sync' ) ) {
		$state = rfs_get_accelerated_sync_state();
		if ( isset( $state['status'] ) && 'running' === $state['status'] ) {
			rfs_cancel_accelerated_sync();
		}
	}
}

/** Only the API's specific lock response changes access; ordinary 403s do not. */
function rfs_receive_sync_lock( $response ) {
	if ( is_wp_error( $response ) || 403 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || 'rentfetch_sync_locked' !== ( $data['code'] ?? '' ) || true !== ( $data['data']['sync_locked'] ?? null ) ) {
		return false;
	}
	rfs_apply_sync_lock();
	return true;
}

/** All central API requests carry the same site identity and consume lock instructions. */
function rfs_rentfetch_api_post( $route, $parameters = array(), $timeout = 30 ) {
	if ( rfs_is_sync_locked() && 'sync-access' !== $route ) {
		return new WP_Error( 'rentfetch_sync_locked', rfs_sync_locked_message() );
	}
	// shortcut: URL identity controls standard clients; add verified identities if impersonation becomes a problem.
	$parameters['site_url'] = preg_replace( '#^(https?://)?(www\.)?([^/]+).*$#', '$3', untrailingslashit( get_site_url() ) );
	$response = wp_remote_post( rfs_get_rentfetch_api_url( $route ), array(
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body' => wp_json_encode( $parameters ),
		'timeout' => $timeout,
	) );
	rfs_receive_sync_lock( $response );
	return $response;
}

/** Verify access afresh for each direct-provider sync, before cached credentials can be used. */
function rfs_verify_sync_access_before_sync() {
	if ( rfs_is_sync_locked( true ) ) {
		return new WP_Error( 'rentfetch_sync_locked', rfs_sync_locked_message() );
	}
	$response = rfs_rentfetch_api_post( 'sync-access', array(), 15 );
	if ( rfs_is_sync_locked( true ) ) {
		return new WP_Error( 'rentfetch_sync_locked', rfs_sync_locked_message() );
	}
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 === $status && is_array( $data ) && false === ( $data['sync_locked'] ?? null ) ) {
		return true;
	}
	if ( ( 404 === $status && 'rest_no_route' === ( $data['code'] ?? '' ) ) || ( 403 === $status && 'rentfetch_site_unregistered' === ( $data['code'] ?? '' ) ) ) {
		// Older APIs have no access route; new sites need registration. Use a fresh bootstrap, never a cached fallback.
		$bootstrap = rfs_refresh_info_from_rentfetch_api();
		if ( rfs_is_sync_locked( true ) ) {
			return new WP_Error( 'rentfetch_sync_locked', rfs_sync_locked_message() );
		}
		if ( is_array( $bootstrap ) ) {
			return true;
		}
	}
	return new WP_Error( 'sync_access_unconfirmed', 'Sync access could not be confirmed. Try again later.' );
}

/** Unlocking requires an explicit, successful credential-free API response. */
function rfs_check_sync_access() {
	$response = rfs_rentfetch_api_post( 'sync-access' );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( rfs_is_sync_locked() && 403 === (int) wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'rentfetch_sync_locked', rfs_sync_locked_message() );
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || false !== ( $data['sync_locked'] ?? null ) ) {
		return new WP_Error( 'sync_access_unconfirmed', 'Sync access could not be confirmed. Try again later.' );
	}
	// Unlock access without resuming a site that was paused.
	update_option( 'rentfetch_options_data_sync', 'nosync' );
	delete_option( 'rfs_api_sync_locked' );
	rfs_clear_rentfetch_api_info_cache();
	return true;
}
