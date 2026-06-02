<?php
/**
 * Geocoder provider contract.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Geo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A provider that turns a free-text address into a {@see GeoPoint}.
 *
 * Implementations are server-side only; any API key stays on the server and
 * never reaches the client.
 */
interface GeocoderProvider {

	/**
	 * Resolve an address to a point, or null if it cannot be resolved.
	 *
	 * @param string $address Free-text address / place query.
	 */
	public function geocode( string $address ): ?GeoPoint;
}
