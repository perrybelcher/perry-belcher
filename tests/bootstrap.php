<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * Resolves the WP test library from WP_TESTS_DIR (or the conventional
 * /tmp/wordpress-tests-lib location), then loads Lodestar as a mu-plugin.
 *
 * @package Lodestar
 */

declare(strict_types=1);

$lodestar_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $lodestar_tests_dir ) {
	$lodestar_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$lodestar_functions = $lodestar_tests_dir . '/includes/functions.php';

if ( ! is_readable( $lodestar_functions ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test suite at {$lodestar_tests_dir}.\n" .
		"Install it (e.g. bin/install-wp-tests.sh) or set WP_TESTS_DIR.\n"
	);
	exit( 1 );
}

require_once $lodestar_functions;

/**
 * Load the plugin into the test WordPress instance.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/lodestar-directory.php';
	}
);

require $lodestar_tests_dir . '/includes/bootstrap.php';

// Ensure the custom tables exist for integration tests. DDL is not rolled back
// by the per-test transaction, so running it once here is sufficient.
( new \Lodestar\Install\MigrationRunner( $GLOBALS['wpdb'] ) )->run();
