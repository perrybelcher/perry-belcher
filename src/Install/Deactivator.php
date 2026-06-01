<?php
/**
 * Deactivation routine.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Install;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin deactivation.
 *
 * Deactivation is reversible: it must NOT drop tables or delete data (that is
 * {@see uninstall.php}'s job). It only tears down runtime artefacts — scheduled
 * cron events and rewrite rules — so nothing is left orphaned.
 */
final class Deactivator {

	/**
	 * Deactivation entry point.
	 */
	public static function deactivate(): void {
		self::clear_cron();

		flush_rewrite_rules();
	}

	/**
	 * Remove every cron event this plugin may have scheduled.
	 *
	 * Keep this list in sync with everything {@see Activator} (and later
	 * managers) register, so deactivation never leaves an orphaned schedule.
	 */
	private static function clear_cron(): void {
		$hooks = array(
			Activator::CRON_HOOK,
		);

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
