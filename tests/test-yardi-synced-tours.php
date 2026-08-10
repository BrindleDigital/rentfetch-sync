<?php
/**
 * Run with: php -d zend.assertions=1 -d assert.exception=1 tests/test-yardi-synced-tours.php
 */

define( 'ABSPATH', __DIR__ );

function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}

require_once dirname( __DIR__ ) . '/lib/api/yardi/api-functions-yardi.php';

$matterport = 'https://my.matterport.com/show/?m=RbRMQVyf2sL&play=1';
$matterport_two = 'https://my.matterport.com/show/?m=gsAWyyW5ezo';
$youtube    = 'https://www.youtube.com/watch?v=example';
$vimeo      = 'https://vimeo.com/123';
$tours      = rfs_yardi_v2_get_synced_tours(
	array(
		'fpVideoEmbedCode'        => '<iframe src="https://my.matterport.com/show/?m=RbRMQVyf2sL&amp;play=1"></iframe>, <iframe src="https://my.matterport.com/show/?m=gsAWyyW5ezo"></iframe>',
		'tour360EmbedCode'        => $matterport,
		'floorplanVirtualTourUrl' => $youtube . ',' . $vimeo,
		'propertyVideoEmbedCode'  => 'javascript:alert(1)',
	)
);

assert(
	array(
		array(
			'url'          => $matterport,
			'type'         => 'tour_360',
			'source'       => 'yardi',
			'source_field' => 'tour360EmbedCode',
		),
		array(
			'url'          => $matterport_two,
			'type'         => 'video',
			'source'       => 'yardi',
			'source_field' => 'fpVideoEmbedCode',
		),
		array(
			'url'          => $youtube,
			'type'         => 'virtual_tour',
			'source'       => 'yardi',
			'source_field' => 'floorplanVirtualTourUrl',
		),
		array(
			'url'          => $vimeo,
			'type'         => 'virtual_tour',
			'source'       => 'yardi',
			'source_field' => 'floorplanVirtualTourUrl',
		),
	) === $tours
);
assert( array() === rfs_yardi_v2_get_synced_tours( 'invalid' ) );

echo "Yardi synced tour tests passed.\n";
