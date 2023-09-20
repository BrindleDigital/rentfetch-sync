<?php

function rfs_get_credentials() {
	$credentials = [];
	
	$enabled = get_option( 'options_enabled_integrations' );
		
	if ( in_array( 'yardi', $enabled ) ) {
		$credentials['yardi'] = [
			'apikey' => get_option( 'options_yardi_integration_creds_yardi_api_key' ),
			// 'user' => get_option( 'options_yardi_integration_creds_yardi_username' ),
			// 'password' => get_option( 'options_yardi_integration_creds_yardi_password' ),
		];
	}
		
	if ( in_array( 'entrata', $enabled ) ) {
		$credentials['entrata'] = [
			'user' => get_option( 'options_entrata_integration_creds_entrata_user' ),
			'password' => get_option( 'options_entrata_integration_creds_entrata_pass' ),
		];
	}
	
	if ( in_array( 'realpage', $enabled ) ) {
		$credentials['realpage'] = [
			'user' => get_option( 'options_realpage_integration_creds_realpage_user' ),
			'password' => get_option( 'options_realpage_integration_creds_realpage_pass' ),
			'pmc_id' => get_option( 'options_realpage_integration_creds_realpage_pmc_id' ),
		];
	}
	
	if ( in_array( 'appfolio', $enabled ) ) {
		$credentials['appfolio'] = [
			'database' => get_option( 'options_appfolio_integration_creds_appfolio_database_name' ),
			'client_id' => get_option( 'options_appfolio_integration_creds_appfolio_client_id' ),
			'client_secret' => get_option( 'options_appfolio_integration_creds_appfolio_client_secret' ),
		];
	}
	
	return $credentials;
}
