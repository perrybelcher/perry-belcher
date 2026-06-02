<?php
/**
 * Payment webhook endpoint + order fulfilment.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize;

use Lodestar\Monetize\Gateways\GatewayManager;
use Lodestar\Support\Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives gateway webhooks at admin-post.php?action=lodestar_webhook&gateway=…
 *
 * The gateway verifies the signature before this controller trusts anything;
 * only then is the order's status updated and, on payment, the plan fulfilled
 * (plan attached, listing featured if the plan includes it).
 */
final class WebhookController {

	public const ACTION = 'lodestar_webhook';

	public function __construct(
		private GatewayManager $gateways,
		private OrderManager $orders,
		private FeaturedManager $featured,
		private PlanManager $plans,
		private \wpdb $db,
	) {}

	/**
	 * Register the (public) webhook endpoint.
	 */
	public function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handle an incoming webhook.
	 */
	public function handle(): void {
		$gateway = $this->gateways->get( Request::getKey( 'gateway' ) );
		if ( ! $gateway ) {
			$this->respond( 400 );
		}

		// Raw body is required for signature verification.
		$payload = (string) file_get_contents( 'php://input' );
		$headers = array(
			'stripe-signature' => Request::header( 'Stripe-Signature' ),
			'paypal-transmission-sig' => Request::header( 'Paypal-Transmission-Sig' ),
		);

		$event = $gateway->parse_webhook( $payload, $headers );
		if ( ! $event ) {
			// Unverified or irrelevant: do not leak detail.
			$this->respond( 400 );
		}

		if ( $event->orderId ) {
			$this->orders->update_status( $event->orderId, $event->status, $event->gatewayRef );
			$order = $this->orders->find( $event->orderId );
		} else {
			$this->orders->update_status_by_ref( $event->gatewayRef, $event->status );
			$order = null;
		}

		if ( 'paid' === $event->status && is_array( $order ) ) {
			$this->fulfill( $order );
		}

		$this->respond( 200 );
	}

	/**
	 * Apply a paid order: attach the plan and feature the listing if included.
	 *
	 * @param array<string,mixed> $order Order row.
	 */
	private function fulfill( array $order ): void {
		$plan_id    = (int) ( $order['plan_id'] ?? 0 );
		$listing_id = (int) ( $order['listing_id'] ?? 0 );
		if ( 0 === $plan_id || 0 === $listing_id ) {
			return;
		}

		$plan = $this->plans->find( $plan_id );
		if ( ! $plan ) {
			return;
		}

		$this->db->update(
			$this->db->prefix . 'lodestar_listing_data',
			array( 'plan_id' => $plan_id ),
			array( 'listing_id' => $listing_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( $plan->featuredIncluded ) {
			$this->featured->feature( $listing_id, $plan->durationDays );
		}

		/**
		 * Fires after a paid order is fulfilled.
		 *
		 * @param int  $listing_id Listing ID.
		 * @param Plan $plan       The plan applied.
		 */
		do_action( 'lodestar_order_fulfilled', $listing_id, $plan );
	}

	/**
	 * Emit a status code and stop.
	 *
	 * @param int $code HTTP status.
	 */
	private function respond( int $code ): void {
		status_header( $code );
		echo 200 === $code ? 'ok' : 'error'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
