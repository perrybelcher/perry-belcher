<?php
/**
 * Plugin container and bootstrap.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central runtime object. Holds the (eventual) service container and wires the
 * top-level WordPress hooks.
 *
 * Phase 0 keeps this intentionally thin: load translations and run the
 * migration check. Later phases register the CPT, REST routes, blocks, etc.
 * through this single entry point.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor — use {@see Plugin::instance()}.
	 */
	private function __construct() {}

	/**
	 * Retrieve the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire WordPress hooks. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_content_types' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

		if ( is_admin() ) {
			$this->register_admin();
		}
	}

	/**
	 * Wire the admin screens (directory types & fields).
	 */
	private function register_admin(): void {
		global $wpdb;

		$type_admin = new Admin\DirectoryTypeAdmin(
			new DirectoryType\DirectoryTypeManager( $wpdb ),
			new DirectoryType\FieldManager( $wpdb )
		);

		add_action( 'admin_init', array( $type_admin, 'handle_post' ) );
		add_action( 'admin_menu', array( new Admin\AdminMenu( $type_admin ), 'register' ) );
	}

	/**
	 * Register the listing CPT and its taxonomies.
	 */
	public function register_content_types(): void {
		( new PostType\ListingPostType() )->register();
		( new PostType\Taxonomies() )->register();
	}

	/**
	 * Load the plugin text domain for translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'lodestar', false, dirname( LODESTAR_BASENAME ) . '/languages' );
	}

	/**
	 * Run pending migrations when the stored schema version is behind code.
	 *
	 * This mirrors what the activation hook does, but also covers the case
	 * where a site is updated via FTP/Git deploy where the activation hook
	 * never fires.
	 */
	public function maybe_upgrade(): void {
		$installed = get_option( 'lodestar_db_version' );

		if ( LODESTAR_DB_VERSION === $installed ) {
			return;
		}

		global $wpdb;

		( new Install\MigrationRunner( $wpdb ) )->run();
		update_option( 'lodestar_db_version', LODESTAR_DB_VERSION );
	}
}
