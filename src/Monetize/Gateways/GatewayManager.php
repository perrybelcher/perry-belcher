<?php
/**
 * Gateway registry / selector.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Monetize\Gateways;

use Lodestar\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the available gateways and resolves the active one from config.
 */
final class GatewayManager {

	/**
	 * @var array<string,GatewayInterface>
	 */
	private array $gateways = array();

	/**
	 * Register a gateway.
	 */
	public function register( GatewayInterface $gateway ): void {
		$this->gateways[ $gateway->id() ] = $gateway;
	}

	/**
	 * Get a gateway by id.
	 */
	public function get( string $id ): ?GatewayInterface {
		return $this->gateways[ $id ] ?? null;
	}

	/**
	 * The configured active gateway.
	 */
	public function active(): ?GatewayInterface {
		return $this->get( Settings::payments_gateway() );
	}

	/**
	 * Build a manager from configuration.
	 */
	public static function from_config(): self {
		$manager = new self();
		$manager->register( new StripeGateway( Settings::stripe_secret_key(), Settings::stripe_webhook_secret() ) );
		$manager->register( new PaypalGateway() );

		if ( class_exists( 'WooCommerce' ) ) {
			$manager->register( new WooGateway() );
		}

		/**
		 * Allow add-ons to register additional gateways.
		 *
		 * @param GatewayManager $manager The registry.
		 */
		do_action( 'lodestar_register_gateways', $manager );

		return $manager;
	}
}
