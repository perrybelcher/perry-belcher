<?php
/**
 * Translates a SearchQuery into a prepared SQL statement.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a {@see SearchQuery} into `[ $sql, $args ]` ready for `$wpdb->prepare()`.
 *
 * Design that keeps it fast at 100k+ rows:
 *  - Each facet becomes an indexed EXISTS subquery against `field_index`
 *    `(field_key, value_text|value_num)` — never an N-way self-join or
 *    meta_query.
 *  - Radius search uses an indexed lat/lng bounding-box prefilter, then a
 *    haversine refine in HAVING and for distance ordering.
 *  - Sort columns are allowlisted (no identifier ever comes from user input).
 *
 * The class is pure (no DB handle), so it is unit-testable in isolation. SQL
 * uses `$wpdb->prepare` placeholders (%d, %s, %f); the args array matches the
 * placeholder order exactly.
 */
final class QueryBuilder {

	/**
	 * Hot columns returned for each result row (never the heavy `meta` blob).
	 */
	private const SELECT_COLUMNS = array(
		'listing_id',
		'directory_type_id',
		'plan_id',
		'price',
		'rating_avg',
		'rating_count',
		'is_featured',
		'lat',
		'lng',
		'view_count',
	);

	/**
	 * Allowlist mapping SearchQuery order keys to real columns.
	 *
	 * @var array<string,string>
	 */
	private const ORDER_COLUMNS = array(
		SearchQuery::ORDER_PRICE  => 'd.price',
		SearchQuery::ORDER_RATING => 'd.rating_avg',
		SearchQuery::ORDER_NEWEST => 'd.created_at',
		SearchQuery::ORDER_VIEWS  => 'd.view_count',
	);

	/**
	 * @param string $prefix Site table prefix (e.g. `$wpdb->prefix`).
	 */
	public function __construct( private string $prefix ) {}

	/**
	 * Build the paginated result query.
	 *
	 * @return array{0:string,1:array<int,mixed>} [ $sql, $args ]
	 */
	public function build( SearchQuery $q ): array {
		$select = 'SELECT d.' . implode( ', d.', self::SELECT_COLUMNS );
		$args   = array();

		if ( null !== $q->geo ) {
			[ $distance, $distance_args ] = $this->distanceExpr( $q );
			$select .= ', ( ' . $distance . ' ) AS distance';
			$args    = $distance_args;
		}

		[ $where, $where_args ] = $this->conditions( $q );

		$sql = $select . ' ' . $this->fromClause( $q );
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
			$args = array_merge( $args, $where_args );
		}

		$sql  .= ' ' . $this->orderClause( $q ) . ' LIMIT %d OFFSET %d';
		$args  = array_merge( $args, array( $q->perPageClamped(), $q->offset() ) );

		return array( $sql, $args );
	}

	/**
	 * Build the matching COUNT query (for pagination totals).
	 *
	 * @return array{0:string,1:array<int,mixed>} [ $sql, $args ]
	 */
	public function buildCount( SearchQuery $q ): array {
		[ $where, $where_args ] = $this->conditions( $q );

		$sql  = 'SELECT COUNT(*) ' . $this->fromClause( $q );
		$args = array();
		if ( $where ) {
			$sql  .= ' WHERE ' . implode( ' AND ', $where );
			$args  = $where_args;
		}

		return array( $sql, $args );
	}

	/**
	 * Fully-qualified listing_data table with alias and optional posts join.
	 */
	private function fromClause( SearchQuery $q ): string {
		$from = 'FROM ' . $this->table( 'listing_data' ) . ' d';

		if ( null !== $q->status ) {
			$from .= ' INNER JOIN ' . $this->prefix . 'posts p ON p.ID = d.listing_id';
		}

		return $from;
	}

	/**
	 * Haversine distance expression and its args, or [null, []] when no geo.
	 *
	 * Uses the asin form, which is numerically stable at small (local) radii.
	 *
	 * @return array{0:string|null,1:array<int,float>}
	 */
	private function distanceExpr( SearchQuery $q ): array {
		if ( null === $q->geo ) {
			return array( null, array() );
		}

		$expr = '2 * 6371 * asin( sqrt( '
			. 'pow( sin( radians( d.lat - %f ) / 2 ), 2 ) + '
			. 'cos( radians( %f ) ) * cos( radians( d.lat ) ) * '
			. 'pow( sin( radians( d.lng - %f ) / 2 ), 2 ) ) )';

		return array( $expr, array( $q->geo->lat, $q->geo->lat, $q->geo->lng ) );
	}

	/**
	 * All WHERE conditions and their args, in placeholder order.
	 *
	 * @return array{0:array<int,string>,1:array<int,mixed>}
	 */
	private function conditions( SearchQuery $q ): array {
		$where = array();
		$args  = array();

		if ( null !== $q->directoryTypeId ) {
			$where[] = 'd.directory_type_id = %d';
			$args[]  = $q->directoryTypeId;
		}

		if ( null !== $q->status ) {
			$where[] = 'p.post_status = %s';
			$args[]  = $q->status;
			$where[] = 'p.post_type = %s';
			$args[]  = \Lodestar\PostType\ListingPostType::POST_TYPE;
		}

		if ( null !== $q->geo ) {
			// Cheap, indexed bounding-box prefilter...
			[ $min_lat, $max_lat, $min_lng, $max_lng ] = $q->geo->boundingBox();
			$where[] = 'd.lat BETWEEN %f AND %f';
			$args[]  = $min_lat;
			$args[]  = $max_lat;
			$where[] = 'd.lng BETWEEN %f AND %f';
			$args[]  = $min_lng;
			$args[]  = $max_lng;

			// ...then the exact haversine refine (portable: no HAVING needed).
			[ $distance, $distance_args ] = $this->distanceExpr( $q );
			$where[] = '( ' . $distance . ' ) <= %f';
			$args    = array_merge( $args, $distance_args, array( $q->geo->radiusKm ) );
		}

		if ( null !== $q->priceMin ) {
			$where[] = 'd.price >= %f';
			$args[]  = $q->priceMin;
		}
		if ( null !== $q->priceMax ) {
			$where[] = 'd.price <= %f';
			$args[]  = $q->priceMax;
		}

		if ( null !== $q->ratingMin ) {
			$where[] = 'd.rating_avg >= %f';
			$args[]  = $q->ratingMin;
		}

		if ( $q->featuredOnly ) {
			$where[] = 'd.is_featured = 1';
		}

		foreach ( $q->facets as $i => $facet ) {
			[ $cond, $facet_args ] = $this->facetExists( $facet, (int) $i );
			if ( null !== $cond ) {
				$where[] = $cond;
				$args    = array_merge( $args, $facet_args );
			}
		}

		return array( $where, $args );
	}

	/**
	 * Build one indexed EXISTS clause for a facet.
	 *
	 * @return array{0:string|null,1:array<int,mixed>}
	 */
	private function facetExists( Facet $facet, int $i ): array {
		$alias = 'f' . $i;
		$table = $this->table( 'field_index' );

		$sql  = "EXISTS ( SELECT 1 FROM {$table} {$alias} WHERE {$alias}.listing_id = d.listing_id AND {$alias}.field_key = %s";
		$args = array( $facet->key );

		if ( Facet::TYPE_NUM === $facet->type || Facet::TYPE_DATE === $facet->type ) {
			$column = Facet::TYPE_NUM === $facet->type ? 'value_num' : 'value_date';

			if ( null !== $facet->numMin && null !== $facet->numMax ) {
				$sql   .= " AND {$alias}.{$column} BETWEEN %f AND %f";
				$args[] = $facet->numMin;
				$args[] = $facet->numMax;
			} elseif ( null !== $facet->numMin ) {
				$sql   .= " AND {$alias}.{$column} >= %f";
				$args[] = $facet->numMin;
			} elseif ( null !== $facet->numMax ) {
				$sql   .= " AND {$alias}.{$column} <= %f";
				$args[] = $facet->numMax;
			} else {
				// A numeric facet with no bounds is a no-op; skip it.
				return array( null, array() );
			}
		} else {
			$values = array_values( array_filter( $facet->values, static fn ( $v ) => '' !== (string) $v ) );
			if ( empty( $values ) ) {
				return array( null, array() );
			}

			if ( 1 === count( $values ) ) {
				$sql   .= " AND {$alias}.value_text = %s";
				$args[] = (string) $values[0];
			} else {
				$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
				$sql         .= " AND {$alias}.value_text IN ( {$placeholders} )";
				foreach ( $values as $v ) {
					$args[] = (string) $v;
				}
			}
		}

		$sql .= ' )';

		return array( $sql, $args );
	}

	/**
	 * ORDER BY clause. Columns are allowlisted; direction is constrained.
	 */
	private function orderClause( SearchQuery $q ): string {
		$dir = 'ASC' === strtoupper( $q->order ) ? 'ASC' : 'DESC';

		// Explicit, allowlisted sort.
		if ( isset( self::ORDER_COLUMNS[ $q->orderBy ] ) ) {
			return 'ORDER BY ' . self::ORDER_COLUMNS[ $q->orderBy ] . ' ' . $dir . ', d.listing_id DESC';
		}

		if ( SearchQuery::ORDER_DISTANCE === $q->orderBy && null !== $q->geo ) {
			return 'ORDER BY distance ASC, d.listing_id DESC';
		}

		// Relevance: featured first, then nearest (if geo) or most recent.
		$tiebreak = null !== $q->geo ? 'distance ASC' : 'd.updated_at DESC';

		return 'ORDER BY d.is_featured DESC, ' . $tiebreak . ', d.listing_id DESC';
	}

	/**
	 * Fully-qualified Lodestar table name.
	 */
	private function table( string $name ): string {
		return $this->prefix . 'lodestar_' . $name;
	}
}
