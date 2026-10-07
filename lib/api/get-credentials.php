<?php

function rfs_get_credentials() {
	$credentials = [];

	$enabled = get_option( 'rentfetch_options_enabled_integrations' );
	if ( ! is_array( $enabled ) ) {
		$enabled = [];
	}

	if ( in_array( 'yardi', $enabled, true ) ) {
		$credentials['yardi'] = [
			'apikey' => get_option( 'rentfetch_options_yardi_integration_creds_yardi_api_key' ),
			'company_code' => get_option( 'rentfetch_options_yardi_integration_creds_yardi_company_code' ),
			'vendor' => 'marketing@brindledigital.com',
			// 'user' => get_option( 'rentfetch_options_yardi_integration_creds_yardi_username' ),
			// 'password' => get_option( 'rentfetch_options_yardi_integration_creds_yardi_password' ),
		];
	}

	if ( in_array( 'engrain', $enabled, true ) ) {
		$credentials['engrain'] = [
			'api_key' => rfs_get_engrain_api_key(),
		];
	}

	if ( in_array( 'entrata', $enabled, true ) ) {
		$credentials['entrata'] = [
			'subdomain' => get_option( 'rentfetch_options_entrata_integration_creds_entrata_subdomain' ),
			// the Entrata API key will come from the Rent Fetch API, and therefore is not stored in the WP options or available in the $credentials array.
		];
	}

	if ( in_array( 'appfolio', $enabled, true ) ) {
		$credentials['appfolio'] = [
			'database' => get_option( 'rentfetch_options_appfolio_integration_creds_appfolio_database_name' ),
			'client_id' => get_option( 'rentfetch_options_appfolio_integration_creds_appfolio_client_id' ),
			'client_secret' => get_option( 'rentfetch_options_appfolio_integration_creds_appfolio_client_secret' ),
		];
	}

	if ( in_array( 'rentmanager', $enabled, true ) ) {
		// Keep site registration and monitoring bootstrap updates; the partner token stays on the API.
		rfs_get_info_from_rentfetch_api();
		$credentials['rentmanager'] = [
			'companycode' => get_option( 'rentfetch_options_rentmanager_integration_creds_rentmanager_companycode' ),
		];
	}
	
	return $credentials;
}
