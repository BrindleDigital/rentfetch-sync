<?php
/** Standalone exact-identity lookup regression; no WordPress storage mutations. */
define( 'ABSPATH', __DIR__ );
$queries = array();
$matches = array();
function rfs_assert_property_sync_lock( ...$args ) {}
function get_posts( $args ) {
	$GLOBALS['queries'][] = $args;
	if ( 2 !== $args['posts_per_page'] || 'ids' !== $args['fields'] || array( 'publish', 'draft', 'private' ) !== $args['post_status'] ) {
		throw new RuntimeException( 'Identity matching must be bounded and recover drafts/private records.' );
	}
	$filters = array_column( $args['meta_query'], 'value', 'key' );
	if ( array( 'unit_source' => 'rentegi', 'property_id' => 'PROPERTY', 'unit_id' => 'UNIT' ) !== $filters ) {
		throw new RuntimeException( 'Lookup must filter the exact source/property/unit identity in its query.' );
	}
	return $GLOBALS['matches'];
}
function get_post_meta( ...$args ) { throw new RuntimeException( 'Matching must not scan every saved record ID in PHP.' ); }
require dirname( __DIR__ ) . '/lib/api/rentegi/update-wp-rentegi.php';
$record = array( 'id' => 'UNIT', 'name' => 'Unit' );
if ( array() !== rfs_rentegi_existing_posts( 'units', 'unit', 'PROPERTY', 'UNIT' ) || 1 !== count( $queries ) ) {
	throw new RuntimeException( 'Identity lookup requires one bounded query and no metadata scan.' );
}
$matches = array( 42, 43 );
$failed = false;
try { rfs_rentegi_upsert( 'units', 'unit', 'PROPERTY', $record ); } catch ( RuntimeException $error ) {
	$failed = str_contains( $error->getMessage(), 'Duplicate RentEGI records' );
}
if ( ! $failed ) { throw new RuntimeException( 'Two matching records must retain duplicate protection.' ); }
echo "RentEGI identity lookup passed: exact bounded query, draft/private coverage, no full-record scan, duplicate rejection.\n";
