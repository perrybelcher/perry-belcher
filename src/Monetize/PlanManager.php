<?php
/**
 * CRUD + limit logic for monetization plans.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize;

use Lodestar\PostType\ListingPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence gateway for the `lodestar_plans` table, plus the listing-limit
 * enforcement that gates submissions.
 */
final class PlanManager {

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( private \wpdb $db ) {}

	/**
	 * Create a plan.
	 *
	 * @param array<string,mixed> $data Plan attributes.
	 * @return int New ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		$slug = sanitize_key( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug || $this->findBySlug( $slug ) ) {
			return 0;
		}

		$ok = $this->db->insert(
			$this->table(),
			array(
				'slug'              => $slug,
				'label'             => (string) ( $data['label'] ?? '' ),
				'price'             => (float) ( $data['price'] ?? 0 ),
				'billing'           => 'recurring' === ( $data['billing'] ?? '' ) ? 'recurring' : 'one_time',
				'interval_unit'     => isset( $data['interval'] ) ? (string) $data['interval'] : null,
				'listing_limit'     => isset( $data['listing_limit'] ) && null !== $data['listing_limit'] ? (int) $data['listing_limit'] : null,
				'featured_included' => ! empty( $data['featured_included'] ) ? 1 : 0,
				'duration_days'     => isset( $data['duration_days'] ) && null !== $data['duration_days'] ? (int) $data['duration_days'] : null,
				'capabilities_json' => wp_json_encode( (array) ( $data['capabilities'] ?? array() ) ),
				'gateway_meta_json' => wp_json_encode( (array) ( $data['gateway_meta'] ?? array() ) ),
			),
			array( '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		return $ok ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Delete a plan.
	 *
	 * @param int $id Plan ID.
	 */
	public function delete( int $id ): bool {
		return (bool) $this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Find a plan by ID.
	 *
	 * @param int $id Plan ID.
	 */
	public function find( int $id ): ?Plan {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ? Plan::fromRow( $row ) : null;
	}

	/**
	 * Find a plan by slug.
	 *
	 * @param string $slug Plan slug.
	 */
	public function findBySlug( string $slug ): ?Plan {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE slug = %s', $slug ),
			ARRAY_A
		);

		return $row ? Plan::fromRow( $row ) : null;
	}

	/**
	 * All plans, cheapest first.
	 *
	 * @return Plan[]
	 */
	public function all(): array {
		$rows = $this->db->get_results( 'SELECT * FROM ' . $this->table() . ' ORDER BY price ASC', ARRAY_A );

		return array_map( array( Plan::class, 'fromRow' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Whether a listing count is within a plan limit (pure).
	 *
	 * A null limit means unlimited; otherwise the user may add while their
	 * count is strictly below the limit.
	 *
	 * @param int      $current_count Existing listing count.
	 * @param int|null $limit         Plan limit (null = unlimited).
	 */
	public static function within_limit( int $current_count, ?int $limit ): bool {
		if ( null === $limit ) {
			return true;
		}

		return $current_count < $limit;
	}

	/**
	 * Whether a user may submit another listing under a plan.
	 *
	 * @param int  $user_id User ID.
	 * @param Plan $plan    The plan.
	 */
	public function can_user_submit( int $user_id, Plan $plan ): bool {
		return self::within_limit( $this->count_user_listings( $user_id ), $plan->listingLimit );
	}

	/**
	 * Count a user's listings (any status except trash).
	 *
	 * @param int $user_id User ID.
	 */
	public function count_user_listings( int $user_id ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->db->posts} WHERE post_author = %d AND post_type = %s AND post_status != 'trash'",
				$user_id,
				ListingPostType::POST_TYPE
			)
		);
	}

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'lodestar_plans';
	}
}
