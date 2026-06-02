<?php
/**
 * WooCommerce bridge gateway.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional bridge for sites already running WooCommerce. Checkout hands off to
 * Woo; order status changes flow through Woo's own hooks (mapped here), so this
 * gateway does not process our generic webhook.
 */
final class WooGateway implements GatewayInterface {

	public function id(): string {
		return 'woo';
	}

	/**
	 * @param array<string,mixed> $order   Order data.
	 * @param array<string,mixed> $context URLs.
	 */
	public function create_checkout( array $order, array $context ): CheckoutSession {
		if ( ! function_exists( 'wc_get_checkout_url' ) ) {
			return CheckoutSession::fail( 'WooCommerce is not active.' );
		}

		return new CheckoutSession( (string) wc_get_checkout_url(), 'woo_' . (string) ( $order['order_id'] ?? '' ) );
	}

	/**
	 * Woo posts status via its own hooks, not our webhook endpoint.
	 *
	 * @param string               $payload Raw body.
	 * @param array<string,string> $headers Headers.
	 */
	public function parse_webhook( string $payload, array $headers ): ?WebhookEvent {
		return null;
	}

	/**
	 * Map a WooCommerce order status to our order status (pure).
	 *
	 * @param string $woo_status Woo status (e.g. 'completed').
	 */
	public static function map_status( string $woo_status ): ?string {
		return match ( $woo_status ) {
			'completed', 'processing' => 'paid',
			'failed', 'cancelled'     => 'failed',
			'refunded'                => 'refunded',
			default                   => null,
		};
	}
}
