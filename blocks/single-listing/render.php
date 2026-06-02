<?php
/**
 * Server render for the single-listing details block.
 *
 * @package Lodestar
 *
 * @var array<string,mixed> $attributes Block attributes (provided by WP).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Lodestar\Blocks\Renderer::single( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
