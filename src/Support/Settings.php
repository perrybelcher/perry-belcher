<?php
/**
 * Typed accessors for plugin settings / config flags.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only gateway to the plugin's configurable options.
 *
 * Each getter has a sensible default and a filter, so behaviour is settable via
 * the (future) settings UI, `update_option`, or a `lodestar_*` filter. Defaults
 * match the SDD §7 open-config decisions.
 */
final class Settings {

	/**
	 * Whether guests (logged-out users) may submit listings. Default: no.
	 */
	public static function allow_guest_submissions(): bool {
		$value = (bool) get_option( 'lodestar_allow_guest_submissions', false );

		return (bool) apply_filters( 'lodestar_allow_guest_submissions', $value );
	}

	/**
	 * The post status applied to a newly submitted listing. Default: pending.
	 *
	 * @return string One of: pending, publish, draft.
	 */
	public static function default_listing_status(): string {
		$value   = (string) get_option( 'lodestar_default_listing_status', 'pending' );
		$allowed = array( 'pending', 'publish', 'draft' );
		$value   = in_array( $value, $allowed, true ) ? $value : 'pending';

		return (string) apply_filters( 'lodestar_default_listing_status', $value );
	}

	/**
	 * Whether editing a published listing sends it back to pending review.
	 * Default: no (owners can fix typos without re-moderation).
	 */
	public static function moderate_edits(): bool {
		return (bool) apply_filters( 'lodestar_moderate_edits', (bool) get_option( 'lodestar_moderate_edits', false ) );
	}

	/**
	 * URL of the page hosting the submission form (for edit links).
	 *
	 * Defaults to the home URL; set via the `lodestar_submit_page_url` filter or
	 * a stored option until the settings UI lands.
	 */
	public static function submit_page_url(): string {
		$option = (int) get_option( 'lodestar_submit_page_id', 0 );
		$url    = $option > 0 ? (string) get_permalink( $option ) : home_url( '/' );

		return (string) apply_filters( 'lodestar_submit_page_url', $url );
	}

	/**
	 * Geocoding/map provider. Default: nominatim (OSM, no API key friction).
	 *
	 * @return string One of: nominatim, google, mapbox.
	 */
	public static function geo_provider(): string {
		$value   = (string) get_option( 'lodestar_geo_provider', 'nominatim' );
		$allowed = array( 'nominatim', 'google', 'mapbox' );
		$value   = in_array( $value, $allowed, true ) ? $value : 'nominatim';

		return (string) apply_filters( 'lodestar_geo_provider', $value );
	}

	/**
	 * Geocoding API key. Prefers the wp-config constant, then the option.
	 * Server-side only — never enqueued.
	 */
	public static function geo_api_key(): string {
		$key = defined( 'LODESTAR_GEO_API_KEY' ) ? (string) LODESTAR_GEO_API_KEY : (string) get_option( 'lodestar_geo_api_key', '' );

		return (string) apply_filters( 'lodestar_geo_api_key', $key );
	}

	/**
	 * Default radius (km) applied when a location search omits one.
	 */
	public static function default_radius_km(): float {
		return (float) apply_filters( 'lodestar_default_radius_km', (float) get_option( 'lodestar_default_radius_km', 25 ) );
	}

	/**
	 * Active payment gateway. Default: stripe (native).
	 *
	 * @return string One of: stripe, paypal, woo.
	 */
	public static function payments_gateway(): string {
		$value = defined( 'LODESTAR_PAYMENTS' ) ? (string) LODESTAR_PAYMENTS : (string) get_option( 'lodestar_payments_gateway', 'stripe' );
		$allowed = array( 'stripe', 'paypal', 'woo' );
		$value   = in_array( $value, $allowed, true ) ? $value : 'stripe';

		return (string) apply_filters( 'lodestar_payments_gateway', $value );
	}

	/**
	 * ISO currency code for prices/checkout. Default: USD.
	 */
	public static function currency(): string {
		return strtoupper( (string) apply_filters( 'lodestar_currency', (string) get_option( 'lodestar_currency', 'USD' ) ) );
	}

	/**
	 * Stripe secret key (server-side only).
	 */
	public static function stripe_secret_key(): string {
		return defined( 'LODESTAR_STRIPE_SECRET' ) ? (string) LODESTAR_STRIPE_SECRET : (string) get_option( 'lodestar_stripe_secret', '' );
	}

	/**
	 * Stripe webhook signing secret (server-side only).
	 */
	public static function stripe_webhook_secret(): string {
		return defined( 'LODESTAR_STRIPE_WEBHOOK_SECRET' ) ? (string) LODESTAR_STRIPE_WEBHOOK_SECRET : (string) get_option( 'lodestar_stripe_webhook_secret', '' );
	}
}
