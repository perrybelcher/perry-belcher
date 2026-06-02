<?php
/**
 * Listing claim → verify → ownership transfer.
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
 * Manages the `lodestar_claims` lifecycle.
 *
 * A user claims an unclaimed listing (with evidence); an admin approves, which
 * transfers post ownership and marks the listing claimed; or rejects, which
 * returns the listing to unclaimed. Transitions are guarded by a pure predicate.
 */
final class ClaimManager {

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( private \wpdb $db ) {}

	/**
	 * Open a claim on a listing.
	 *
	 * @param int                 $listing_id Listing ID.
	 * @param int                 $user_id    Claiming user.
	 * @param array<string,mixed> $evidence   Supporting evidence.
	 * @param string              $verified_via email|phone|doc.
	 * @return int New claim ID, or 0 on failure.
	 */
	public function claim( int $listing_id, int $user_id, array $evidence = array(), string $verified_via = 'email' ): int {
		$post = get_post( $listing_id );
		if ( ! $post || ListingPostType::POST_TYPE !== $post->post_type || $user_id <= 0 ) {
			return 0;
		}

		$ok = $this->db->insert(
			$this->table(),
			array(
				'listing_id'    => $listing_id,
				'user_id'       => $user_id,
				'status'        => 'pending',
				'evidence_json' => wp_json_encode( $evidence ),
				'verified_via'  => in_array( $verified_via, array( 'email', 'phone', 'doc' ), true ) ? $verified_via : 'email',
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return 0;
		}

		$this->set_listing_claim_status( $listing_id, 'pending' );

		return (int) $this->db->insert_id;
	}

	/**
	 * Approve a claim: transfer ownership and mark the listing claimed.
	 *
	 * @param int $claim_id Claim ID.
	 */
	public function approve( int $claim_id ): bool {
		$claim = $this->find( $claim_id );
		if ( ! $claim || ! self::can_transition( (string) $claim['status'], 'approve' ) ) {
			return false;
		}

		$listing_id = (int) $claim['listing_id'];
		$user_id    = (int) $claim['user_id'];

		// Transfer ownership of the post to the verified claimant.
		wp_update_post( array( 'ID' => $listing_id, 'post_author' => $user_id ) );
		$this->set_listing_claim_status( $listing_id, 'claimed' );

		return $this->resolve( $claim_id, 'approved' );
	}

	/**
	 * Reject a claim and return the listing to unclaimed.
	 *
	 * @param int $claim_id Claim ID.
	 */
	public function reject( int $claim_id ): bool {
		$claim = $this->find( $claim_id );
		if ( ! $claim || ! self::can_transition( (string) $claim['status'], 'reject' ) ) {
			return false;
		}

		$this->set_listing_claim_status( (int) $claim['listing_id'], 'unclaimed' );

		return $this->resolve( $claim_id, 'rejected' );
	}

	/**
	 * Find a claim by ID.
	 *
	 * @param int $claim_id Claim ID.
	 * @return array<string,mixed>|null
	 */
	public function find( int $claim_id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $claim_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Pending claims (for admin review).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function pending(): array {
		$rows = $this->db->get_results(
			"SELECT * FROM {$this->table()} WHERE status = 'pending' ORDER BY created_at ASC",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether a claim in $status may undergo $action (pure).
	 *
	 * @param string $status Current status.
	 * @param string $action approve|reject.
	 */
	public static function can_transition( string $status, string $action ): bool {
		// Only pending claims can be approved or rejected.
		return 'pending' === $status && in_array( $action, array( 'approve', 'reject' ), true );
	}

	/**
	 * Mark a claim resolved with a final status.
	 *
	 * @param int    $claim_id Claim ID.
	 * @param string $status   approved|rejected.
	 */
	private function resolve( int $claim_id, string $status ): bool {
		return false !== $this->db->update(
			$this->table(),
			array( 'status' => $status, 'resolved_at' => current_time( 'mysql', true ) ),
			array( 'id' => $claim_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Update the listing's denormalised claim_status column.
	 *
	 * @param int    $listing_id Listing ID.
	 * @param string $status     unclaimed|pending|claimed.
	 */
	private function set_listing_claim_status( int $listing_id, string $status ): void {
		$this->db->update(
			$this->db->prefix . 'lodestar_listing_data',
			array( 'claim_status' => $status ),
			array( 'listing_id' => $listing_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Fully-qualified claims table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'lodestar_claims';
	}
}
