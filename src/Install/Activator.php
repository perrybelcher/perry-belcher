<?php
/**
 * Activation routine.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Install;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin activation.
 *
 * Responsibilities (Phase 0):
 *  - Guard the host environment (PHP / WP versions).
 *  - Run the migration runner so the baseline schema exists.
 *  - Record the installed schema version.
 *  - Schedule the maintenance cron (cleared again in {@see Deactivator}).
 *  - Flush rewrite rules (no-op now, matters once the CPT lands in Phase 1).
 */
final class Activator {

	/**
	 * Cron hook used for periodic maintenance sweeps (featured/expiry, etc.).
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'lodestar_daily_maintenance';

	/**
	 * Activation entry point.
	 */
	public static function activate(): void {
		self::guard_environment();

		global $wpdb;

		( new MigrationRunner( $wpdb ) )->run();
		update_option( 'lodestar_db_version', LODESTAR_DB_VERSION );

		self::schedule_cron();

		flush_rewrite_rules();
	}

	/**
	 * Bail out with a readable message if the host is unsupported, rather than
	 * leaving a half-activated plugin behind.
	 */
	private static function guard_environment(): void {
		if ( version_compare( PHP_VERSION, LODESTAR_MIN_PHP, '<' ) ) {
			deactivate_plugins( LODESTAR_BASENAME );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'Lodestar Directory requires PHP %1$s or higher. You are running PHP %2$s.', 'lodestar' ),
						LODESTAR_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}

		global $wp_version;

		if ( isset( $wp_version ) && version_compare( $wp_version, LODESTAR_MIN_WP, '<' ) ) {
			deactivate_plugins( LODESTAR_BASENAME );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: required WordPress version, 2: current WordPress version. */
						__( 'Lodestar Directory requires WordPress %1$s or higher. You are running %2$s.', 'lodestar' ),
						LODESTAR_MIN_WP,
						$wp_version
					)
				)
			);
		}
	}

	/**
	 * Schedule the recurring maintenance event if it is not already queued.
	 */
	private static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}
}
