<?php
/**
 * Fixed-window rate limiter.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small fixed-window rate limiter for write/AI endpoints.
 *
 * The store and clock are injected, so the windowing logic is unit-testable
 * without WordPress; {@see self::from_transients()} backs it with the options
 * cache in production.
 */
final class RateLimiter {

	/** @var callable */
	private $get;
	/** @var callable */
	private $set;
	/** @var callable */
	private $clock;

	/**
	 * @param callable $get   callable(string $key): ?array — read a bucket.
	 * @param callable $set   callable(string $key, array $bucket, int $ttl): void.
	 * @param callable $clock callable(): int — current unix time.
	 */
	public function __construct( callable $get, callable $set, ?callable $clock = null ) {
		$this->get   = $get;
		$this->set   = $set;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Build a limiter backed by WordPress transients.
	 */
	public static function from_transients(): self {
		return new self(
			static function ( string $key ) {
				$value = get_transient( $key );
				return is_array( $value ) ? $value : null;
			},
			static function ( string $key, array $bucket, int $ttl ): void {
				set_transient( $key, $bucket, $ttl );
			}
		);
	}

	/**
	 * Consume one unit against $key, returning the decision + metadata.
	 *
	 * @param string $key    Bucket key (e.g. "lodestar_rl_create_42").
	 * @param int    $limit  Max requests per window.
	 * @param int    $window Window length in seconds.
	 * @return array{allowed:bool,remaining:int,reset:int,retry_after:int}
	 */
	public function check( string $key, int $limit, int $window ): array {
		$now    = (int) ( $this->clock )();
		$bucket = ( $this->get )( $key );

		if ( ! is_array( $bucket ) || ! isset( $bucket['reset'], $bucket['count'] ) || $now >= (int) $bucket['reset'] ) {
			$bucket = array( 'count' => 0, 'reset' => $now + $window );
		}

		$reset = (int) $bucket['reset'];

		if ( (int) $bucket['count'] >= $limit ) {
			return array(
				'allowed'     => false,
				'remaining'   => 0,
				'reset'       => $reset,
				'retry_after' => max( 1, $reset - $now ),
			);
		}

		$bucket['count'] = (int) $bucket['count'] + 1;
		( $this->set )( $key, $bucket, max( 1, $reset - $now ) );

		return array(
			'allowed'     => true,
			'remaining'   => max( 0, $limit - (int) $bucket['count'] ),
			'reset'       => $reset,
			'retry_after' => 0,
		);
	}
}
