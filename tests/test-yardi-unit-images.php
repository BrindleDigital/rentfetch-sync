<?php
/**
 * Run with: php -d zend.assertions=1 -d assert.exception=1 tests/test-yardi-unit-images.php
 */

define( 'ABSPATH', __DIR__ );

function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}

require_once dirname( __DIR__ ) . '/lib/api/yardi/v2/update-wp-unit-yardi-v2.php';

$first  = 'https://example.com/unit-1.jpg';
$second = 'https://example.com/unit-2.jpg';

assert(
	array( $first, $second ) === rfs_yardi_v2_get_unit_image_urls(
		array(
			'unitImageURLs'      => $first . ', ' . $second,
			'unitImageURLsArray' => array( $second, 'not a URL' ),
		)
	)
);
assert( array() === rfs_yardi_v2_get_unit_image_urls( array() ) );

echo "Yardi unit image tests passed.\n";
