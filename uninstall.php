<?php
/**
 * Uninstall handler — runs when the plugin is deleted (not merely deactivated).
 *
 * Drops every Lodestar table and deletes plugin options. This is destructive
 * and irreversible by design; deactivation never touches data.
 *
 * @package Lodestar
 */

declare(strict_types=1);

// Only ever run inside WordPress's uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
 * All custom tables defined across the build (§3 of the SDD). Listed in full so
 * a clean uninstall removes everything regardless of which phase was reached.
 */
$tables = array(
	'lodestar_listing_data',
	'lodestar_field_index',
	'lodestar_directory_types',
	'lodestar_fields',
	'lodestar_reviews',
	'lodestar_claims',
	'lodestar_plans',
	'lodestar_orders',
	'lodestar_ai_log',
	'lodestar_migrations',
);

foreach ( $tables as $table ) {
	$name = $wpdb->prefix . $table;
	// Identifier comes from a hard-coded allowlist above, not user input.
	$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
}

// Plugin options.
$options = array(
	'lodestar_db_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear any scheduled events as a belt-and-braces measure.
wp_clear_scheduled_hook( 'lodestar_daily_maintenance' );
