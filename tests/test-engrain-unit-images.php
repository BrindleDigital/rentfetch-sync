<?php
/**
 * Run with: php -d zend.assertions=1 -d assert.exception=1 tests/test-engrain-unit-images.php
 *
 * @package rentfetch-sync
 */

define( 'ABSPATH', __DIR__ );

/**
 * Stub URL validation.
 *
 * @param string $url URL.
 * @return string
 */
function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}

/** Stub WordPress filter registration. */
function add_filter() {}

/** Stub WordPress action registration. */
function add_action() {}

require_once dirname( __DIR__ ) . '/lib/api/engrain/update-wp-engrain.php';

$url = 'https://cdn.sightmap.com/unit.jpg';

assert(
	array( $url ) === rfs_engrain_get_unit_image_urls(
		array(
			'view_image_url'           => $url,
			'secondary_view_image_url' => $url,
		)
	)
);
assert( array() === rfs_engrain_get_unit_image_urls( array( 'view_image_url' => 'not a URL' ) ) );

echo "Engrain unit image tests passed.\n";
