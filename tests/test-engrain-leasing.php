<?php
/**
 * WP-CLI eval-file tests/test-engrain-leasing.php --skip-plugins --skip-themes.
 * All HTTP is mocked; the temporary status-test property is removed afterward.
 *
 * @package rentfetch-sync
 */

$root = dirname( __DIR__ );
require_once $root . '/lib/api/engrain/api-client-engrain.php';
require_once $root . '/lib/api/engrain/update-wp-engrain.php';
require_once $root . '/lib/api/engrain/sync-helpers-engrain.php';
require_once $root . '/lib/initialization/sync-status.php';

function rfs_leasing_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$args = array(
	'property_id'                => '38202',
	'wordpress_property_post_id' => 0,
	'credentials'                => array( 'engrain' => array( 'api_key' => 'fixture-key' ) ),
);
$prefix = '/v1/assets/38202/multifamily/pricing';
$units = array(
	array( 'id' => '1' ),
	array( 'id' => '2', 'show_online_leasing' => false ),
	array( 'id' => '3' ),
	array( 'id' => '5' ),
	array( 'id' => '6' ),
);
$entries = array(
	array( 'unit_id' => '1', 'leasing_fields' => array( 'apply_url' => 'https://example.com/old' ) ),
	array( 'unit_id' => '2', 'leasing_fields' => array( 'apply_url' => 'https://example.com/disabled' ) ),
	array( 'unit_id' => '3', 'leasing_fields' => array( 'application_url' => 'https://example.com/appfolio' ) ),
	array( 'unit_id' => '6', 'show_online_leasing' => false, 'leasing_fields' => array( 'application_url' => 'https://example.com/disabled' ) ),
);
$leasing = array(
	array( 'asset_id' => '38202', 'unit_id' => '1', 'apply_url' => 'https://example.com/compiled' ),
	array( 'asset_id' => '38202', 'unit_id' => '2', 'apply_url' => 'https://example.com/disabled' ),
	array( 'asset_id' => '38202', 'unit_id' => '4', 'apply_url' => 'https://example.com/unavailable' ),
);
$fixtures = array(
	$prefix                  => array( 200, array( 'data' => array( array( 'id' => '40305', 'tags' => array( 'primary' ) ) ) ) ),
	$prefix . '/40305/units'   => array( 200, array( 'data' => $units ) ),
	$prefix . '/40305/entries' => array( 200, array( 'data' => $entries ) ),
	$prefix . '/40305/leasing' => array( 200, array( 'data' => $leasing ) ),
);
$requests = array();
$status_post_id = 0;
$fetch_contexts = function () use ( $args ) {
	$contexts = rfs_engrain_get_pricing_contexts_for_sync( $args, $response );
	return rfs_engrain_merge_unit_leasing_contexts( $contexts, $response );
};
$mock_http = function ( $pre, $request, $url ) use ( &$fixtures, &$requests ) {
	$path = wp_parse_url( $url, PHP_URL_PATH );
	parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
	$key = $path . ( isset( $query['page'] ) ? '?page=' . $query['page'] : '' );
	rfs_leasing_check( isset( $fixtures[ $key ] ), 'Unexpected HTTP request: ' . $url );
	$requests[] = array( 'url' => $url, 'args' => $request );
	return array( 'response' => array( 'code' => $fixtures[ $key ][0] ), 'body' => wp_json_encode( $fixtures[ $key ][1] ), 'headers' => array(), 'cookies' => array() );
};
add_filter( 'pre_http_request', $mock_http, PHP_INT_MAX, 3 );

try {
	$contexts = $fetch_contexts();
	rfs_leasing_check( 'https://example.com/compiled' === $contexts['1']['apply_online_url'], 'Compiled leasing URL must take precedence.' );
	rfs_leasing_check( '' === $contexts['2']['apply_online_url'] && '' === $contexts['6']['apply_online_url'], 'Explicit unit and entry disablement must be respected.' );
	rfs_leasing_check( '' === $contexts['3']['apply_online_url'] && $contexts['3']['apply_online_url_authoritative'], 'A complete response must clear links for omitted units.' );
	rfs_leasing_check( ! $contexts['4']['is_available'], 'Leasing URLs must not establish unit availability.' );
	$headers = $requests[3]['args']['headers'];
	rfs_leasing_check( 'online-leasing-resource' === $headers['Experimental-Flags'] && 'fixture-key' === $headers['API-Key'], 'Leasing requests must include the flag and runtime API key.' );

	$fixtures[ $prefix . '/40305/leasing' ] = array( 403, array( 'message' => 'Access denied' ) );
	$contexts = $fetch_contexts();
	rfs_leasing_check( 'https://example.com/old' === $contexts['1']['apply_online_url'] && $contexts['1']['apply_online_url_authoritative'], 'Denied leasing requests must use legacy apply_url.' );
	rfs_leasing_check( 'https://example.com/appfolio' === $contexts['3']['apply_online_url'] && $contexts['3']['apply_online_url_authoritative'], 'Denied leasing requests must use AppFolio application_url.' );
	rfs_leasing_check( ! $contexts['5']['apply_online_url_authoritative'], 'Failures without a fallback must preserve saved links.' );
	rfs_leasing_check( '' === $contexts['6']['apply_online_url'] && $contexts['6']['apply_online_url_authoritative'], 'Explicit disablement must still clear saved links during an outage.' );

	$fixtures[ $prefix . '/40305/entries' ] = array( 500, array() );
	$contexts = $fetch_contexts();
	rfs_leasing_check( ! $contexts['1']['apply_online_url_authoritative'], 'When both URL sources fail, saved links must be preserved.' );
	$fixtures[ $prefix . '/40305/leasing' ] = array( 200, array( 'data' => $leasing ) );
	$contexts = $fetch_contexts();
	rfs_leasing_check( 'https://example.com/compiled' === $contexts['1']['apply_online_url'], 'Leasing URLs must work independently of Pricing Entries.' );

	$fixtures[ $prefix . '/40305/units' ] = array( 500, array() );
	$fixtures[ $prefix . '/40305/entries' ] = array( 200, array( 'data' => $entries ) );
	$fixtures[ $prefix . '/40305/leasing' ] = array( 403, array() );
	$contexts = $fetch_contexts();
	rfs_leasing_check( 'https://example.com/appfolio' === $contexts['3']['apply_online_url'], 'Entry fallbacks must remain usable when Pricing Units fails.' );
	$fixtures[ $prefix . '/40305/entries' ] = array( 500, array() );
	$fixtures[ $prefix . '/40305/leasing' ] = array( 200, array( 'data' => $leasing ) );
	$contexts = rfs_engrain_get_pricing_contexts_for_sync( $args, $leasing_response );
	$contexts = rfs_engrain_merge_all_in_pricing_contexts( $contexts, array( array( 'unit_id' => '1', 'available_on' => '2099-01-01', 'base_price' => '1000' ) ) );
	$contexts = rfs_engrain_merge_unit_leasing_contexts( $contexts, $leasing_response );
	rfs_leasing_check( $contexts['1']['is_available'] && 'https://example.com/compiled' === $contexts['1']['apply_online_url'] && ! $contexts['4']['is_available'], 'All-In Pricing must remain the independent availability authority.' );
	rfs_leasing_check( null === rfs_engrain_merge_unit_leasing_contexts( null, $leasing_response ), 'Failed All-In Pricing must preserve the null inventory guard, even with successful leasing data.' );

	// Apply leasing after All-In Pricing so newly available units also clear stale links.
	$contexts = rfs_engrain_merge_all_in_pricing_contexts( null, array( array( 'unit_id' => '8', 'available_on' => '2099-01-01' ) ) );
	$contexts = rfs_engrain_merge_unit_leasing_contexts( $contexts, array( 'success' => true, 'data' => $leasing ) );
	rfs_leasing_check( '' === $contexts['8']['apply_online_url'] && $contexts['8']['apply_online_url_authoritative'], 'Units known only to All-In Pricing must respect successful leasing omissions.' );

	foreach ( array( array( 'asset_id' => 'other', 'unit_id' => '1', 'apply_url' => 'https://example.com/wrong' ), array( 'asset_id' => '38202', 'unit_id' => '1', 'apply_url' => 'javascript:alert(1)' ), array( 'asset_id' => '38202', 'apply_url' => 'https://example.com/missing-id' ) ) as $invalid_row ) {
		$fixtures[ $prefix . '/40305/leasing' ] = array( 200, array( 'data' => array( $invalid_row ) ) );
		rfs_leasing_check( ! rfs_engrain_get_online_leasing_urls( $args, '40305' )['success'], 'Malformed leasing data must not become authoritative.' );
	}

	$fixtures[ $prefix . '/40305/leasing' ] = array( 200, array( 'data' => $leasing, 'paging' => array( 'next_url' => 'https://api.sightmap.com' . $prefix . '/40305/leasing?page=2' ) ) );
	$fixtures[ $prefix . '/40305/leasing?page=2' ] = array( 500, array() );
	rfs_leasing_check( ! rfs_engrain_get_online_leasing_urls( $args, '40305' )['success'], 'A failed later page must not authorize partial leasing data.' );
	$fixtures[ $prefix . '/40305/leasing?page=2' ] = array( 200, array( 'data' => array( array( 'asset_id' => '38202', 'unit_id' => '7', 'apply_url' => 'https://example.com/page-two' ) ) ) );
	rfs_leasing_check( 4 === count( rfs_engrain_get_online_leasing_urls( $args, '40305' )['data'] ), 'Every leasing page must be included.' );

	$fixtures[ $prefix . '/40305/leasing' ] = array( 200, array( 'data' => array() ) );
	rfs_leasing_check( rfs_engrain_get_online_leasing_urls( $args, '40305' )['success'], 'An empty leasing list is authoritative.' );
	$contexts = rfs_engrain_merge_unit_leasing_contexts( array( '1' => array( 'apply_online_url' => 'https://example.com/old' ) ), array( 'success' => true, 'data' => array() ) );
	rfs_leasing_check( '' === $contexts['1']['apply_online_url'] && $contexts['1']['apply_online_url_authoritative'], 'Authoritative empty lists must clear stale URLs.' );

	register_post_type( 'properties' );
	$status_post_id = wp_insert_post( array( 'post_type' => 'properties', 'post_status' => 'draft', 'post_title' => 'Temporary leasing status test', 'meta_input' => array( 'property_source' => 'engrain' ) ), true );
	rfs_leasing_check( ! is_wp_error( $status_post_id ) && $status_post_id > 0, 'The temporary status-test property must be created.' );
	rfs_engrain_record_api_response( $status_post_id, 'engrain_leasing_api', rfs_engrain_failed_response( 'access_denied', 403 ), null, true );
	$status = get_post_meta( $status_post_id, 'sync_status', true );
	rfs_leasing_check( 'partial' === ( $status['engrain_leasing_api']['state'] ?? '' ) && 'partial' === get_post_meta( $status_post_id, 'last_sync_state', true ), 'A leasing failure must appear in the property sync status as partial.' );
	rfs_engrain_record_api_response( $status_post_id, 'engrain_leasing_api', array( 'success' => true, 'status_code' => 200, 'reason' => 'valid_data_array' ), null, true );
	$status = get_post_meta( $status_post_id, 'sync_status', true );
	rfs_leasing_check( 'success' === ( $status['engrain_leasing_api']['state'] ?? '' ) && 'success' === get_post_meta( $status_post_id, 'last_sync_state', true ), 'A successful leasing response must clear the partial status.' );
} finally {
	remove_filter( 'pre_http_request', $mock_http, PHP_INT_MAX );
	if ( is_int( $status_post_id ) && $status_post_id > 0 ) {
		wp_delete_post( $status_post_id, true );
	}
}

echo "Engrain leasing tests passed: precedence, fallbacks, failures, disablement, pagination, validation, and independent availability.\n";
