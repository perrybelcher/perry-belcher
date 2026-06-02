<?php
/**
 * OpenStreetMap / Nominatim geocoder (default, no API key).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Geo\Provider;

use Lodestar\Geo\GeoPoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default provider — free, keyless. Respect the OSM usage policy (low volume +
 * caching, which {@see \Lodestar\Geo\Geocoder} does via transients).
 */
final class NominatimProvider extends AbstractProvider {

	protected function build_url( string $address ): string {
		return 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' . rawurlencode( $address );
	}

	/**
	 * @param array<mixed> $json Decoded response (a list of matches).
	 */
	protected function parse( array $json ): ?GeoPoint {
		$first = $json[0] ?? null;
		if ( ! is_array( $first ) || ! isset( $first['lat'], $first['lon'] ) ) {
			return null;
		}

		return new GeoPoint(
			(float) $first['lat'],
			(float) $first['lon'],
			isset( $first['display_name'] ) ? (string) $first['display_name'] : null
		);
	}
}
