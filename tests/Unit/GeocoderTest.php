<?php
/**
 * Unit tests for the geocoder providers (no network; injected fetcher).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Geo\GeoPoint;
use Lodestar\Geo\Provider\GoogleProvider;
use Lodestar\Geo\Provider\MapboxProvider;
use Lodestar\Geo\Provider\NominatimProvider;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! class_exists( NominatimProvider::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src';
	require_once $lodestar_src . '/Geo/GeoPoint.php';
	require_once $lodestar_src . '/Geo/GeocoderProvider.php';
	require_once $lodestar_src . '/Geo/Provider/AbstractProvider.php';
	require_once $lodestar_src . '/Geo/Provider/NominatimProvider.php';
	require_once $lodestar_src . '/Geo/Provider/GoogleProvider.php';
	require_once $lodestar_src . '/Geo/Provider/MapboxProvider.php';
}

/**
 * @covers \Lodestar\Geo\Provider\AbstractProvider
 * @covers \Lodestar\Geo\Provider\NominatimProvider
 * @covers \Lodestar\Geo\Provider\GoogleProvider
 * @covers \Lodestar\Geo\Provider\MapboxProvider
 */
final class GeocoderTest extends TestCase {

	/**
	 * A fetcher that returns a fixed payload and records the requested URL.
	 *
	 * @param array<mixed> $payload Decoded JSON to return.
	 * @param string|null  $url     Captured URL (by reference).
	 */
	private function fetcher( array $payload, ?string &$url = null ): callable {
		return static function ( string $requested ) use ( $payload, &$url ) {
			$url = $requested;
			return $payload;
		};
	}

	public function test_nominatim_parses_and_builds_url(): void {
		$url      = null;
		$provider = new NominatimProvider(
			$this->fetcher( array( array( 'lat' => '40.7128', 'lon' => '-74.0060', 'display_name' => 'New York, NY' ) ), $url )
		);

		$point = $provider->geocode( 'New York' );

		$this->assertInstanceOf( GeoPoint::class, $point );
		$this->assertEqualsWithDelta( 40.7128, $point->lat, 0.0001 );
		$this->assertEqualsWithDelta( -74.0060, $point->lng, 0.0001 );
		$this->assertSame( 'New York, NY', $point->label );
		$this->assertStringContainsString( 'nominatim.openstreetmap.org', (string) $url );
		$this->assertStringContainsString( 'New%20York', (string) $url );
	}

	public function test_google_parses_ok_response(): void {
		$provider = new GoogleProvider(
			'KEY123',
			$this->fetcher(
				array(
					'status'  => 'OK',
					'results' => array(
						array(
							'formatted_address' => 'Philadelphia, PA, USA',
							'geometry'          => array( 'location' => array( 'lat' => 39.9526, 'lng' => -75.1652 ) ),
						),
					),
				)
			)
		);

		$point = $provider->geocode( 'Philadelphia' );
		$this->assertEqualsWithDelta( 39.9526, $point->lat, 0.0001 );
		$this->assertEqualsWithDelta( -75.1652, $point->lng, 0.0001 );
		$this->assertSame( 'Philadelphia, PA, USA', $point->label );
	}

	public function test_google_returns_null_on_non_ok_status(): void {
		$provider = new GoogleProvider( 'KEY123', $this->fetcher( array( 'status' => 'ZERO_RESULTS', 'results' => array() ) ) );
		$this->assertNull( $provider->geocode( 'nowhere' ) );
	}

	public function test_mapbox_parses_center_as_lng_lat(): void {
		$provider = new MapboxProvider(
			'TOKEN',
			$this->fetcher(
				array(
					'features' => array(
						array( 'place_name' => 'Boston, Massachusetts', 'center' => array( -71.0589, 42.3601 ) ),
					),
				)
			)
		);

		$point = $provider->geocode( 'Boston' );
		// center is [lng, lat] — ensure we don't swap them.
		$this->assertEqualsWithDelta( 42.3601, $point->lat, 0.0001 );
		$this->assertEqualsWithDelta( -71.0589, $point->lng, 0.0001 );
	}

	public function test_empty_address_returns_null_without_fetching(): void {
		$fetched  = false;
		$provider = new NominatimProvider(
			static function ( string $url ) use ( &$fetched ) {
				$fetched = true;
				return array();
			}
		);

		$this->assertNull( $provider->geocode( '   ' ) );
		$this->assertFalse( $fetched );
	}
}
