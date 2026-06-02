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
		add_action( 'init', array( $this, 'register_frontend' ) );
		add_action( 'init', array( $this, 'register_aeo' ) );
		add_action( 'init', array( $this, 'register_monetize' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

		if ( is_admin() ) {
			$this->register_admin();
		}
	}

	/**
	 * Wire monetization: webhook endpoint + the featured/expiry cron sweep.
	 */
	public function register_monetize(): void {
		global $wpdb;

		$featured = new Monetize\FeaturedManager( $wpdb );

		// Reuse the daily maintenance cron scheduled by the Activator.
		add_action( Install\Activator::CRON_HOOK, array( $featured, 'sweep' ) );

		( new Monetize\WebhookController(
			Monetize\Gateways\GatewayManager::from_config(),
			new Monetize\OrderManager( $wpdb ),
			$featured,
			new Monetize\PlanManager( $wpdb ),
			$wpdb
		) )->register();
	}

	/**
	 * Wire the AEO/GEO engine (JSON-LD, hub pages, sitemaps).
	 */
	public function register_aeo(): void {
		global $wpdb;

		$repo   = new Data\ListingRepository( $wpdb );
		$types  = new DirectoryType\DirectoryTypeManager( $wpdb );
		$fields = new DirectoryType\FieldManager( $wpdb );

		( new Aeo\SchemaGenerator( $repo, $types, $fields ) )->register();
		( new Aeo\ProgrammaticPages() )->register();
		( new Aeo\SitemapProvider() )->register();
	}

	/**
	 * Wire the front-end controllers (shortcodes + form/POST handlers).
	 *
	 * Registered for both front-end and admin-post.php requests so submissions
	 * processed via admin-post.php are handled regardless of context.
	 */
	public function register_frontend(): void {
		global $wpdb;

		$repo      = new Data\ListingRepository( $wpdb );
		$templates = new Frontend\TemplateLoader();
		$types     = new DirectoryType\DirectoryTypeManager( $wpdb );
		$fields    = new DirectoryType\FieldManager( $wpdb );

		( new Frontend\Assets() )->register();
		( new Frontend\SubmissionController( $repo, $types, $fields, $templates ) )->register();
		( new Frontend\DashboardController( $repo, $templates ) )->register();
		( new Frontend\SearchController( $repo, $types, $fields, $templates ) )->register();
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
