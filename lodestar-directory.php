<?php
/**
 * Plugin Name:       Lodestar Directory
 * Plugin URI:        https://example.com/lodestar-directory
 * Description:       The WordPress directory engine built for the AI-search era — fast at 100k+ listings, citable by default, with monetization in core.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Lodestar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lodestar
 * Domain Path:       /languages
 *
 * @package Lodestar
 */

declare(strict_types=1);

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * -------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------
 */
define( 'LODESTAR_VERSION', '0.1.0' );
define( 'LODESTAR_MIN_PHP', '8.1' );
define( 'LODESTAR_MIN_WP', '6.4' );

// Schema version. Bumped only when a new migration is added (see MigrationRunner).
define( 'LODESTAR_DB_VERSION', '1.0.0' );

define( 'LODESTAR_FILE', __FILE__ );
define( 'LODESTAR_BASENAME', plugin_basename( __FILE__ ) );
define( 'LODESTAR_PATH', plugin_dir_path( __FILE__ ) );
define( 'LODESTAR_URL', plugin_dir_url( __FILE__ ) );

/*
 * -------------------------------------------------------------------------
 * PHP version guard
 * -------------------------------------------------------------------------
 *
 * Never fatal on an unsupported host: degrade to an admin notice and bail so
 * the rest of the site keeps working.
 */
if ( version_compare( PHP_VERSION, LODESTAR_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'Lodestar Directory requires PHP %1$s or higher. You are running PHP %2$s. The plugin has been halted.', 'lodestar' ),
						LODESTAR_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

/*
 * -------------------------------------------------------------------------
 * Autoloading
 * -------------------------------------------------------------------------
 *
 * Prefer the Composer autoloader when present (dev / packaged builds). Fall
 * back to a lightweight PSR-4 autoloader so the plugin activates cleanly on a
 * stock install even before `composer install` has been run.
 */
if ( is_readable( LODESTAR_PATH . 'vendor/autoload.php' ) ) {
	require_once LODESTAR_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix   = 'Lodestar\\';
			$base_dir = LODESTAR_PATH . 'src/';

			$len = strlen( $prefix );
			if ( 0 !== strncmp( $prefix, $class, $len ) ) {
				return;
			}

			$relative = substr( $class, $len );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

/*
 * -------------------------------------------------------------------------
 * Lifecycle hooks
 * -------------------------------------------------------------------------
 *
 * Registered against the bootstrap file directly (WP requirement). The heavy
 * lifting lives in the namespaced Install classes.
 */
register_activation_hook( __FILE__, array( \Lodestar\Install\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Lodestar\Install\Deactivator::class, 'deactivate' ) );

/*
 * -------------------------------------------------------------------------
 * Boot
 * -------------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	static function (): void {
		\Lodestar\Plugin::instance()->boot();
	}
);
