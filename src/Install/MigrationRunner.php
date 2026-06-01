<?php
/**
 * Versioned schema migration runner.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Install;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies versioned schema migrations through {@see dbDelta()} and records the
 * applied versions in the {@see self::table()} tracking table.
 *
 * Conventions (per the SDD):
 *  - Schema changes ONLY happen here, via dbDelta — never ad-hoc CREATE TABLE.
 *  - Each migration is identified by an immutable version string and is applied
 *    at most once. Migrations are ordered and idempotent.
 *  - Phase 0 ships a single, empty baseline migration. Real tables arrive in
 *    Phase 1 by appending entries to {@see self::migrations()}.
 */
final class MigrationRunner {

	/**
	 * WordPress database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Fully-qualified migrations tracking table name (with prefix).
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db WordPress database handle (usually $wpdb).
	 */
	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->table = $db->prefix . 'lodestar_migrations';
	}

	/**
	 * Tracking table name, including the site prefix.
	 */
	public function table(): string {
		return $this->table;
	}

	/**
	 * Run every pending migration in order.
	 *
	 * @return string[] Versions applied during this run (empty if up to date).
	 */
	public function run(): array {
		$this->ensure_tracking_table();

		$applied = $this->applied_versions();
		$ran     = array();

		foreach ( $this->migrations() as $migration ) {
			$version = $migration['version'];

			if ( in_array( $version, $applied, true ) ) {
				continue;
			}

			$this->apply( $migration );
			$ran[] = $version;
		}

		return $ran;
	}

	/**
	 * The ordered migration set.
	 *
	 * Each migration is an array of:
	 *  - version: immutable identifier, compared verbatim.
	 *  - sql:     callable( string $prefix, string $charset_collate ): string
	 *             returning dbDelta-compatible SQL, or an empty string for a
	 *             data-only / baseline migration.
	 *
	 * @return array<int, array{version: string, sql: callable}>
	 */
	private function migrations(): array {
		return array(
			array(
				// Phase 0 baseline. The tracking table itself is bootstrapped by
				// ensure_tracking_table(); this records that the install is
				// initialised so later phases have a known starting point.
				'version' => '1.0.0',
				'sql'     => static function (): string {
					return '';
				},
			),
			// Phase 1+ migrations are appended below, e.g.:
			// array( 'version' => '1.1.0', 'sql' => fn( $prefix, $charset ) => "CREATE TABLE {$prefix}lodestar_listing_data ( ... ) {$charset};" ),
		);
	}

	/**
	 * Apply a single migration: run its SQL (if any), then record the version.
	 *
	 * @param array{version: string, sql: callable} $migration Migration spec.
	 */
	private function apply( array $migration ): void {
		$sql = (string) call_user_func(
			$migration['sql'],
			$this->db->prefix,
			$this->db->get_charset_collate()
		);

		if ( '' !== trim( $sql ) ) {
			$this->db_delta( $sql );
		}

		$this->record( $migration['version'] );
	}

	/**
	 * Create the tracking table if it does not exist yet.
	 */
	private function ensure_tracking_table(): void {
		$charset_collate = $this->db->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after the field name,
		// keys on their own lines, PRIMARY KEY with two spaces.
		$sql = "CREATE TABLE {$this->table} (
			version varchar(32) NOT NULL,
			applied_at datetime NOT NULL,
			PRIMARY KEY  (version)
		) {$charset_collate};";

		$this->db_delta( $sql );
	}

	/**
	 * Versions already recorded as applied.
	 *
	 * @return string[]
	 */
	private function applied_versions(): array {
		// The table name is internally derived from $wpdb->prefix and cannot be
		// parameterised; it is not user input.
		$rows = $this->db->get_col( "SELECT version FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * Record a version as applied (GMT timestamp), ignoring duplicates.
	 *
	 * @param string $version Migration version.
	 */
	private function record( string $version ): void {
		$this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO {$this->table} (version, applied_at) VALUES (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$version,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Thin wrapper around dbDelta so the upgrade include is loaded once.
	 *
	 * @param string $sql CREATE TABLE statement(s).
	 */
	private function db_delta( string $sql ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
	}
}
