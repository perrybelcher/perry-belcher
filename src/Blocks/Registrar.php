<?php
/**
 * Registers the Gutenberg blocks.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the block category, the shared editor script, and each dynamic
 * block from its block.json. Blocks are server-rendered (render.php → Renderer),
 * so there is no compiled save markup to go stale on update.
 */
final class Registrar {

	/** Block directory names under /blocks. */
	private const BLOCKS = array( 'search-form', 'submit-form', 'listings-grid', 'single-listing', 'map' );

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_filter( 'block_categories_all', array( $this, 'category' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Add the Lodestar block category.
	 *
	 * @param array<int,array<string,mixed>> $categories Existing categories.
	 * @return array<int,array<string,mixed>>
	 */
	public function category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'lodestar',
				'title' => __( 'Lodestar', 'lodestar' ),
				'icon'  => 'location-alt',
			)
		);

		return $categories;
	}

	/**
	 * Register the editor script + each block from block.json.
	 */
	public function register_blocks(): void {
		// The shared editor bundle (plain JS — no build step). Referenced by each
		// block.json via "editorScript": "lodestar-blocks".
		wp_register_script(
			'lodestar-blocks',
			LODESTAR_URL . 'assets/js/lodestar-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			LODESTAR_VERSION,
			true
		);

		foreach ( self::BLOCKS as $block ) {
			$dir = LODESTAR_PATH . 'blocks/' . $block;
			if ( is_readable( $dir . '/block.json' ) ) {
				register_block_type( $dir );
			}
		}
	}
}
