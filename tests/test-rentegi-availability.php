<?php
/** Standalone published-unit availability and diagnostic regression. */
define( 'ABSPATH', __DIR__ );
$published = array();
$saved = array();
$responses = array();
$meta = array(
	101 => array( 'unit_source' => 'rentegi', 'availability_date' => '12/10/2026' ),
	102 => array( 'unit_source' => '', 'availability_date' => '01/02/2027' ),
);
function rfs_assert_property_sync_lock( ...$args ) {}
function rentfetch_get_floorplan_unit_query_args( $post_id, $args ) {
	if ( isset( $args['meta_query'] ) || 7 !== $post_id ) { throw new RuntimeException( 'Core must own the source/manual attachment relation.' ); }
	return $args;
}
function get_posts( $args ) {
	if ( 'publish' !== $args['post_status'] || 'ids' !== $args['fields'] ) { throw new RuntimeException( 'Only published units determine availability.' ); }
	return $GLOBALS['published'];
}
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function rfs_rentegi_update_meta( $id, $key, $value ) { $GLOBALS['saved'][ $id ][ $key ] = $value; }
function rfs_rentegi_record_result( $id, $error, $payload ) {
	if ( null !== $error ) { throw new RuntimeException( 'Count disagreements must not fail syncing.' ); }
	$GLOBALS['responses'][ $id ] = $payload;
}
require dirname( __DIR__ ) . '/lib/api/rentegi/update-wp-floorplan-rentegi.php';
$inventory = array( 'property' => array( 'id' => 'PROPERTY' ), 'floorplans' => array( 'PLAN' => array(
	'id' => 'PLAN', 'meta' => array( 'available_units' => 99 ), 'source_context' => array( 'reported_available_units' => 99 ),
) ) );
rfs_rentegi_finalize_floorplan_availability( $inventory, array( 'PLAN' => 7 ) );
if ( 0 !== $saved[7]['available_units'] || '' !== $saved[7]['availability_date'] || 99 !== $responses[7]['source_context']['reported_available_units'] || ! $responses[7]['warnings'] ) {
	throw new RuntimeException( 'Zero published units must clear availability and retain reported-count warnings.' );
}
$published = array( 101, 102 );
rfs_rentegi_finalize_floorplan_availability( $inventory, array( 'PLAN' => 7 ) );
if ( 2 !== $saved[7]['available_units'] || '20261210' !== $saved[7]['availability_date'] || 1 !== $responses[7]['source_context']['published_manual_units'] || 1 !== $responses[7]['source_context']['published_provider_units'] ) {
	throw new RuntimeException( 'Published manual attachments and provider units must share public counts and earliest date.' );
}
echo "RentEGI availability passed: zero overrides provider count, manual attachments count, source diagnostics and earliest published date.\n";
