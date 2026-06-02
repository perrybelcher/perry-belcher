<?php
/**
 * Geohash encoder.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal geohash encoder used to precompute the indexed `geohash` column on
 * save. The radius prefilter in Phase 1 uses a lat/lng bounding box; the
 * geohash is stored for the dedicated Geo\RadiusQuery work in Phase 4.
 */
final class Geohash {

	private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

	/**
	 * Encode a coordinate to a geohash string.
	 *
	 * @param float $lat       Latitude (-90..90).
	 * @param float $lng       Longitude (-180..180).
	 * @param int   $precision Number of characters (default 12).
	 */
	public static function encode( float $lat, float $lng, int $precision = 12 ): string {
		$precision = max( 1, min( 12, $precision ) );

		$lat_range = array( -90.0, 90.0 );
		$lng_range = array( -180.0, 180.0 );

		$hash     = '';
		$bit      = 0;
		$ch       = 0;
		$even_bit = true;

		while ( strlen( $hash ) < $precision ) {
			if ( $even_bit ) {
				$mid = ( $lng_range[0] + $lng_range[1] ) / 2;
				if ( $lng >= $mid ) {
					$ch |= ( 1 << ( 4 - $bit ) );
					$lng_range[0] = $mid;
				} else {
					$lng_range[1] = $mid;
				}
			} else {
				$mid = ( $lat_range[0] + $lat_range[1] ) / 2;
				if ( $lat >= $mid ) {
					$ch |= ( 1 << ( 4 - $bit ) );
					$lat_range[0] = $mid;
				} else {
					$lat_range[1] = $mid;
				}
			}

			$even_bit = ! $even_bit;

			if ( $bit < 4 ) {
				++$bit;
			} else {
				$hash .= self::BASE32[ $ch ];
				$bit   = 0;
				$ch    = 0;
			}
		}

		return $hash;
	}
}
