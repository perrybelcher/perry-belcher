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
}
