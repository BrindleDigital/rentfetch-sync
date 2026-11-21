<?php
/** Isolated API process for mixed-version tests; all provider traffic is mocked. */
if ( ! str_contains( ABSPATH, '/rentfetch-followup-test-' ) ) {
	throw new RuntimeException( 'Disposable site required.' );
}
define( 'RENTFETCH_API_LOCAL', true ); // Prevent the legacy Yardi bootstrap from using native cURL.
require_once ABSPATH . 'wp-content/plugins/rentfetch-api/rentfetch-api.php';
rentfetch_api_register_websites_cpt();
$input = json_decode( getenv( 'RF_MIXED_API_REQUEST' ), true );
if ( ! is_array( $input ) ) {
	throw new RuntimeException( 'API request fixture required.' );
}
add_filter( 'pre_option_rentfetchapi_rentegi_key', static function() { return 'fixture-key'; } );
add_filter( 'pre_option_rentfetchapi_rentegi_secret', static function() { return 'fixture-secret'; } );
add_filter( 'pre_http_request', static function( $pre, $args, $url ) {
	if ( 'https://hapi.ioaimi.com/api/auth/GetToken' === $url ) {
		$body = wp_json_encode( array( 'token' => 'fixture-bearer', 'expires_in_seconds' => 3600 ) );
	} elseif ( 'https://hapi.ioaimi.com/api/184AST/universalxml/getuniversalxml' === $url ) {
		$body = file_get_contents( __DIR__ . '/rentegi-mits.xml' );
	} else {
		return new WP_Error( 'mixed_provider_denied', 'Unexpected provider request blocked.' );
	}
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => $body );
}, PHP_INT_MAX, 3 );
$request = new WP_REST_Request( 'POST', $input['path'] );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body( $input['body'] );
$response = rest_do_request( $request );
$data = $response->get_data();
// The API's local-mode placeholder needs a synthetic expiry for real client validation.
if ( 'dummyaccesstoken' === ( $data['yardi']['access_token'] ?? null ) ) {
	$data['yardi']['access_token'] = 'fixture.' . rtrim( strtr( base64_encode( wp_json_encode( array( 'exp' => time() + 3600 ) ) ), '+/', '-_' ), '=' ) . '.fixture';
}
echo 'MIXED_API_RESULT=' . wp_json_encode( array( 'status' => $response->get_status(), 'data' => $data ) ) . PHP_EOL;
