<?php
/**
 * Mapbox Geocoding provider.
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
 * Geocodes via the Mapbox Geocoding API. Requires a server-side access token.
 */
final class MapboxProvider extends AbstractProvider {

	/**
	 * @param string        $access_token Mapbox token (server-side).
	 * @param callable|null $fetcher      Custom HTTP fetcher (tests).
	 */
	public function __construct( private string $access_token, ?callable $fetcher = null ) {
		parent::__construct( $fetcher );
	}

	protected function build_url( string $address ): string {
		return 'https://api.mapbox.com/geocoding/v5/mapbox.places/'
			. rawurlencode( $address ) . '.json?limit=1&access_token=' . rawurlencode( $this->access_token );
	}

	/**
	 * @param array<mixed> $json Decoded response.
	 */
	protected function parse( array $json ): ?GeoPoint {
		$feature = $json['features'][0] ?? null;
		$center  = $feature['center'] ?? null;
		// Mapbox returns [lng, lat].
		if ( ! is_array( $center ) || ! isset( $center[0], $center[1] ) ) {
			return null;
		}

		return new GeoPoint(
			(float) $center[1],
			(float) $center[0],
			isset( $feature['place_name'] ) ? (string) $feature['place_name'] : null
		);
	}
}
