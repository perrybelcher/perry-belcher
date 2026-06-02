<?php
/**
 * Server render for the search-form block.
 *
 * @package Lodestar
 *
 * @var array<string,mixed> $attributes Block attributes (provided by WP).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Renderer output is built with esc_* throughout.
echo \Lodestar\Blocks\Renderer::search( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
