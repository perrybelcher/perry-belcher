<?php
/**
 * Monetization plan value object.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One monetization plan (free, paid one-time, or recurring subscription).
 */
final class Plan {

	/**
	 * @param int                 $id               Row ID.
	 * @param string              $slug             Machine slug.
	 * @param string              $label            Display label.
	 * @param float               $price            Price in the store currency.
	 * @param string              $billing          one_time|recurring.
	 * @param string|null         $interval         month|year|null.
	 * @param int|null            $listingLimit     Max listings (null = unlimited).
	 * @param bool                $featuredIncluded Whether listings are featured.
	 * @param int|null            $durationDays     Listing lifetime (null = forever).
	 * @param array<string,mixed> $capabilities     Extra capability flags.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $slug,
		public readonly string $label,
		public readonly float $price = 0.0,
		public readonly string $billing = 'one_time',
		public readonly ?string $interval = null,
		public readonly ?int $listingLimit = null,
		public readonly bool $featuredIncluded = false,
		public readonly ?int $durationDays = null,
		public readonly array $capabilities = array(),
	) {}

	/**
	 * Whether this is a free plan.
	 */
	public function isFree(): bool {
		return $this->price <= 0.0;
	}

	/**
	 * Hydrate from a DB row.
	 *
	 * @param array<string,mixed> $row Associative DB row.
	 */
	public static function fromRow( array $row ): self {
		$caps = array();
		if ( ! empty( $row['capabilities_json'] ) ) {
			$decoded = json_decode( (string) $row['capabilities_json'], true );
			$caps    = is_array( $decoded ) ? $decoded : array();
		}

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['slug'] ?? '' ),
			(string) ( $row['label'] ?? '' ),
			(float) ( $row['price'] ?? 0 ),
			(string) ( $row['billing'] ?? 'one_time' ),
			isset( $row['interval_unit'] ) && '' !== (string) $row['interval_unit'] ? (string) $row['interval_unit'] : null,
			isset( $row['listing_limit'] ) && null !== $row['listing_limit'] ? (int) $row['listing_limit'] : null,
			! empty( $row['featured_included'] ),
			isset( $row['duration_days'] ) && null !== $row['duration_days'] ? (int) $row['duration_days'] : null,
			$caps,
		);
	}
}
