<?php
/**
 * Radius (geo) filter for a search query.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable centre-point + radius. The {@see QueryBuilder} turns this into a
 * cheap bounding-box prefilter (indexed on lat/lng) plus a haversine refine and
 * distance ordering.
 */
final class GeoFilter {

	/** Approximate kilometres per degree of latitude. */
	private const KM_PER_DEG_LAT = 111.045;

	/**
	 * @param float $lat      Centre latitude.
	 * @param float $lng      Centre longitude.
	 * @param float $radiusKm Search radius in kilometres.
	 */
	public function __construct(
		public readonly float $lat,
		public readonly float $lng,
		public readonly float $radiusKm,
	) {}

	/**
	 * Bounding box for the radius: [minLat, maxLat, minLng, maxLng].
	 *
	 * Used as an index-friendly prefilter before the exact haversine check.
	 *
	 * @return array{0:float,1:float,2:float,3:float}
	 */
	public function boundingBox(): array {
		$lat_delta = $this->radiusKm / self::KM_PER_DEG_LAT;

		// Guard the poles: cos(lat) -> 0 would blow up the longitude delta.
		$cos     = cos( deg2rad( $this->lat ) );
		$cos     = abs( $cos ) < 0.000001 ? 0.000001 : $cos;
		$lng_delta = $this->radiusKm / ( self::KM_PER_DEG_LAT * $cos );

		return array(
			$this->lat - $lat_delta,
			$this->lat + $lat_delta,
			$this->lng - $lng_delta,
			$this->lng + $lng_delta,
		);
	}
}
