<?php
/**
 * All reads/writes for listings.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Data;

use Lodestar\PostType\ListingPostType;
use Lodestar\PostType\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single gateway for listing persistence.
 *
 * Listings are a hybrid: WordPress owns the post row (title, slug, status,
 * author, long description) for routing/SEO; the custom tables own everything
 * facetable. No code outside this class should touch the listing tables, and
 * nothing here uses `meta_query` — search runs entirely on the custom tables
 * via {@see QueryBuilder}.
 */
final class ListingRepository {

	private FieldIndexer $indexer;
	private QueryBuilder $query_builder;

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( private \wpdb $db ) {
		$this->indexer       = new FieldIndexer( $db );
		$this->query_builder = new QueryBuilder( $db->prefix );
	}

	/**
	 * Create a listing.
	 *
	 * Recognised $data keys:
	 *   title, content, status, author        -> WP post row
	 *   directory_type_id, plan_id, lat, lng,
	 *   price, expires_at, meta (array)        -> listing_data
	 *   category|location|tag (term IDs)       -> taxonomies + denormalised facets
	 *   facets (typed rows) | fields+defs      -> field_index
	 *
	 * @param array<string,mixed> $data Listing data (caller sanitises first).
	 * @return int New listing (post) ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => ListingPostType::POST_TYPE,
				'post_title'   => (string) ( $data['title'] ?? '' ),
				'post_content' => (string) ( $data['content'] ?? '' ),
				'post_status'  => (string) ( $data['status'] ?? 'pending' ),
				'post_author'  => (int) ( $data['author'] ?? get_current_user_id() ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			return 0;
		}

		$post_id = (int) $post_id;

		$this->writeData( $post_id, $data );
		$this->writeTerms( $post_id, $data );
		$this->reindex( $post_id, $data );

		return $post_id;
	}

	/**
	 * Update an existing listing. Only the keys present in $data are touched.
	 *
	 * @param int                 $listing_id Listing (post) ID.
	 * @param array<string,mixed> $data       Partial listing data.
	 */
	public function update( int $listing_id, array $data ): bool {
		if ( ! $this->exists( $listing_id ) ) {
			return false;
		}

		$post_fields = array();
		if ( array_key_exists( 'title', $data ) ) {
			$post_fields['post_title'] = (string) $data['title'];
		}
		if ( array_key_exists( 'content', $data ) ) {
			$post_fields['post_content'] = (string) $data['content'];
		}
		if ( array_key_exists( 'status', $data ) ) {
			$post_fields['post_status'] = (string) $data['status'];
		}
		if ( array_key_exists( 'author', $data ) ) {
			$post_fields['post_author'] = (int) $data['author'];
		}

		if ( $post_fields ) {
			$post_fields['ID'] = $listing_id;
			$result            = wp_update_post( $post_fields, true );
			if ( is_wp_error( $result ) ) {
				return false;
			}
		}

		$this->writeData( $listing_id, $data );
		$this->writeTerms( $listing_id, $data );

		// Only rebuild the index when facet-affecting data was provided.
		if ( isset( $data['facets'] ) || isset( $data['fields'] ) || $this->hasTermData( $data ) ) {
			$this->reindex( $listing_id, $data );
		}

		return true;
	}

	/**
	 * Hydrate a full listing: post + listing_data + decoded meta + facets + terms.
	 *
	 * @param int $listing_id Listing (post) ID.
	 * @return array<string,mixed>|null Null if not a listing.
	 */
	public function find( int $listing_id ): ?array {
		$post = get_post( $listing_id );
		if ( ! $post || ListingPostType::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table( 'listing_data' ) . ' WHERE listing_id = %d',
				$listing_id
			),
			ARRAY_A
		);

		$facets = $this->db->get_results(
			$this->db->prepare(
				'SELECT field_key, value_text, value_num, value_date FROM ' . $this->table( 'field_index' ) . ' WHERE listing_id = %d',
				$listing_id
			),
			ARRAY_A
		);

		$meta = array();
		if ( ! empty( $row['meta'] ) ) {
			$decoded = json_decode( (string) $row['meta'], true );
			$meta    = is_array( $decoded ) ? $decoded : array();
		}

		return array(
			'id'         => $listing_id,
			'title'      => $post->post_title,
			'slug'       => $post->post_name,
			'status'     => $post->post_status,
			'author'     => (int) $post->post_author,
			'content'    => $post->post_content,
			'data'       => $row ?: array(),
			'meta'       => $meta,
			'facets'     => $facets ?: array(),
			'categories' => wp_get_object_terms( $listing_id, Taxonomies::CATEGORY, array( 'fields' => 'ids' ) ),
			'locations'  => wp_get_object_terms( $listing_id, Taxonomies::LOCATION, array( 'fields' => 'ids' ) ),
			'tags'       => wp_get_object_terms( $listing_id, Taxonomies::TAG, array( 'fields' => 'ids' ) ),
		);
	}

	/**
	 * Delete a listing and all of its custom-table rows.
	 *
	 * @param int $listing_id Listing (post) ID.
	 */
	public function delete( int $listing_id ): bool {
		if ( ! $this->exists( $listing_id ) ) {
			return false;
		}

		$this->indexer->delete( $listing_id );
		$this->db->delete( $this->table( 'listing_data' ), array( 'listing_id' => $listing_id ), array( '%d' ) );

		$deleted = wp_delete_post( $listing_id, true );

		return (bool) $deleted;
	}

	/**
	 * Run a faceted search entirely against the custom tables.
	 *
	 * @param SearchQuery $query The search specification.
	 */
	public function search( SearchQuery $query ): SearchResult {
		[ $sql, $args ]               = $this->query_builder->build( $query );
		[ $count_sql, $count_args ]   = $this->query_builder->buildCount( $query );

		$items = $this->db->get_results( $this->prepare( $sql, $args ) );
		$total = (int) $this->db->get_var( $this->prepare( $count_sql, $count_args ) );

		return new SearchResult(
			is_array( $items ) ? $items : array(),
			$total,
			max( 1, $query->page ),
			$query->perPageClamped()
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Internals
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Insert or update the listing_data row.
	 *
	 * @param int                 $listing_id Listing ID.
	 * @param array<string,mixed> $data       Listing data.
	 */
	private function writeData( int $listing_id, array $data ): void {
		$now    = current_time( 'mysql', true );
		$table  = $this->table( 'listing_data' );
		$exists = $this->db->get_var(
			$this->db->prepare( 'SELECT 1 FROM ' . $table . ' WHERE listing_id = %d', $listing_id )
		);

		$fields = array();
		$format = array();

		$map = array(
			'directory_type_id' => '%d',
			'plan_id'           => '%d',
			'lat'               => '%f',
			'lng'               => '%f',
			'price'             => '%f',
			'is_featured'       => '%d',
			'featured_until'    => '%s',
			'expires_at'        => '%s',
			'claim_status'      => '%s',
			'view_count'        => '%d',
		);

		foreach ( $map as $key => $fmt ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = $data[ $key ];
				$format[]       = $fmt;
			}
		}

		// Precompute the geohash whenever coordinates are supplied.
		if ( array_key_exists( 'lat', $data ) && array_key_exists( 'lng', $data )
			&& null !== $data['lat'] && null !== $data['lng'] ) {
			$fields['geohash'] = Geohash::encode( (float) $data['lat'], (float) $data['lng'] );
			$format[]          = '%s';
		}

		if ( array_key_exists( 'meta', $data ) ) {
			$fields['meta'] = wp_json_encode( $data['meta'] );
			$format[]       = '%s';
		}

		$fields['updated_at'] = $now;
		$format[]             = '%s';

		if ( ! $exists ) {
			$fields['listing_id'] = $listing_id;
			$format[]             = '%d';
			$fields['created_at'] = $now;
			$format[]             = '%s';
			$this->db->insert( $table, $fields, $format );
		} else {
			$this->db->update( $table, $fields, array( 'listing_id' => $listing_id ), $format, array( '%d' ) );
		}
	}

	/**
	 * Assign taxonomy terms from the supplied data.
	 *
	 * @param int                 $listing_id Listing ID.
	 * @param array<string,mixed> $data       Listing data.
	 */
	private function writeTerms( int $listing_id, array $data ): void {
		$taxonomies = array(
			'category' => Taxonomies::CATEGORY,
			'location' => Taxonomies::LOCATION,
			'tag'      => Taxonomies::TAG,
		);

		foreach ( $taxonomies as $key => $taxonomy ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$term_ids = array_map( 'intval', (array) $data[ $key ] );
			wp_set_object_terms( $listing_id, $term_ids, $taxonomy, false );
		}
	}

	/**
	 * Rebuild the facet index for a listing from facets + denormalised terms.
	 *
	 * @param int                 $listing_id Listing ID.
	 * @param array<string,mixed> $data       Listing data.
	 */
	private function reindex( int $listing_id, array $data ): void {
		$rows = array();

		// Explicit typed rows, or raw field values + definitions.
		if ( isset( $data['facets'] ) && is_array( $data['facets'] ) ) {
			$rows = $data['facets'];
		} elseif ( isset( $data['fields'] ) && is_array( $data['fields'] ) ) {
			$rows = FieldIndexer::buildRows( $data['fields'], (array) ( $data['field_defs'] ?? array() ) );
		}

		// Denormalise taxonomy terms so they can be faceted on like any field.
		$rows = array_merge( $rows, $this->termFacetRows( $listing_id, $data ) );

		$this->indexer->replace( $listing_id, $rows );
	}

	/**
	 * Build denormalised facet rows for assigned taxonomy terms.
	 *
	 * @param int                 $listing_id Listing ID.
	 * @param array<string,mixed> $data       Listing data.
	 * @return array<int,array{field_key:string,value_num:float}>
	 */
	private function termFacetRows( int $listing_id, array $data ): array {
		$rows = array();

		$map = array(
			Taxonomies::CATEGORY => 'category',
			Taxonomies::LOCATION => 'location',
			Taxonomies::TAG      => 'tag',
		);

		foreach ( $map as $taxonomy => $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$term_ids = array_map( 'intval', (array) $data[ $key ] );
			} else {
				$term_ids = wp_get_object_terms( $listing_id, $taxonomy, array( 'fields' => 'ids' ) );
				$term_ids = is_wp_error( $term_ids ) ? array() : array_map( 'intval', $term_ids );
			}

			foreach ( $term_ids as $term_id ) {
				$rows[] = array(
					'field_key' => $taxonomy,
					'value_num' => (float) $term_id,
				);
			}
		}

		return $rows;
	}

	/**
	 * Whether the data carries any taxonomy assignment.
	 *
	 * @param array<string,mixed> $data Listing data.
	 */
	private function hasTermData( array $data ): bool {
		return array_key_exists( 'category', $data )
			|| array_key_exists( 'location', $data )
			|| array_key_exists( 'tag', $data );
	}

	/**
	 * Whether a listing post exists.
	 *
	 * @param int $listing_id Listing ID.
	 */
	private function exists( int $listing_id ): bool {
		$post = get_post( $listing_id );

		return $post && ListingPostType::POST_TYPE === $post->post_type;
	}

	/**
	 * Prepare a statement only when it carries args (avoids prepare() notices).
	 *
	 * @param string            $sql  SQL with placeholders.
	 * @param array<int,mixed>  $args Bind args.
	 */
	private function prepare( string $sql, array $args ): string {
		if ( empty( $args ) ) {
			return $sql;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->db->prepare( $sql, $args );
	}

	/**
	 * Fully-qualified Lodestar table name.
	 *
	 * @param string $name Bare table name.
	 */
	private function table( string $name ): string {
		return $this->db->prefix . 'lodestar_' . $name;
	}
}
