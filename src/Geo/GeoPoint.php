<?php
/**
 * A resolved geographic point.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Geo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable lat/lng pair with an optional human label (e.g. the matched
 * address), returned by {@see Geocoder}.
 */
final class GeoPoint {

	/**
	 * @param float       $lat   Latitude.
	 * @param float       $lng   Longitude.
	 * @param string|null $label Matched address / display name.
	 */
	public function __construct(
		public readonly float $lat,
		public readonly float $lng,
		public readonly ?string $label = null,
	) {}
}
