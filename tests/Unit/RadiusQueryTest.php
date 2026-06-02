<?php
/**
 * Unit tests for RadiusQuery geo math (pure, no WordPress).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Data\Geohash;
use Lodestar\Geo\RadiusQuery;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! class_exists( RadiusQuery::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src';
	require_once $lodestar_src . '/Data/Geohash.php';
	require_once $lodestar_src . '/Data/GeoFilter.php';
	require_once $lodestar_src . '/Geo/RadiusQuery.php';
}

/**
 * @covers \Lodestar\Geo\RadiusQuery
 */
final class RadiusQueryTest extends TestCase {

	public function test_haversine_matches_known_distance(): void {
		// New York City -> Philadelphia is ~130 km.
		$km = RadiusQuery::distance_km( 40.7128, -74.0060, 39.9526, -75.1652 );
		$this->assertEqualsWithDelta( 130.0, $km, 5.0 );
	}

	public function test_distance_to_self_is_zero(): void {
		$this->assertEqualsWithDelta( 0.0, RadiusQuery::distance_km( 51.5, -0.12, 51.5, -0.12 ), 0.0001 );
	}

	public function test_distance_is_symmetric(): void {
		$a = RadiusQuery::distance_km( 34.05, -118.24, 37.77, -122.41 );
		$b = RadiusQuery::distance_km( 37.77, -122.41, 34.05, -118.24 );
		$this->assertEqualsWithDelta( $a, $b, 0.0001 );
	}

	public function test_precision_scales_with_radius(): void {
		$this->assertSame( 4, RadiusQuery::precision_for_radius( 25 ) );
		$this->assertSame( 5, RadiusQuery::precision_for_radius( 10 ) );
		$this->assertLessThan(
			RadiusQuery::precision_for_radius( 1 ),
			RadiusQuery::precision_for_radius( 100 )
		);
	}

	public function test_covering_geohashes_include_centre_at_precision(): void {
		$lat       = 40.5;
		$lng       = -74.0;
		$radius    = 10.0;
		$precision = RadiusQuery::precision_for_radius( $radius );

		$cover = RadiusQuery::covering_geohashes( $lat, $lng, $radius );

		$this->assertNotEmpty( $cover );
		$this->assertContains( Geohash::encode( $lat, $lng, $precision ), $cover );
		foreach ( $cover as $hash ) {
			$this->assertSame( $precision, strlen( $hash ) );
		}
	}
}
