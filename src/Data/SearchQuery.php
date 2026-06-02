<?php
/**
 * Search query value object.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable description of a directory search: filters, facets, geo, sort, page.
 *
 * This is a pure data holder — it knows nothing about SQL. {@see QueryBuilder}
 * translates it into a prepared statement. Construct it directly or via
 * {@see self::fromArray()} from sanitised request input.
 */
final class SearchQuery {

	public const ORDER_RELEVANCE = 'relevance';
	public const ORDER_DISTANCE  = 'distance';
	public const ORDER_PRICE     = 'price';
	public const ORDER_RATING    = 'rating';
	public const ORDER_NEWEST    = 'newest';
	public const ORDER_VIEWS     = 'views';

	/**
	 * @param int|null         $directoryTypeId Restrict to a directory type.
	 * @param array<int,Facet> $facets          Facet constraints (AND-combined).
	 * @param GeoFilter|null   $geo             Radius filter.
	 * @param float|null       $priceMin        Inclusive price lower bound.
	 * @param float|null       $priceMax        Inclusive price upper bound.
	 * @param float|null       $ratingMin       Minimum average rating.
	 * @param bool             $featuredOnly    Only featured listings.
	 * @param string           $orderBy         One of the ORDER_* constants.
	 * @param string           $order           'ASC' or 'DESC'.
	 * @param int              $page            1-based page number.
	 * @param int              $perPage         Page size (1–100).
	 * @param string|null      $status          Post status filter (null = any).
	 */
	public function __construct(
		public readonly ?int $directoryTypeId = null,
		public readonly array $facets = array(),
		public readonly ?GeoFilter $geo = null,
		public readonly ?float $priceMin = null,
		public readonly ?float $priceMax = null,
		public readonly ?float $ratingMin = null,
		public readonly bool $featuredOnly = false,
		public readonly string $orderBy = self::ORDER_RELEVANCE,
		public readonly string $order = 'DESC',
		public readonly int $page = 1,
		public readonly int $perPage = 20,
		public readonly ?string $status = 'publish',
	) {}

	/**
	 * Zero-based offset derived from page/perPage.
	 */
	public function offset(): int {
		return ( max( 1, $this->page ) - 1 ) * $this->perPageClamped();
	}

	/**
	 * Page size clamped to a sane 1–100 range.
	 */
	public function perPageClamped(): int {
		return max( 1, min( 100, $this->perPage ) );
	}

	/**
	 * Build from a sanitised associative array (e.g. REST/request input).
	 *
	 * Callers are responsible for sanitising values before this point; this
	 * method only shapes them into the DTO.
	 *
	 * @param array<string,mixed> $input Sanitised input.
	 */
	public static function fromArray( array $input ): self {
		$facets = array();
		foreach ( (array) ( $input['facets'] ?? array() ) as $key => $spec ) {
			if ( $spec instanceof Facet ) {
				$facets[] = $spec;
				continue;
			}

			$type = (string) ( $spec['type'] ?? Facet::TYPE_TEXT );
			if ( Facet::TYPE_NUM === $type || Facet::TYPE_DATE === $type ) {
				$facets[] = new Facet(
					(string) $key,
					$type,
					array(),
					isset( $spec['min'] ) ? (float) $spec['min'] : null,
					isset( $spec['max'] ) ? (float) $spec['max'] : null,
				);
			} else {
				$values = isset( $spec['values'] ) ? (array) $spec['values'] : (array) $spec;
				$facets[] = Facet::text( (string) $key, $values );
			}
		}

		$geo = null;
		if ( isset( $input['lat'], $input['lng'], $input['radius_km'] ) ) {
			$geo = new GeoFilter( (float) $input['lat'], (float) $input['lng'], (float) $input['radius_km'] );
		}

		return new self(
			isset( $input['directory_type_id'] ) ? (int) $input['directory_type_id'] : null,
			$facets,
			$geo,
			isset( $input['price_min'] ) ? (float) $input['price_min'] : null,
			isset( $input['price_max'] ) ? (float) $input['price_max'] : null,
			isset( $input['rating_min'] ) ? (float) $input['rating_min'] : null,
			! empty( $input['featured_only'] ),
			(string) ( $input['order_by'] ?? self::ORDER_RELEVANCE ),
			strtoupper( (string) ( $input['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
			isset( $input['page'] ) ? (int) $input['page'] : 1,
			isset( $input['per_page'] ) ? (int) $input['per_page'] : 20,
			array_key_exists( 'status', $input ) ? ( null === $input['status'] ? null : (string) $input['status'] ) : 'publish',
		);
	}
}
