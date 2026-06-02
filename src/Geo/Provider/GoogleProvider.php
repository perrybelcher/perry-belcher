<?php
/**
 * Google Maps Geocoding provider.
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
 * Geocodes via the Google Maps Geocoding API. Requires a server-side key.
 */
final class GoogleProvider extends AbstractProvider {

	/**
	 * @param string        $api_key Google API key (server-side).
	 * @param callable|null $fetcher Custom HTTP fetcher (tests).
	 */
	public function __construct( private string $api_key, ?callable $fetcher = null ) {
		parent::__construct( $fetcher );
	}

	protected function build_url( string $address ): string {
		return 'https://maps.googleapis.com/maps/api/geocode/json?address='
			. rawurlencode( $address ) . '&key=' . rawurlencode( $this->api_key );
	}

	/**
	 * @param array<mixed> $json Decoded response.
	 */
	protected function parse( array $json ): ?GeoPoint {
		if ( ( $json['status'] ?? '' ) !== 'OK' ) {
			return null;
		}

		$result   = $json['results'][0] ?? null;
		$location = $result['geometry']['location'] ?? null;
		if ( ! is_array( $location ) || ! isset( $location['lat'], $location['lng'] ) ) {
			return null;
		}

		return new GeoPoint(
			(float) $location['lat'],
			(float) $location['lng'],
			isset( $result['formatted_address'] ) ? (string) $result['formatted_address'] : null
		);
	}
}
