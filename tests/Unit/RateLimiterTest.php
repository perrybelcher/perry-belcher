<?php
/**
 * Unit tests for the fixed-window RateLimiter.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Api\RateLimiter;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! class_exists( RateLimiter::class ) ) {
	require_once dirname( __DIR__, 2 ) . '/src/Api/RateLimiter.php';
}

/**
 * @covers \Lodestar\Api\RateLimiter
 */
final class RateLimiterTest extends TestCase {

	/** @var array<string,array> */
	private array $store = array();
	private int $now = 1000;

	private function limiter(): RateLimiter {
		return new RateLimiter(
			fn ( string $k ) => $this->store[ $k ] ?? null,
			function ( string $k, array $v, int $ttl ): void {
				$this->store[ $k ] = $v;
			},
			fn () => $this->now
		);
	}

	public function test_allows_up_to_limit_then_blocks(): void {
		$limiter = $this->limiter();

		$first  = $limiter->check( 'k', 3, 60 );
		$second = $limiter->check( 'k', 3, 60 );
		$third  = $limiter->check( 'k', 3, 60 );
		$fourth = $limiter->check( 'k', 3, 60 );

		$this->assertTrue( $first['allowed'] );
		$this->assertSame( 2, $first['remaining'] );
		$this->assertTrue( $second['allowed'] );
		$this->assertTrue( $third['allowed'] );
		$this->assertSame( 0, $third['remaining'] );

		$this->assertFalse( $fourth['allowed'] );
		$this->assertGreaterThan( 0, $fourth['retry_after'] );
	}

	public function test_window_resets_after_expiry(): void {
		$limiter = $this->limiter();

		$limiter->check( 'k', 1, 60 );
		$this->assertFalse( $limiter->check( 'k', 1, 60 )['allowed'] );

		// Advance past the window.
		$this->now += 61;
		$this->assertTrue( $limiter->check( 'k', 1, 60 )['allowed'] );
	}

	public function test_keys_are_independent(): void {
		$limiter = $this->limiter();
		$limiter->check( 'a', 1, 60 );

		$this->assertFalse( $limiter->check( 'a', 1, 60 )['allowed'] );
		$this->assertTrue( $limiter->check( 'b', 1, 60 )['allowed'] );
	}
}
