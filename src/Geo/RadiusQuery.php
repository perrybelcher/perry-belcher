<?php
/**
 * Radius search helpers: geohash prefilter + haversine refine.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Geo;

use Lodestar\Data\Geohash;
use Lodestar\Data\GeoFilter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The canonical geo math for proximity search.
 *
 * {@see \Lodestar\Data\QueryBuilder} executes the radius query (indexed lat/lng
 * bounding-box prefilter + haversine refine). This class provides the reusable
 * pieces around it: exact distance for ordering/labelling, the GeoFilter that
 * carries the bounding box, and the set of covering geohash prefixes for map
 * tiling / clustering and an alternative indexed prefilter.
 */
final class RadiusQuery {

	/** Mean Earth radius in kilometres. */
	public const EARTH_KM = 6371.0;

	/**
	 * Great-circle distance between two points, in kilometres (haversine).
	 *
	 * @param float $lat1 Point 1 latitude.
	 * @param float $lng1 Point 1 longitude.
	 * @param float $lat2 Point 2 latitude.
	 * @param float $lng2 Point 2 longitude.
	 */
	public static function distance_km( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $d_lat / 2 ) ** 2
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;

		// Clamp guards floating error at the antipode; irrelevant for local radii.
		return self::EARTH_KM * 2 * asin( min( 1.0, sqrt( $a ) ) );
	}

	/**
	 * Build the GeoFilter (centre + radius, carrying the bounding box) consumed
	 * by the query builder.
	 */
	public static function to_filter( float $lat, float $lng, float $radius_km ): GeoFilter {
		return new GeoFilter( $lat, $lng, max( 0.1, $radius_km ) );
	}

	/**
	 * Pick a geohash precision whose cell roughly matches the radius.
	 *
	 * @param float $radius_km Search radius.
	 */
	public static function precision_for_radius( float $radius_km ): int {
		return match ( true ) {
			$radius_km > 2500 => 1,
			$radius_km > 630  => 2,
			$radius_km > 78   => 3,
			$radius_km > 20   => 4,
			$radius_km > 2.4  => 5,
			$radius_km > 0.61 => 6,
			default           => 7,
		};
	}

	/**
	 * Geohash prefixes covering the radius (centre + bounding-box corners/edges).
	 *
	 * Usable as an indexed `geohash LIKE 'prefix%'` / `IN(...)` prefilter, and as
	 * the tiling key for map clustering.
	 *
	 * @param float $lat       Centre latitude.
	 * @param float $lng       Centre longitude.
	 * @param float $radius_km Radius in kilometres.
	 * @return string[] Distinct geohash prefixes.
	 */
	public static function covering_geohashes( float $lat, float $lng, float $radius_km ): array {
		$precision = self::precision_for_radius( $radius_km );

		[ $min_lat, $max_lat, $min_lng, $max_lng ] = self::to_filter( $lat, $lng, $radius_km )->boundingBox();

		$points = array(
			array( $lat, $lng ),
			array( $min_lat, $min_lng ),
			array( $min_lat, $max_lng ),
			array( $max_lat, $min_lng ),
			array( $max_lat, $max_lng ),
			array( $lat, $min_lng ),
			array( $lat, $max_lng ),
			array( $min_lat, $lng ),
			array( $max_lat, $lng ),
		);

		$prefixes = array();
		foreach ( $points as [$plat, $plng] ) {
			$prefixes[] = Geohash::encode(
				max( -90.0, min( 90.0, $plat ) ),
				max( -180.0, min( 180.0, $plng ) ),
				$precision
			);
		}

		return array_values( array_unique( $prefixes ) );
	}
}
