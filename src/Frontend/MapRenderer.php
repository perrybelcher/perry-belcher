<?php
/**
 * Renders the map container consumed by the front-end clustering script.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits a `data-lodestar-map` element carrying its markers as escaped JSON. The
 * plain-JS initialiser (assets/js/lodestar-map.js) hydrates it with Leaflet +
 * markercluster, so thousands of markers cluster without lag. No business logic
 * and no inline styles — height comes from a data attribute the script applies.
 */
final class MapRenderer {

	/**
	 * @param array<int,array{lat:float,lng:float,title:string,url:string}> $markers Marker data.
	 * @param array<string,mixed>                                            $opts    height (px).
	 */
	public static function render( array $markers, array $opts = array() ): string {
		$points = array();
		foreach ( $markers as $marker ) {
			if ( ! isset( $marker['lat'], $marker['lng'] ) ) {
				continue;
			}
			$points[] = array(
				'lat'   => (float) $marker['lat'],
				'lng'   => (float) $marker['lng'],
				'title' => (string) ( $marker['title'] ?? '' ),
				'url'   => (string) ( $marker['url'] ?? '' ),
			);
		}

		$height = isset( $opts['height'] ) ? (int) $opts['height'] : 420;

		return sprintf(
			'<div class="lodestar-map" data-lodestar-map data-height="%1$d" data-markers="%2$s" role="application" aria-label="%3$s"></div>',
			$height,
			esc_attr( (string) wp_json_encode( $points ) ),
			esc_attr__( 'Map of listings', 'lodestar' )
		);
	}
}
