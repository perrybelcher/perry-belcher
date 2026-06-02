<?php
/**
 * Order recording + status transitions.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence gateway for the `lodestar_orders` table.
 *
 * Orders move through a small allowlisted status machine; gateway webhooks are
 * the only thing that flips an order to paid/failed/refunded, keyed by the
 * gateway reference.
 */
final class OrderManager {

	public const STATUSES = array( 'pending', 'paid', 'failed', 'refunded' );

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( private \wpdb $db ) {}

	/**
	 * Record a new (pending) order.
	 *
	 * @param array<string,mixed> $data user_id, plan_id, listing_id, gateway, gateway_ref, amount.
	 * @return int New order ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		$ok = $this->db->insert(
			$this->table(),
			array(
				'user_id'     => (int) ( $data['user_id'] ?? 0 ),
				'plan_id'     => isset( $data['plan_id'] ) ? (int) $data['plan_id'] : null,
				'listing_id'  => isset( $data['listing_id'] ) ? (int) $data['listing_id'] : null,
				'gateway'     => (string) ( $data['gateway'] ?? '' ),
				'gateway_ref' => isset( $data['gateway_ref'] ) ? (string) $data['gateway_ref'] : null,
				'amount'      => (float) ( $data['amount'] ?? 0 ),
				'status'      => self::normalize_status( (string) ( $data['status'] ?? 'pending' ) ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%f', '%s', '%s' )
		);

		return $ok ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Find an order by ID.
	 *
	 * @param int $id Order ID.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Update an order's status by gateway reference.
	 *
	 * @param string $gateway_ref Gateway transaction reference.
	 * @param string $status      New status.
	 */
	public function update_status_by_ref( string $gateway_ref, string $status ): bool {
		if ( '' === $gateway_ref ) {
			return false;
		}

		return false !== $this->db->update(
			$this->table(),
			array( 'status' => self::normalize_status( $status ) ),
			array( 'gateway_ref' => $gateway_ref ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Update an order's status by ID, and attach/refresh its gateway ref.
	 *
	 * @param int    $id          Order ID.
	 * @param string $status      New status.
	 * @param string $gateway_ref Gateway reference to set (optional).
	 */
	public function update_status( int $id, string $status, string $gateway_ref = '' ): bool {
		$fields = array( 'status' => self::normalize_status( $status ) );
		$format = array( '%s' );

		if ( '' !== $gateway_ref ) {
			$fields['gateway_ref'] = $gateway_ref;
			$format[]              = '%s';
		}

		return false !== $this->db->update( $this->table(), $fields, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/**
	 * Coerce a status to the allowlist (defaults to pending).
	 *
	 * @param string $status Candidate status.
	 */
	public static function normalize_status( string $status ): string {
		return in_array( $status, self::STATUSES, true ) ? $status : 'pending';
	}

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'lodestar_orders';
	}
}
