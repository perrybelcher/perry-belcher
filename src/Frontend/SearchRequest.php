<?php
/**
 * Maps sanitised request args + field definitions to a SearchQuery.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Frontend;

use Lodestar\Data\Facet;
use Lodestar\Data\GeoFilter;
use Lodestar\Data\SearchQuery;
use Lodestar\DirectoryType\FieldDefinition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure transform from front-end search args to a {@see SearchQuery}.
 *
 * Only facetable fields can drive a facet — a request cannot filter on a field
 * the type did not mark searchable. Numeric fields read `<key>_min` / `<key>_max`;
 * text/select/multiselect read `<key>` (string or array). No WordPress calls, so
 * the mapping is unit-testable; the controller supplies pre-sanitised args.
 */
final class SearchRequest {

	/**
	 * @var array<string,array{0:string,1:string}> sort token => [orderBy, dir]
	 */
	private const SORT_MAP = array(
		'relevance'  => array( SearchQuery::ORDER_RELEVANCE, 'DESC' ),
		'distance'   => array( SearchQuery::ORDER_DISTANCE, 'ASC' ),
		'price_low'  => array( SearchQuery::ORDER_PRICE, 'ASC' ),
		'price_high' => array( SearchQuery::ORDER_PRICE, 'DESC' ),
		'rating'     => array( SearchQuery::ORDER_RATING, 'DESC' ),
		'newest'     => array( SearchQuery::ORDER_NEWEST, 'DESC' ),
	);

	/**
	 * Build a SearchQuery from request args.
	 *
	 * @param array<string,mixed> $args        Sanitised request args.
	 * @param FieldDefinition[]   $defs        Field definitions for the type.
	 * @param int|null            $type_id     Directory type to scope to.
	 * @param float               $default_radius_km Radius used when geo lacks one.
	 */
	public static function build( array $args, array $defs, ?int $type_id = null, float $default_radius_km = 25.0 ): SearchQuery {
		$facets = array();

		foreach ( $defs as $def ) {
			if ( ! $def->isFacetable || ! $def->isIndexable() ) {
				continue;
			}

			$key = $def->fieldKey;

			if ( Facet::TYPE_NUM === $def->storageType() ) {
				$min = self::number( $args[ $key . '_min' ] ?? null );
				$max = self::number( $args[ $key . '_max' ] ?? null );
				if ( null !== $min || null !== $max ) {
					$facets[] = Facet::range( $key, $min, $max );
				}
				continue;
			}

			if ( isset( $args[ $key ] ) ) {
				$values = array_values( array_filter( (array) $args[ $key ], static fn ( $v ) => '' !== (string) $v ) );
				if ( $values ) {
					$facets[] = Facet::text( $key, $values );
				}
			}
		}

		$geo = null;
		if ( isset( $args['lat'], $args['lng'] ) && '' !== (string) $args['lat'] && '' !== (string) $args['lng'] ) {
			$radius = self::number( $args['radius_km'] ?? null );
			$geo    = new GeoFilter( (float) $args['lat'], (float) $args['lng'], $radius && $radius > 0 ? $radius : $default_radius_km );
		}

		$sort                = (string) ( $args['sort'] ?? 'relevance' );
		[ $order_by, $order ] = self::SORT_MAP[ $sort ] ?? self::SORT_MAP['relevance'];

		// Distance sort only makes sense with a centre point.
		if ( SearchQuery::ORDER_DISTANCE === $order_by && null === $geo ) {
			[ $order_by, $order ] = self::SORT_MAP['relevance'];
		}

		return new SearchQuery(
			directoryTypeId: $type_id,
			facets: $facets,
			geo: $geo,
			priceMin: self::number( $args['price_min'] ?? null ),
			priceMax: self::number( $args['price_max'] ?? null ),
			ratingMin: self::number( $args['rating_min'] ?? null ),
			orderBy: $order_by,
			order: $order,
			page: max( 1, (int) ( $args['page'] ?? 1 ) ),
			perPage: max( 1, min( 50, (int) ( $args['per_page'] ?? 20 ) ) ),
			status: 'publish',
		);
	}

	/**
	 * Parse a value to float, or null when blank/absent.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function number( $value ): ?float {
		if ( null === $value || '' === ( is_scalar( $value ) ? (string) $value : '' ) ) {
			return null;
		}

		return is_numeric( $value ) ? (float) $value : null;
	}
}
