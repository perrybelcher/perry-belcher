<?php
/**
 * Reads/writes for listing reviews.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence for `lodestar_reviews`. New reviews land pending; approving (or
 * deleting) recomputes the denormalised rating average/count on the listing.
 */
final class ReviewRepository {

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( private \wpdb $db ) {}

	/**
	 * Create a review (clamped 1–5, status pending).
	 *
	 * @param array<string,mixed> $data listing_id, user_id, rating, title, body.
	 * @return int New review ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		$rating = max( 1, min( 5, (int) ( $data['rating'] ?? 0 ) ) );

		$ok = $this->db->insert(
			$this->table(),
			array(
				'listing_id' => (int) ( $data['listing_id'] ?? 0 ),
				'user_id'    => isset( $data['user_id'] ) && $data['user_id'] ? (int) $data['user_id'] : null,
				'rating'     => $rating,
				'title'      => isset( $data['title'] ) ? (string) $data['title'] : null,
				'body'       => isset( $data['body'] ) ? (string) $data['body'] : null,
				'status'     => 'pending',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Approve a review and recompute the listing's rating.
	 *
	 * @param int $id Review ID.
	 */
	public function approve( int $id ): bool {
		$listing_id = (int) $this->db->get_var(
			$this->db->prepare( 'SELECT listing_id FROM ' . $this->table() . ' WHERE id = %d', $id )
		);
		if ( 0 === $listing_id ) {
			return false;
		}

		$this->db->update( $this->table(), array( 'status' => 'approved' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		$this->recompute( $listing_id );

		return true;
	}

	/**
	 * Recompute rating_avg/rating_count from approved reviews.
	 *
	 * @param int $listing_id Listing ID.
	 */
	public function recompute( int $listing_id ): void {
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT COUNT(*) AS c, AVG(rating) AS a FROM ' . $this->table() . " WHERE listing_id = %d AND status = 'approved'",
				$listing_id
			),
			ARRAY_A
		);

		$count = (int) ( $row['c'] ?? 0 );
		$avg   = $count > 0 ? round( (float) $row['a'], 2 ) : 0.0;

		$this->db->update(
			$this->db->prefix . 'lodestar_listing_data',
			array( 'rating_avg' => $avg, 'rating_count' => $count ),
			array( 'listing_id' => $listing_id ),
			array( '%f', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Fully-qualified reviews table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'lodestar_reviews';
	}
}
