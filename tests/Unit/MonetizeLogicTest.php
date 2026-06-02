<?php
/**
 * Unit tests for monetization state/limit logic (pure statics).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Monetize\ClaimManager;
use Lodestar\Monetize\FeaturedManager;
use Lodestar\Monetize\Gateways\PaypalGateway;
use Lodestar\Monetize\Gateways\WooGateway;
use Lodestar\Monetize\OrderManager;
use Lodestar\Monetize\Plan;
use Lodestar\Monetize\PlanManager;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! class_exists( PlanManager::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src';
	require_once $lodestar_src . '/PostType/ListingPostType.php';
	require_once $lodestar_src . '/Monetize/Plan.php';
	require_once $lodestar_src . '/Monetize/PlanManager.php';
	require_once $lodestar_src . '/Monetize/OrderManager.php';
	require_once $lodestar_src . '/Monetize/FeaturedManager.php';
	require_once $lodestar_src . '/Monetize/ClaimManager.php';
	require_once $lodestar_src . '/Monetize/Gateways/GatewayInterface.php';
	require_once $lodestar_src . '/Monetize/Gateways/CheckoutSession.php';
	require_once $lodestar_src . '/Monetize/Gateways/WebhookEvent.php';
	require_once $lodestar_src . '/Monetize/Gateways/PaypalGateway.php';
	require_once $lodestar_src . '/Monetize/Gateways/WooGateway.php';
}

/**
 * @covers \Lodestar\Monetize\PlanManager
 * @covers \Lodestar\Monetize\FeaturedManager
 * @covers \Lodestar\Monetize\ClaimManager
 * @covers \Lodestar\Monetize\OrderManager
 */
final class MonetizeLogicTest extends TestCase {

	public function test_plan_limit(): void {
		$this->assertTrue( PlanManager::within_limit( 0, null ) );    // unlimited
		$this->assertTrue( PlanManager::within_limit( 99, null ) );
		$this->assertTrue( PlanManager::within_limit( 2, 3 ) );
		$this->assertFalse( PlanManager::within_limit( 3, 3 ) );      // at the cap
		$this->assertFalse( PlanManager::within_limit( 0, 0 ) );      // a 0-limit plan
	}

	public function test_plan_dto_from_row(): void {
		$plan = Plan::fromRow(
			array( 'id' => '2', 'slug' => 'pro', 'label' => 'Pro', 'price' => '19.00', 'listing_limit' => '10', 'featured_included' => '1', 'duration_days' => '30' )
		);
		$this->assertSame( 10, $plan->listingLimit );
		$this->assertTrue( $plan->featuredIncluded );
		$this->assertSame( 30, $plan->durationDays );
		$this->assertFalse( $plan->isFree() );
	}

	public function test_featured_active_and_expiry(): void {
		$now = '2026-06-01 12:00:00';
		$this->assertTrue( FeaturedManager::is_active( null, $now ) );                  // permanent
		$this->assertTrue( FeaturedManager::is_active( '2026-07-01 00:00:00', $now ) ); // future
		$this->assertFalse( FeaturedManager::is_active( '2026-05-01 00:00:00', $now ) ); // past

		$this->assertFalse( FeaturedManager::is_expired( null, $now ) );
		$this->assertFalse( FeaturedManager::is_expired( '2026-07-01 00:00:00', $now ) );
		$this->assertTrue( FeaturedManager::is_expired( '2026-05-01 00:00:00', $now ) );
	}

	public function test_claim_transitions(): void {
		$this->assertTrue( ClaimManager::can_transition( 'pending', 'approve' ) );
		$this->assertTrue( ClaimManager::can_transition( 'pending', 'reject' ) );
		$this->assertFalse( ClaimManager::can_transition( 'approved', 'approve' ) );
		$this->assertFalse( ClaimManager::can_transition( 'pending', 'nonsense' ) );
	}

	public function test_order_status_allowlist(): void {
		$this->assertSame( 'paid', OrderManager::normalize_status( 'paid' ) );
		$this->assertSame( 'refunded', OrderManager::normalize_status( 'refunded' ) );
		$this->assertSame( 'pending', OrderManager::normalize_status( 'totally-bogus' ) );
	}

	public function test_paypal_and_woo_status_maps(): void {
		$this->assertSame( 'paid', PaypalGateway::map_status( 'PAYMENT.CAPTURE.COMPLETED' ) );
		$this->assertSame( 'refunded', PaypalGateway::map_status( 'PAYMENT.CAPTURE.REFUNDED' ) );
		$this->assertNull( PaypalGateway::map_status( 'BILLING.SUBSCRIPTION.CREATED' ) );

		$this->assertSame( 'paid', WooGateway::map_status( 'completed' ) );
		$this->assertSame( 'failed', WooGateway::map_status( 'cancelled' ) );
		$this->assertNull( WooGateway::map_status( 'on-hold' ) );
	}

	public function test_paypal_refuses_unverified_webhook(): void {
		// No verifier configured -> never trusts the event.
		$gateway = new PaypalGateway( 'cid', 'secret' );
		$this->assertNull( $gateway->parse_webhook( '{"event_type":"PAYMENT.CAPTURE.COMPLETED"}', array() ) );
	}
}
