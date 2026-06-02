<?php
/**
 * Bootstrap for the WordPress-free unit suite.
 *
 * Defines ABSPATH + lightweight WP stubs, then the Composer autoloader, so the
 * pure classes under tests/Unit can run without a WordPress install.
 *
 * @package Lodestar
 */

declare(strict_types=1);

require __DIR__ . '/stubs.php';

$lodestar_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $lodestar_autoload ) ) {
	require $lodestar_autoload;
}
