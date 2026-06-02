<?php
/**
 * Provider-agnostic geocoder facade.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Geo;

use Lodestar\Geo\Provider\GoogleProvider;
use Lodestar\Geo\Provider\MapboxProvider;
use Lodestar\Geo\Provider\NominatimProvider;
use Lodestar\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Selects a provider by config and caches results so repeated lookups (and the
 * OSM usage policy) stay cheap. Swap providers via the `lodestar_geo_provider`
 * setting/filter — Google, Mapbox, or the keyless Nominatim default.
 */
final class Geocoder {

	/**
	 * @param GeocoderProvider $provider Concrete provider.
	 */
	public function __construct( private GeocoderProvider $provider ) {}

	/**
	 * Build a geocoder from the configured provider.
	 *
	 * @param callable|null $fetcher Optional HTTP fetcher (tests).
	 */
	public static function from_config( ?callable $fetcher = null ): self {
		$provider = Settings::geo_provider();
		$key      = Settings::geo_api_key();

		$instance = match ( $provider ) {
			'google' => new GoogleProvider( $key, $fetcher ),
			'mapbox' => new MapboxProvider( $key, $fetcher ),
			default  => new NominatimProvider( $fetcher ),
		};

		return new self( $instance );
	}

	/**
	 * Geocode an address, with a 30-day result cache.
	 *
	 * @param string $address Free-text address.
	 */
	public function geocode( string $address ): ?GeoPoint {
		$address = trim( $address );
		if ( '' === $address ) {
			return null;
		}

		$cache_key = 'lodestar_geo_' . md5( Settings::geo_provider() . '|' . $address );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['lat'], $cached['lng'] ) ) {
			return new GeoPoint( (float) $cached['lat'], (float) $cached['lng'], $cached['label'] ?? null );
		}

		$point = $this->provider->geocode( $address );
		if ( $point instanceof GeoPoint ) {
			set_transient(
				$cache_key,
				array( 'lat' => $point->lat, 'lng' => $point->lng, 'label' => $point->label ),
				MONTH_IN_SECONDS
			);
		}

		return $point;
	}
}
