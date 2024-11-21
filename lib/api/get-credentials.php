<?php

function rfs_get_credentials() {
	$credentials = [];
	
	$enabled = get_option( 'rentfetch_options_enabled_integrations' );
	if ( $enabled == false ) {
		$enabled = [];
	}
			
	if ( in_array( 'yardi', $enabled ) ) {
		$credentials['yardi'] = [
			'apikey' => get_option( 'rentfetch_options_yardi_integration_creds_yardi_api_key' ),
			'company_code' => get_option( 'rentfetch_options_yardi_integration_creds_yardi_company_code' ),
			'vendor' => 'marketing@brindledigital.com',
			// 'user' => get_option( 'rentfetch_options_yardi_integration_creds_yardi_username' ),
			// 'password' => get_option( 'rentfetch_options_yardi_integration_creds_yardi_password' ),
		];
	}
		
	if ( in_array( 'entrata', $enabled ) ) {
		$credentials['entrata'] = [
			'user' => get_option( 'rentfetch_options_entrata_integration_creds_entrata_user' ),
			'password' => get_option( 'rentfetch_options_entrata_integration_creds_entrata_pass' ),
		];
	}
	
	if ( in_array( 'realpage', $enabled ) ) {
		$credentials['realpage'] = [
			'user' => get_option( 'rentfetch_options_realpage_integration_creds_realpage_user' ),
			'password' => get_option( 'rentfetch_options_realpage_integration_creds_realpage_pass' ),
			'pmc_id' => get_option( 'rentfetch_options_realpage_integration_creds_realpage_pmc_id' ),
		];
	}
	
	if ( in_array( 'appfolio', $enabled ) ) {
		$credentials['appfolio'] = [
			'database' => get_option( 'rentfetch_options_appfolio_integration_creds_appfolio_database_name' ),
			'client_id' => get_option( 'rentfetch_options_appfolio_integration_creds_appfolio_client_id' ),
			'client_secret' => get_option( 'rentfetch_options_appfolio_integration_creds_appfolio_client_secret' ),
		];
	}
	
	if ( in_array( 'rentmanager', $enabled ) ) {
		$credentials['rentmanager'] = [
			'companycode' => get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_companycode' ),
			'partner_token' => rfs_get_rentmanager_partner_token(),
		];
	}
	
	return $credentials;
}
