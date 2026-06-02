<?php
/**
 * Featured-listing enforcement + expiry sweep.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks listings featured (optionally until a date) and sweeps expired ones.
 *
 * The sweep runs on the existing daily maintenance cron: it un-features any
 * listing whose `featured_until` has passed, and unpublishes listings whose
 * `expires_at` has passed. The active/expired predicates are pure and tested.
 */
final class FeaturedManager {

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( private \wpdb $db ) {}

	/**
	 * Feature a listing, optionally for a fixed number of days.
	 *
	 * @param int      $listing_id Listing ID.
	 * @param int|null $days       Days to feature (null = indefinitely).
	 */
	public function feature( int $listing_id, ?int $days = null ): bool {
		$until = null;
		if ( null !== $days && $days > 0 ) {
			$until = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
		}

		return false !== $this->db->update(
			$this->table(),
			array( 'is_featured' => 1, 'featured_until' => $until ),
			array( 'listing_id' => $listing_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Remove featured status from a listing.
	 *
	 * @param int $listing_id Listing ID.
	 */
	public function unfeature( int $listing_id ): bool {
		return false !== $this->db->update(
			$this->table(),
			array( 'is_featured' => 0, 'featured_until' => null ),
			array( 'listing_id' => $listing_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Sweep: un-feature expired featured listings and unpublish expired ones.
	 *
	 * @return array{unfeatured:int,expired:int} Counts of affected rows.
	 */
	public function sweep(): array {
		$now = current_time( 'mysql', true );

		$unfeatured = (int) $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table()} SET is_featured = 0
				 WHERE is_featured = 1 AND featured_until IS NOT NULL AND featured_until < %s",
				$now
			)
		);

		// Unpublish listings past their expiry.
		$expired_ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT listing_id FROM {$this->table()} WHERE expires_at IS NOT NULL AND expires_at < %s",
				$now
			)
		);

		$expired = 0;
		foreach ( (array) $expired_ids as $listing_id ) {
			$post = get_post( (int) $listing_id );
			if ( $post && 'publish' === $post->post_status ) {
				wp_update_post( array( 'ID' => (int) $listing_id, 'post_status' => 'draft' ) );
				++$expired;
			}
		}

		return array( 'unfeatured' => $unfeatured, 'expired' => $expired );
	}

	/**
	 * Whether a featured window is still active at $now (pure).
	 *
	 * A null/empty `featured_until` means indefinitely featured.
	 *
	 * @param string|null $featured_until Datetime string or null.
	 * @param string      $now            Reference datetime (GMT 'Y-m-d H:i:s').
	 */
	public static function is_active( ?string $featured_until, string $now ): bool {
		if ( null === $featured_until || '' === $featured_until ) {
			return true;
		}

		return strtotime( $featured_until ) > strtotime( $now );
	}

	/**
	 * Whether a record with $expires_at is past expiry at $now (pure).
	 *
	 * @param string|null $expires_at Datetime string or null.
	 * @param string      $now        Reference datetime.
	 */
	public static function is_expired( ?string $expires_at, string $now ): bool {
		if ( null === $expires_at || '' === $expires_at ) {
			return false;
		}

		return strtotime( $expires_at ) < strtotime( $now );
	}

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'lodestar_listing_data';
	}
}
