<?php
/**
 * Unit tests for StripeGateway (signature verification + status mapping).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Monetize\Gateways\StripeGateway;
use Lodestar\Monetize\Gateways\WebhookEvent;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! class_exists( StripeGateway::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src/Monetize/Gateways';
	require_once $lodestar_src . '/GatewayInterface.php';
	require_once $lodestar_src . '/CheckoutSession.php';
	require_once $lodestar_src . '/WebhookEvent.php';
	require_once $lodestar_src . '/StripeGateway.php';
}

/**
 * @covers \Lodestar\Monetize\Gateways\StripeGateway
 */
final class StripeGatewayTest extends TestCase {

	private const SECRET = 'whsec_test_secret';

	/**
	 * Produce a valid Stripe-Signature header for a payload at $ts.
	 */
	private function sign( string $payload, int $ts, string $secret = self::SECRET ): string {
		$sig = hash_hmac( 'sha256', $ts . '.' . $payload, $secret );
		return "t={$ts},v1={$sig}";
	}

	public function test_valid_signature_passes(): void {
		$now     = 1_700_000_000;
		$payload = '{"id":"evt_1"}';
		$header  = $this->sign( $payload, $now );

		$this->assertTrue( StripeGateway::verify_signature( $payload, $header, self::SECRET, 300, $now ) );
	}

	public function test_tampered_payload_fails(): void {
		$now    = 1_700_000_000;
		$header = $this->sign( '{"id":"evt_1"}', $now );

		$this->assertFalse( StripeGateway::verify_signature( '{"id":"evt_TAMPERED"}', $header, self::SECRET, 300, $now ) );
	}

	public function test_wrong_secret_fails(): void {
		$now     = 1_700_000_000;
		$payload = '{"id":"evt_1"}';
		$header  = $this->sign( $payload, $now, 'whsec_attacker' );

		$this->assertFalse( StripeGateway::verify_signature( $payload, $header, self::SECRET, 300, $now ) );
	}

	public function test_stale_timestamp_fails(): void {
		$payload = '{"id":"evt_1"}';
		$signed  = 1_700_000_000;
		$now     = $signed + 10_000; // beyond tolerance.
		$header  = $this->sign( $payload, $signed );

		$this->assertFalse( StripeGateway::verify_signature( $payload, $header, self::SECRET, 300, $now ) );
	}

	public function test_malformed_header_fails(): void {
		$this->assertFalse( StripeGateway::verify_signature( '{}', 'garbage', self::SECRET ) );
		$this->assertFalse( StripeGateway::verify_signature( '{}', '', self::SECRET ) );
	}

	public function test_status_mapping(): void {
		$this->assertSame( 'paid', StripeGateway::map_status( 'checkout.session.completed' ) );
		$this->assertSame( 'failed', StripeGateway::map_status( 'payment_intent.payment_failed' ) );
		$this->assertSame( 'refunded', StripeGateway::map_status( 'charge.refunded' ) );
		$this->assertNull( StripeGateway::map_status( 'customer.created' ) );
	}

	public function test_to_minor_units(): void {
		$this->assertSame( 1250, StripeGateway::to_minor_units( 12.50 ) );
		$this->assertSame( 10, StripeGateway::to_minor_units( 0.10 ) );
		$this->assertSame( 0, StripeGateway::to_minor_units( 0.0 ) );
	}

	public function test_checkout_params_shape(): void {
		$params = StripeGateway::checkout_params(
			array( 'order_id' => 42, 'amount' => 19.99, 'currency' => 'USD', 'description' => 'Pro plan' ),
			array( 'success_url' => 'https://x.test/ok', 'cancel_url' => 'https://x.test/no' )
		);

		$this->assertSame( '42', $params['client_reference_id'] );
		$this->assertSame( 1999, $params['line_items[0][price_data][unit_amount]'] );
		$this->assertSame( 'usd', $params['line_items[0][price_data][currency]'] );
	}

	public function test_parse_webhook_returns_event_on_valid_signature(): void {
		$now     = 1_700_000_000;
		$payload = wp_json_encode_compat(
			array(
				'type' => 'checkout.session.completed',
				'data' => array( 'object' => array( 'id' => 'cs_123', 'client_reference_id' => '77' ) ),
			)
		);
		$gateway = new StripeGateway( 'sk_test', self::SECRET );

		$event = $gateway->parse_webhook( $payload, array( 'stripe-signature' => $this->sign( $payload, time() ) ) );

		$this->assertInstanceOf( WebhookEvent::class, $event );
		$this->assertSame( 'paid', $event->status );
		$this->assertSame( 'cs_123', $event->gatewayRef );
		$this->assertSame( 77, $event->orderId );
	}

	public function test_parse_webhook_rejects_bad_signature(): void {
		$gateway = new StripeGateway( 'sk_test', self::SECRET );
		$this->assertNull( $gateway->parse_webhook( '{"type":"checkout.session.completed"}', array( 'stripe-signature' => 't=1,v1=bad' ) ) );
	}
}

if ( ! function_exists( 'Lodestar\\Tests\\Unit\\wp_json_encode_compat' ) ) {
	function wp_json_encode_compat( $data ): string {
		return (string) json_encode( $data );
	}
}
