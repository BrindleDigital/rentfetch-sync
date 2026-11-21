<?php
/** Standalone parser regressions: php tests/test-rentegi-parser.php [optional-full-feed.xml]. */
define( 'ABSPATH', __DIR__ );
define( 'MB_IN_BYTES', 1048576 );
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
require_once dirname( __DIR__ ) . '/lib/api/rentegi/parse-rentegi.php';
$xml = file_get_contents( __DIR__ . '/fixtures/rentegi-mits.xml' );
$data = rfs_rentegi_parse_feed( $xml, '184AST' );
check( 3 === count( $data['floorplans'] ) && 3 === count( $data['units'] ), 'Inventory shape.' );
check( 'Fife' === $data['property']['meta']['city'], 'Property mapping.' );
check( 840 === $data['floorplans']['Adams']['meta']['minimum_sqft'], 'Lowercase range attributes.' );
check( 1.5 === $data['floorplans']['Baker']['meta']['baths'], 'Fractional baths.' );
check( 500.0 === $data['floorplans']['Adams']['meta']['minimum_deposit'], 'Floor plan deposit.' );
check( ! isset( $data['property']['meta']['latitude'], $data['floorplans']['Adams']['meta']['floorplan_image_url'] ), 'No fabricated media or coordinates.' );
$statuses = array_count_values( array_map( static function( $u ) { return $u['available'] ? 'available' : 'leased'; }, $data['units'] ) );
check( 2 === $statuses['available'] && 1 === $statuses['leased'], 'Available and on-notice units are offered; leased units are not.' );
check( 'on_notice' === $data['units']['F01']['source_context']['lease_status'] && 'occupied' === $data['units']['F01']['source_context']['occupancy_status'], 'Source status remains available in diagnostics.' );
check( 'RENT' === $data['units']['D06']['source_context']['charges'][1]['code'] && '1595.00' === $data['units']['D06']['source_context']['charges'][1]['amount'], 'Selected source charges retained.' );
check( ! isset( $data['units']['F01']['meta']['source_context'], $data['units']['F01']['meta']['has_specials'] ), 'Diagnostic source fields and concessions never become public metadata.' );
$conflicting = str_replace( '<UnitsAvailable>1</UnitsAvailable>', '<UnitsAvailable>99</UnitsAvailable>', $xml );
$conflict_data = rfs_rentegi_parse_feed( $conflicting, '184AST' );
check( 99 === $conflict_data['floorplans']['Adams']['source_context']['reported_available_units'] && 1 === $conflict_data['floorplans']['Adams']['source_context']['inventory_available_units'] && $conflict_data['floorplans']['Adams']['warnings'], 'Provider count conflicts are diagnostic warnings, not failed inventory.' );
$long_source = simplexml_load_string( $xml );
$charge = $long_source->Property->ILS_Unit[1]->ChargeOfferClass->ChargeOfferItem[1];
$charge->Description = str_repeat( 'x', 2000 );
for ( $i = 0; $i < 10; ++$i ) {
	$extra = $long_source->Property->ILS_Unit[1]->ChargeOfferClass->addChild( 'ChargeOfferItem' );
	$extra->addAttribute( 'InternalCode', 'DEBUG' );
	$extra->addChild( 'Description', str_repeat( 'y', 2000 ) );
}
$bounded = rfs_rentegi_parse_feed( $long_source->asXML(), '184AST' )['units']['D06']['source_context'];
check( 8 === count( $bounded['charges'] ) && $bounded['charges_truncated'] && 512 === strlen( $bounded['charges'][1]['description'] ), 'Source diagnostics have bounded rows and text.' );
foreach ( $data['units'] as $unit ) { check( $unit['meta']['minimum_rent'] > 0, 'RENT charge must win over zero UnitRent.' ); }
$namespaced = str_replace( '<PhysicalProperty>', '<PhysicalProperty xmlns="urn:mits:test">', $xml );
check( $data === rfs_rentegi_parse_feed( $namespaced, '184AST' ), 'Default namespace.' );
$invalid = array( '', '<html>Bad gateway</html>', substr( $xml, 0, -20 ), str_replace( '184AST', 'OTHER', $xml ), str_replace( '<UnitCount>3</UnitCount>', '<UnitCount>99</UnitCount>', $xml ), str_replace( 'InternalCode="RENT"', 'InternalCode="OTHER"', $xml ), str_replace( '<Identification IDType="FloorplanID" IDValue="Rainier"', '<Identification IDType="FloorplanID" IDValue="UNKNOWN"', $xml ), str_replace( '<UnitLeasedStatus>available</UnitLeasedStatus>', '<UnitLeasedStatus>unknown</UnitLeasedStatus>', $xml ), '<!DOCTYPE PhysicalProperty [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $xml );
foreach ( $invalid as $index => $bad ) {
	$thrown = false;
	try { rfs_rentegi_parse_feed( $bad, '184AST' ); } catch ( RuntimeException $e ) { $thrown = true; }
	check( $thrown, 'Invalid/incomplete inventory must be rejected: ' . $index );
}
if ( isset( $argv[1] ) ) {
	$full = rfs_rentegi_parse_feed( file_get_contents( $argv[1] ), '184AST' );
	check( 126 === count( $full['units'] ) && 3 === count( $full['floorplans'] ), 'Full Astoria sample inventory.' );
	echo 'Full sample: 3 floor plans, 126 units, ' . count( array_filter( $full['units'], static function( $u ) { return $u['available']; } ) ) . " offered units.\n";
}
echo "RentEGI parser regressions passed.\n";
