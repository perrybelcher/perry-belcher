<?php
/**
 * Stripe Checkout gateway.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native Stripe gateway.
 *
 * The security-critical and reconciliation logic is pure and unit-tested:
 *  - {@see self::verify_signature()} implements Stripe's `Stripe-Signature`
 *    scheme (HMAC-SHA256 over `t.payload`, constant-time compare, tolerance).
 *  - {@see self::map_status()} maps event types to our order statuses, so
 *    refunds and failures are reflected.
 *  - {@see self::to_minor_units()} converts a decimal amount to cents.
 *
 * The checkout API call uses an injectable fetcher (wp_remote_post in
 * production), so the param building is testable without network.
 */
final class StripeGateway implements GatewayInterface {

	/**
	 * Optional HTTP poster: callable(string $url, array $args): ?array.
	 *
	 * @var callable|null
	 */
	private $poster;

	/**
	 * @param string        $secret_key     Stripe secret key.
	 * @param string        $webhook_secret Webhook signing secret.
	 * @param callable|null $poster         Custom HTTP poster (tests).
	 */
	public function __construct(
		private string $secret_key,
		private string $webhook_secret,
		?callable $poster = null,
	) {
		$this->poster = $poster;
	}

	public function id(): string {
		return 'stripe';
	}

	/**
	 * Create a Stripe Checkout Session.
	 *
	 * @param array<string,mixed> $order   order_id, amount, currency, description.
	 * @param array<string,mixed> $context success_url, cancel_url.
	 */
	public function create_checkout( array $order, array $context ): CheckoutSession {
		if ( '' === $this->secret_key ) {
			return CheckoutSession::fail( 'Stripe is not configured.' );
		}

		$params = self::checkout_params( $order, $context );

		$response = $this->post( 'https://api.stripe.com/v1/checkout/sessions', $params );
		if ( ! is_array( $response ) || empty( $response['id'] ) || empty( $response['url'] ) ) {
			return CheckoutSession::fail( 'Stripe checkout could not be created.' );
		}

		return new CheckoutSession( (string) $response['url'], (string) $response['id'] );
	}

	/**
	 * Verify + parse a Stripe webhook.
	 *
	 * @param string               $payload Raw body.
	 * @param array<string,string> $headers Request headers.
	 */
	public function parse_webhook( string $payload, array $headers ): ?WebhookEvent {
		$signature = $headers['stripe-signature'] ?? ( $headers['Stripe-Signature'] ?? '' );

		if ( '' === $this->webhook_secret || ! self::verify_signature( $payload, $signature, $this->webhook_secret ) ) {
			return null;
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return null;
		}

		$status = self::map_status( (string) $event['type'] );
		if ( null === $status ) {
			return null;
		}

		$object = $event['data']['object'] ?? array();
		$ref    = (string) ( $object['id'] ?? '' );
		$our_id = isset( $object['client_reference_id'] ) ? (int) $object['client_reference_id'] : null;

		return new WebhookEvent( $status, $ref, $our_id, (string) $event['type'] );
	}

	/*
	 * --------------------------------------------------------------------- *
	 *  Pure helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Verify a Stripe-Signature header (HMAC-SHA256, constant-time, tolerance).
	 *
	 * @param string   $payload   Raw body.
	 * @param string   $header    Stripe-Signature header value (`t=..,v1=..`).
	 * @param string   $secret    Webhook signing secret.
	 * @param int      $tolerance Allowed clock skew in seconds.
	 * @param int|null $now       Reference timestamp (defaults to time()).
	 */
	public static function verify_signature( string $payload, string $header, string $secret, int $tolerance = 300, ?int $now = null ): bool {
		if ( '' === $header || '' === $secret ) {
			return false;
		}

		$timestamp = 0;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( 0 === $timestamp || empty( $signatures ) ) {
			return false;
		}

		$now = $now ?? time();
		if ( abs( $now - $timestamp ) > $tolerance ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map a Stripe event type to our order status, or null to ignore.
	 *
	 * @param string $event_type Stripe event type.
	 */
	public static function map_status( string $event_type ): ?string {
		return match ( $event_type ) {
			'checkout.session.completed',
			'checkout.session.async_payment_succeeded',
			'payment_intent.succeeded'                  => 'paid',
			'checkout.session.async_payment_failed',
			'payment_intent.payment_failed'             => 'failed',
			'charge.refunded',
			'refund.created',
			'charge.refund.updated'                     => 'refunded',
			default                                     => null,
		};
	}

	/**
	 * Convert a decimal amount to integer minor units (cents).
	 *
	 * @param float $amount Amount in major units.
	 */
	public static function to_minor_units( float $amount ): int {
		return (int) round( $amount * 100 );
	}

	/**
	 * Build Stripe Checkout Session params for an order (pure).
	 *
	 * @param array<string,mixed> $order   order_id, amount, currency, description.
	 * @param array<string,mixed> $context success_url, cancel_url.
	 * @return array<string,mixed>
	 */
	public static function checkout_params( array $order, array $context ): array {
		return array(
			'mode'                                 => 'payment',
			'client_reference_id'                  => (string) ( $order['order_id'] ?? '' ),
			'success_url'                          => (string) ( $context['success_url'] ?? '' ),
			'cancel_url'                           => (string) ( $context['cancel_url'] ?? '' ),
			'line_items[0][quantity]'              => 1,
			'line_items[0][price_data][currency]'  => strtolower( (string) ( $order['currency'] ?? 'usd' ) ),
			'line_items[0][price_data][unit_amount]' => self::to_minor_units( (float) ( $order['amount'] ?? 0 ) ),
			'line_items[0][price_data][product_data][name]' => (string) ( $order['description'] ?? 'Listing plan' ),
		);
	}

	/**
	 * POST to Stripe (form-encoded, Bearer auth).
	 *
	 * @param string              $url    Endpoint.
	 * @param array<string,mixed> $params Body params.
	 * @return array<mixed>|null
	 */
	private function post( string $url, array $params ): ?array {
		if ( null !== $this->poster ) {
			$result = ( $this->poster )( $url, $params );
			return is_array( $result ) ? $result : null;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $this->secret_key ),
				'body'    => $params,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : null;
	}
}
