<?php
/**
 * Checkout session result.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The outcome of starting a checkout: where to send the buyer, and the gateway
 * reference to reconcile against the webhook.
 */
final class CheckoutSession {

	/**
	 * @param string $url     Redirect URL for the buyer ('' on failure).
	 * @param string $ref     Gateway session/reference id.
	 * @param bool   $ok      Whether the session was created.
	 * @param string $message Error detail when not ok.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $ref = '',
		public readonly bool $ok = true,
		public readonly string $message = '',
	) {}

	/**
	 * A failed session.
	 *
	 * @param string $message Reason.
	 */
	public static function fail( string $message ): self {
		return new self( '', '', false, $message );
	}
}
