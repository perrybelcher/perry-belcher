<?php
/**
 * PayPal gateway.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native PayPal gateway. Event→status mapping is pure/tested; webhook trust is
 * delegated to an injected verifier (PayPal verification needs an API round-trip
 * against the configured credentials), and parsing refuses to trust an event
 * unless that verifier passes.
 */
final class PaypalGateway implements GatewayInterface {

	/**
	 * Optional verifier: callable(string $payload, array $headers): bool.
	 *
	 * @var callable|null
	 */
	private $verifier;

	/**
	 * @param string        $client_id     PayPal client ID.
	 * @param string        $client_secret PayPal secret.
	 * @param callable|null $verifier      Webhook verifier (required to trust events).
	 */
	public function __construct(
		private string $client_id = '',
		private string $client_secret = '',
		?callable $verifier = null,
	) {
		$this->verifier = $verifier;
	}

	public function id(): string {
		return 'paypal';
	}

	/**
	 * @param array<string,mixed> $order   Order data.
	 * @param array<string,mixed> $context URLs.
	 */
	public function create_checkout( array $order, array $context ): CheckoutSession {
		if ( '' === $this->client_id ) {
			return CheckoutSession::fail( 'PayPal is not configured.' );
		}

		// Order creation happens client-side via the PayPal SDK using this ref.
		return new CheckoutSession(
			(string) ( $context['cancel_url'] ?? '' ),
			'paypal_' . (string) ( $order['order_id'] ?? '' )
		);
	}

	/**
	 * @param string               $payload Raw body.
	 * @param array<string,string> $headers Headers.
	 */
	public function parse_webhook( string $payload, array $headers ): ?WebhookEvent {
		// Never trust an unverified PayPal webhook.
		if ( null === $this->verifier || ! ( $this->verifier )( $payload, $headers ) ) {
			return null;
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['event_type'] ) ) {
			return null;
		}

		$status = self::map_status( (string) $event['event_type'] );
		if ( null === $status ) {
			return null;
		}

		$resource = $event['resource'] ?? array();

		return new WebhookEvent(
			$status,
			(string) ( $resource['id'] ?? '' ),
			isset( $resource['custom_id'] ) ? (int) $resource['custom_id'] : null,
			(string) $event['event_type']
		);
	}

	/**
	 * Map a PayPal event type to our order status (pure).
	 *
	 * @param string $event_type PayPal event type.
	 */
	public static function map_status( string $event_type ): ?string {
		return match ( $event_type ) {
			'PAYMENT.CAPTURE.COMPLETED' => 'paid',
			'PAYMENT.CAPTURE.DENIED',
			'PAYMENT.CAPTURE.DECLINED'  => 'failed',
			'PAYMENT.CAPTURE.REFUNDED'  => 'refunded',
			default                     => null,
		};
	}
}
