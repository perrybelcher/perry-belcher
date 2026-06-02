<?php
/**
 * Admin menu registration.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the top-level Lodestar admin menu and its sub-pages.
 */
final class AdminMenu {

	public const CAPABILITY = 'manage_options';
	public const SLUG       = 'lodestar';
	public const TYPES_SLUG = 'lodestar-directory-types';

	/**
	 * @param DirectoryTypeAdmin $types Directory-type admin screen.
	 */
	public function __construct( private DirectoryTypeAdmin $types ) {}

	/**
	 * Hook into `admin_menu`.
	 */
	public function register(): void {
		add_menu_page(
			__( 'Lodestar Directory', 'lodestar' ),
			__( 'Lodestar', 'lodestar' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-location-alt',
			25
		);

		add_submenu_page(
			self::SLUG,
			__( 'Directory Types', 'lodestar' ),
			__( 'Directory Types', 'lodestar' ),
			self::CAPABILITY,
			self::TYPES_SLUG,
			array( $this->types, 'render' )
		);
	}

	/**
	 * Placeholder dashboard landing page.
	 */
	public function render_dashboard(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Lodestar Directory', 'lodestar' ) . '</h1>';
		echo '<p>' . esc_html__( 'Manage your directory types and fields from the submenu.', 'lodestar' ) . '</p></div>';
	}
}
