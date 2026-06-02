<?php
/**
 * Payment gateway contract.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A payment gateway: start a checkout, and turn an incoming webhook into a
 * normalised order status update.
 *
 * Keys/secrets stay server-side. Webhooks are verified inside the gateway before
 * any order state changes.
 */
interface GatewayInterface {

	/**
	 * Gateway identifier (e.g. 'stripe').
	 */
	public function id(): string;

	/**
	 * Start a checkout for an order.
	 *
	 * @param array<string,mixed> $order   order_id, amount, currency, description.
	 * @param array<string,mixed> $context success_url, cancel_url.
	 */
	public function create_checkout( array $order, array $context ): CheckoutSession;

	/**
	 * Verify and parse an incoming webhook, or null if invalid/irrelevant.
	 *
	 * @param string                $payload Raw request body.
	 * @param array<string,string>  $headers Request headers.
	 */
	public function parse_webhook( string $payload, array $headers ): ?WebhookEvent;
}
