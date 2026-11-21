<?php
/** Local disposable content regressions: wp --skip-plugins --skip-themes eval-file <this-file>. */
require_once dirname( __DIR__, 2 ) . '/rentfetch/rentfetch.php';
require_once dirname( __DIR__ ) . '/rentfetch-sync.php';
rfs_boot_plugin();
rentfetch_register_properties_cpt(); rentfetch_register_floorplans_cpt(); rentfetch_register_units_cpt();
$fixture_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $fixture_admins ) { throw new RuntimeException( 'A disposable-site administrator is required for fixture writes.' ); }
wp_set_current_user( $fixture_admins[0] );
function rentegi_check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$code = 'RFS_RENTEGI_TEST_' . wp_generate_password( 6, false );
$xml = str_replace( '184AST', $code, file_get_contents( __DIR__ . '/fixtures/rentegi-mits.xml' ) );
$inventory = rfs_rentegi_parse_feed( $xml, $code );
$get_ids = static function( $type, $singular ) use ( $code ) { return rfs_rentegi_existing_posts( $type, $singular, $code ); };
$property_codes = static function() use ( $code ) { return $code; };
add_filter( 'pre_option_rentfetch_options_rentegi_property_codes', $property_codes );
$bad_http = static function() { return array( 'response' => array( 'code' => 502 ), 'headers' => array(), 'body' => '{"message":"RentEGI feed returned HTTP 500.","data":{"provider_status":500}}' ); };
$run_sync = static function( $feed_xml ) use ( $code ) {
	$http = static function() use ( $feed_xml ) {
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'xml' => $feed_xml ) ) );
	};
	add_filter( 'pre_http_request', $http );
	try {
		rfs_do_rentegi_sync( array( 'property_id' => $code ) );
	} finally {
		remove_filter( 'pre_http_request', $http );
	}
};
try {
	$enabled_filter = static function() { return array( 'rentegi' ); };
	$requests = array();
	$good_http = static function( $pre, $args, $url ) use ( $xml, &$requests ) {
		$requests[] = $url;
		$body = str_ends_with( $url, '/sync-access' ) ? array( 'sync_locked' => false ) : array( 'xml' => $xml );
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
	};
	add_filter( 'pre_option_rentfetch_options_enabled_integrations', $enabled_filter );
	add_filter( 'pre_http_request', $good_http, 10, 3 );
	try {
		rfs_trigger_specific_api_sync( array( 'property_id' => $code, 'integration' => 'rentegi' ) );
		rentegi_check( 2 === count( $requests ) && str_ends_with( $requests[0], '/sync-access' ) && str_ends_with( $requests[1], '/rentegi/feed' ), 'Shared dispatcher verifies access then fetches the feed without client credentials.' );
		$manifest = rfs_get_accelerated_sync_manifest();
		rentegi_check( 2 === count( $manifest ) && 'cleanup' === $manifest[0]['type'] && $code === $manifest[1]['property_id'], 'Accelerated sync includes cleanup and configured RentEGI properties.' );
	} finally {
		remove_filter( 'pre_http_request', $good_http, 10 );
		remove_filter( 'pre_option_rentfetch_options_enabled_integrations', $enabled_filter );
	}
	$properties = $get_ids( 'properties', 'property' ); $plans = $get_ids( 'floorplans', 'floorplan' ); $units = $get_ids( 'units', 'unit' );
	rentegi_check( 1 === count( $properties ) && 3 === count( $plans ) && 2 === count( $units ), 'Only offered units should be published.' );
	$property = $properties[0]; $plan = $plans[0];
	wp_update_post( array( 'ID' => $property, 'post_content' => 'Manual WordPress body', 'post_date' => '2020-01-02 03:04:05' ) );
	update_post_meta( $property, 'latitude', '47.123' ); update_post_meta( $property, 'description', 'Manual description' );
	update_post_meta( $plan, 'floorplan_image_url', 'https://example.invalid/manual.png' );
	update_post_meta( $plan, 'has_specials', '1' );
	update_post_meta( $plan, 'specials_content', 'Manual special must survive syncing.' );
	$run_sync( $xml );
	rentegi_check( $units === $get_ids( 'units', 'unit' ) && $plans === $get_ids( 'floorplans', 'floorplan' ), 'Repeated sync must reuse record IDs.' );
	rentegi_check( '47.123' === get_post_meta( $property, 'latitude', true ) && 'Manual description' === get_post_meta( $property, 'description', true ), 'Manual property fields preserved.' );
	rentegi_check( 'Manual WordPress body' === get_post( $property )->post_content && '2020-01-02 03:04:05' === get_post( $property )->post_date, 'Existing WordPress content and original post dates must survive syncing.' );
	rentegi_check( 'https://example.invalid/manual.png' === get_post_meta( $plan, 'floorplan_image_url', true ), 'Manual floor plan image preserved.' );
	rentegi_check( '1' === get_post_meta( $plan, 'has_specials', true ) && 'Manual special must survive syncing.' === get_post_meta( $plan, 'specials_content', true ), 'Manual specials remain untouched.' );
	foreach ( $units as $unit_id ) {
		$payload = json_decode( get_post_meta( $unit_id, 'api_response', true )['rentegi_feed_api']['api_response'], true );
		rentegi_check( isset( $payload['source_context']['lease_status'], $payload['source_context']['occupancy_status'], $payload['source_context']['charges'] ), 'Unit diagnostics preserve selected source status and charge context.' );
		rentegi_check( ! metadata_exists( 'post', $unit_id, 'source_context' ), 'Diagnostic context must not be saved as public metadata.' );
	}
	foreach ( $units as $unit ) { rentegi_check( metadata_exists( 'post', $unit, 'availability_date' ), 'Undated units must remain queryable in public tables.' ); }
	$public_units = get_posts( array( 'post_type' => 'units', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => 'availability_date', 'meta_query' => array( array( 'key' => 'property_id', 'value' => $code ) ) ) );
	rentegi_check( 2 === count( $public_units ), 'The public unit-table query must include offered units without dates.' );

	$fields = apply_filters( 'rentfetch_filter_property_syncing_fields', array(), $property );
	rentegi_check( in_array( 'phone', $fields, true ) && ! in_array( 'latitude', $fields, true ) && ! in_array( 'description', $fields, true ), 'Core field editing matches feed coverage.' );
	add_filter( 'pre_http_request', $bad_http );
	$failed = false;
	try { rfs_do_rentegi_sync( array( 'property_id' => $code ) ); } catch ( RuntimeException $e ) { $failed = true; }
	remove_filter( 'pre_http_request', $bad_http );
	rentegi_check( $failed && $units === $get_ids( 'units', 'unit' ), 'HTTP failure preserves existing inventory.' );
	rentegi_check( 'failed' === get_post_meta( $property, 'last_sync_state', true ), 'Failure recorded in monitoring.' );
	$bad_http_xml = static function() { return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'xml' => '<PhysicalProperty />' ) ) ); };
	add_filter( 'pre_http_request', $bad_http_xml );
	$failed = false;
	try { rfs_do_rentegi_sync( array( 'property_id' => $code ) ); } catch ( RuntimeException $e ) { $failed = true; }
	remove_filter( 'pre_http_request', $bad_http_xml );
	rentegi_check( $failed && $plans === $get_ids( 'floorplans', 'floorplan' ), 'Malformed inventory cannot retire floor plans.' );
	// A child metadata failure must fail the property and skip retirement.
	$run_sync( $xml );
	$stale_unit = wp_insert_post( array( 'post_type' => 'units', 'post_title' => 'Stale fixture unit', 'post_status' => 'publish' ) );
	foreach ( array( 'unit_source' => 'rentegi', 'property_id' => $code, 'unit_id' => 'STALE_FIXTURE' ) as $key => $value ) {
		update_post_meta( $stale_unit, $key, $value );
	}
	$changed_price = str_replace( '<Amounts>1595.00</Amounts>', '<Amounts>1600.00</Amounts>', $xml );
	$blocked_meta = static function( $check, $post_id, $key ) {
		return 'minimum_rent' === $key && 'units' === get_post_type( $post_id ) ? false : $check;
	};
	add_filter( 'update_post_metadata', $blocked_meta, 10, 3 );
	$failed = false;
	try { $run_sync( $changed_price ); } catch ( RuntimeException $e ) { $failed = true; }
	remove_filter( 'update_post_metadata', $blocked_meta, 10 );
	rentegi_check( $failed && 'failed' === get_post_meta( $property, 'last_sync_state', true ), 'A child metadata failure fails the property.' );
	rentegi_check( 'publish' === get_post_status( $stale_unit ), 'Failed saves must skip missing-record retirement.' );
	wp_delete_post( $stale_unit, true );
	// An unavailable unit early in the feed must survive if a later offered-unit save fails.
	$failed_deletion_feed = simplexml_load_string( $xml );
	$failed_deletion_feed->xpath( '//ILS_Unit[@IDValue="D06"]/Units/Unit/UnitLeasedStatus' )[0][0] = 'leased';
	foreach ( $units as $fixture_unit ) { update_post_meta( $fixture_unit, 'minimum_rent', '1' ); }
	add_filter( 'update_post_metadata', $blocked_meta, 10, 3 );
	$failed = false;
	try { $run_sync( $failed_deletion_feed->asXML() ); } catch ( RuntimeException $e ) { $failed = true; }
	remove_filter( 'update_post_metadata', $blocked_meta, 10 );
	rentegi_check( $failed && $units === $get_ids( 'units', 'unit' ), 'A later save failure must skip deletion of earlier unavailable units.' );

	$lock = rfs_acquire_property_sync_lock( 'rentegi', $code );
	add_filter( 'update_post_metadata', $blocked_meta, 10, 3 );
	$failed = false;
	try {
		rfs_rentegi_upsert( 'units', 'unit', $code, array( 'id' => 'FAILED_FIXTURE', 'name' => 'Failed fixture ' . $code, 'meta' => array( 'minimum_rent' => 123 ) ) );
	} catch ( RuntimeException $e ) {
		$failed = true;
	} finally {
		remove_filter( 'update_post_metadata', $blocked_meta, 10 );
		rfs_release_property_sync_lock( $lock );
	}
	$failed_posts = get_posts( array( 'post_type' => 'units', 'post_status' => 'any', 'title' => 'Failed fixture ' . $code, 'fields' => 'ids' ) );
	rentegi_check( $failed && ! $failed_posts, 'A failed new record must not leave an unidentifiable duplicate behind.' );
	$duplicate = wp_insert_post( array( 'post_type' => 'units', 'post_title' => 'Private duplicate fixture', 'post_status' => 'private', 'meta_input' => array( 'unit_source' => 'rentegi', 'property_id' => $code, 'unit_id' => 'D06', 'floorplan_id' => 'Rainier' ) ) );
	$failed = false;
	try { $run_sync( $xml ); } catch ( RuntimeException $e ) { $failed = str_contains( $e->getMessage(), 'Duplicate RentEGI records' ); }
	wp_delete_post( $duplicate, true );
	rentegi_check( $failed, 'Bounded identity lookup must detect an existing private duplicate.' );
	$run_sync( $xml );
	rentegi_check( 'success' === get_post_meta( $property, 'last_sync_state', true ), 'Retry succeeds after a failed write and releases the execution lock.' );
	$moved_feed = simplexml_load_string( $xml );
	$moved_unit = $moved_feed->xpath( '//ILS_Unit[@IDValue="D06"]/Units/Unit/Identification[@IDType="FloorplanID"]' )[0];
	$moved_unit['IDValue'] = 'Adams';
	$run_sync( $moved_feed->asXML() );
	rentegi_check( $units === $get_ids( 'units', 'unit' ), 'A unit changing floor plans must retain its identity instead of creating a duplicate.' );
	$run_sync( $xml );

	// Clearing a provider date must remove the old unit and floor plan dates.
	$undated_feed = simplexml_load_string( $xml );
	foreach ( $undated_feed->Property->ILS_Unit as $entry ) {
		unset( $entry->Availability->MadeReadyDate );
	}
	$run_sync( $undated_feed->asXML() );
	foreach ( array_merge( $units, $plans ) as $post_id ) {
		rentegi_check( '' === get_post_meta( $post_id, 'availability_date', true ), 'Withdrawn provider dates must be cleared.' );
	}
	$run_sync( $xml );
	$leased_feed = simplexml_load_string( $xml );
	$legacy_draft = wp_insert_post( array( 'post_type' => 'units', 'post_title' => 'Legacy leased fixture', 'post_status' => 'draft', 'meta_input' => array( 'unit_source' => 'rentegi', 'property_id' => $code, 'unit_id' => 'A01', 'floorplan_id' => 'Rainier' ) ) );
	foreach ( $leased_feed->Property->ILS_Unit as $entry ) {
		$entry->Units->Unit->UnitLeasedStatus = 'leased';
	}
	$run_sync( $leased_feed->asXML() );
	rentegi_check( ! $get_ids( 'units', 'unit' ) && ! get_post( $legacy_draft ), 'Unavailable provider units and retained legacy drafts must be deleted.' );
	rentegi_check( $plans === $get_ids( 'floorplans', 'floorplan' ), 'A configured floor plan remains even with zero offered units.' );
	foreach ( $plans as $post_id ) {
		rentegi_check( '' === get_post_meta( $post_id, 'availability_date', true ), 'Floor plans with no offered units must clear their dates.' );
		rentegi_check( '0' === get_post_meta( $post_id, 'available_units', true ), 'Zero published units must override positive provider availability.' );
		$payload = json_decode( get_post_meta( $post_id, 'api_response', true )['rentegi_feed_api']['api_response'], true );
		$plan_id = get_post_meta( $post_id, 'floorplan_id', true );
		rentegi_check( $inventory['floorplans'][ $plan_id ]['meta']['available_units'] === $payload['source_context']['reported_available_units'], 'Reported provider availability is retained separately.' );
		if ( $payload['source_context']['reported_available_units'] ) {
			rentegi_check( ! empty( $payload['warnings'] ), 'Availability disagreements are visible in diagnostics.' );
		}
	}
	rentegi_check( 'success' === get_post_meta( $property, 'last_sync_state', true ), 'Provider aggregate conflicts must not fail an otherwise complete successful sync.' );
	if ( function_exists( 'rentfetch_get_floorplan_unit_query_args' ) ) {
		$rainier = rfs_rentegi_existing_posts( 'floorplans', 'floorplan', $code, 'Rainier' )[0];
		$manual_unit = wp_insert_post( array( 'post_type' => 'units', 'post_title' => 'Manual attached fixture', 'post_status' => 'publish', 'meta_input' => array( 'unit_id' => 'MANUAL_FIXTURE', 'property_id' => $code, 'floorplan_id' => 'Rainier', 'rentfetch_floorplan_post_id' => $rainier ) ) );
		try {
			$run_sync( $leased_feed->asXML() );
			rentegi_check( 'publish' === get_post_status( $manual_unit ) && ! $get_ids( 'units', 'unit' ), 'Manual attachments survive provider-unit deletion and missing-inventory cleanup.' );
			rentegi_check( '1' === get_post_meta( $rainier, 'available_units', true ), 'Published manual attachments count toward actual public availability, including without a date row.' );
			$payload = json_decode( get_post_meta( $rainier, 'api_response', true )['rentegi_feed_api']['api_response'], true );
			rentegi_check( 1 === $payload['source_context']['published_manual_units'] && 0 === $payload['source_context']['published_provider_units'], 'Diagnostics distinguish manual availability from provider availability.' );
		} finally {
			wp_delete_post( $manual_unit, true );
		}
	}
	$run_sync( $xml );
	$units = $get_ids( 'units', 'unit' );

	$offered = array_keys( array_filter( $inventory['units'], static function( $u ) { return $u['available']; } ) );
	$changed_feed = simplexml_load_string( $xml );
	$offered_unit = $changed_feed->xpath( '//ILS_Unit[@IDValue="' . $offered[0] . '"]' )[0];
	$offered_unit->Units->Unit->UnitLeasedStatus = 'leased';
	$run_sync( $changed_feed->asXML() );
	$remaining = $get_ids( 'units', 'unit' );
	rentegi_check( 1 === count( $remaining ), 'An unavailable provider unit must be deleted.' );
	$deleted = array_values( array_diff( $units, $remaining ) )[0];
	rentegi_check( ! get_post( $deleted ), 'Deletion is permanent, matching other integrations.' );
	$run_sync( $xml );
	$units = $get_ids( 'units', 'unit' );
	rentegi_check( 2 === count( $units ) && ! in_array( $deleted, $units, true ), 'A returning offered unit is imported again after deletion.' );
	unset( $offered_unit[0] );
	$changed_feed->Property->Information->UnitCount = 2;
	$run_sync( $changed_feed->asXML() );
	rentegi_check( 1 === count( $get_ids( 'units', 'unit' ) ), 'Units missing from complete inventory are deleted.' );
	$missing_plan_feed = simplexml_load_string( $xml );
	$missing_plan = $missing_plan_feed->xpath( '//Floorplan[@IDValue="Baker"]' )[0];
	unset( $missing_plan[0] );
	$run_sync( $missing_plan_feed->asXML() );
	rentegi_check( 2 === count( $get_ids( 'floorplans', 'floorplan' ) ), 'A provider floor plan absent from complete inventory is deleted.' );
	echo "RentEGI WordPress regressions passed: identities, manual fields/specials, checked writes, source diagnostics, derived zero availability, unavailable/missing deletion and reimport.\n";
} finally {
	remove_filter( 'pre_http_request', $bad_http );
	remove_filter( 'pre_option_rentfetch_options_rentegi_property_codes', $property_codes );
	foreach ( array( 'units' => 'unit', 'floorplans' => 'floorplan', 'properties' => 'property' ) as $type => $singular ) {
		foreach ( $get_ids( $type, $singular ) as $id ) { wp_delete_post( $id, true ); }
	}
}
