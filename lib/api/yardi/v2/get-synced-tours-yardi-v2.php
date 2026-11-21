<?php
/** Normalize Yardi v2 video and virtual-tour fields. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize every video or virtual-tour field included in a Yardi payload.
 *
 * @param array $data Yardi property, floorplan, or unit data.
 * @return array[] Normalized tour records.
 */
function rfs_yardi_v2_get_synced_tours( $data ) {
	if ( ! is_array( $data ) ) {
		return array();
	}

	$fields = array(
		'tour360EmbedCode'         => 'tour_360',
		'fpVideoEmbedCode'         => 'video',
		'floorplanVirtualTourUrl'  => 'virtual_tour',
		'unitEmbedVideo'           => 'video',
		'unitVirtualTourUrl'       => 'virtual_tour',
		'propertyVideoEmbedCode'   => 'video',
		'propertyVirtualTourUrl'   => 'virtual_tour',
	);
	$tours  = array();

	foreach ( $fields as $field => $type ) {
		$value = html_entity_decode( trim( (string) ( $data[ $field ] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( ! $value || ! preg_match_all( '~https?://[^\s,"\'<>]+~i', $value, $matches ) ) {
			continue;
		}

		foreach ( $matches[0] as $match ) {
			$url = esc_url_raw( $match, array( 'http', 'https' ) );

			if ( ! $url || isset( $tours[ $url ] ) ) {
				continue;
			}

			$tours[ $url ] = array(
				'url'          => $url,
				'type'         => $type,
				'source'       => 'yardi',
				'source_field' => $field,
			);
		}
	}

	return array_values( $tours );
}
