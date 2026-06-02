<?php
/**
 * Normalised webhook event.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A verified, normalised payment event: which order it concerns and the new
 * status. Produced by a gateway only after the signature checks out.
 */
final class WebhookEvent {

	/**
	 * @param string   $status      Normalised order status (paid|failed|refunded).
	 * @param string   $gatewayRef  Gateway transaction/session reference.
	 * @param int|null $orderId     Our order ID (from client_reference_id), if present.
	 * @param string   $rawType     Original gateway event type.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $gatewayRef = '',
		public readonly ?int $orderId = null,
		public readonly string $rawType = '',
	) {}
}
