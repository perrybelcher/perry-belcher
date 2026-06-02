<?php
/**
 * Override-aware front-end template renderer.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves and renders templates with theme-override support.
 *
 * Lookup order (first hit wins):
 *   1. {child-theme}/lodestar/{template}.php
 *   2. {parent-theme}/lodestar/{template}.php
 *   3. {plugin}/templates/{template}.php
 *
 * A plugin update never flattens a user's theme override, satisfying the
 * update-safe rendering decision. Templates are pure presentation — controllers
 * prepare every variable and templates only echo (escaped) output.
 */
final class TemplateLoader {

	/**
	 * Resolve a template name to an absolute file path.
	 *
	 * @param string $template Template name without extension (e.g. 'submit-form').
	 */
	public function locate( string $template ): string {
		$file = ltrim( $template, '/' ) . '.php';

		$theme = locate_template( array( 'lodestar/' . $file ) );
		if ( '' !== $theme ) {
			return $theme;
		}

		$plugin = LODESTAR_PATH . 'templates/' . $file;

		return (string) apply_filters( 'lodestar_template_path', $plugin, $template );
	}

	/**
	 * Render a template to a string.
	 *
	 * @param string              $template Template name.
	 * @param array<string,mixed> $vars     Variables exposed to the template.
	 */
	public function render( string $template, array $vars = array() ): string {
		$file = $this->locate( $template );

		if ( ! is_readable( $file ) ) {
			return '';
		}

		/** @var array<string,mixed> $vars */
		$vars = (array) apply_filters( 'lodestar_template_vars', $vars, $template );

		ob_start();
		( static function ( string $lodestar_template_file, array $lodestar_vars ): void {
			// EXTR_SKIP: never let template vars clobber locals like the path.
			extract( $lodestar_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $lodestar_template_file;
		} )( $file, $vars );

		return (string) ob_get_clean();
	}
}
