<?php
/**
 * Server render for the submit-form block.
 *
 * @package Lodestar
 *
 * @var array<string,mixed> $attributes Block attributes (provided by WP).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Lodestar\Blocks\Renderer::submit( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
