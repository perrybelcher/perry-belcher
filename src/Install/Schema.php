<?php
/**
 * Custom table schema definitions (SDD §3).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Install;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the custom table DDL.
 *
 * Returns dbDelta-compatible `CREATE TABLE` statements. The whitespace here is
 * load-bearing: dbDelta requires two spaces after `PRIMARY KEY`, one field/key
 * per line, and lowercase column types. Do not reformat casually.
 *
 * Performance contract (do not reorder the field_index keys): facet queries
 * always filter `field_key` first, so the composite indexes lead with it.
 */
final class Schema {

	/**
	 * Bare table names (without the site prefix), in dependency order.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array(
			'listing_data',
			'field_index',
			'directory_types',
			'fields',
			'reviews',
			'claims',
			'plans',
			'orders',
			'ai_log',
		);
	}

	/**
	 * All `CREATE TABLE` statements concatenated for a single dbDelta call.
	 *
	 * @param string $prefix          Site table prefix (e.g. `wp_`).
	 * @param string $charset_collate Result of `$wpdb->get_charset_collate()`.
	 */
	public static function statements( string $prefix, string $charset_collate ): string {
		$p = $prefix . 'lodestar_';

		$sql = array();

		$sql[] = "CREATE TABLE {$p}listing_data (
			listing_id bigint(20) unsigned NOT NULL,
			directory_type_id int(10) unsigned NOT NULL DEFAULT 0,
			plan_id int(10) unsigned DEFAULT NULL,
			lat decimal(10,7) DEFAULT NULL,
			lng decimal(10,7) DEFAULT NULL,
			geohash varchar(12) DEFAULT NULL,
			price decimal(12,2) DEFAULT NULL,
			rating_avg decimal(3,2) NOT NULL DEFAULT 0,
			rating_count int(10) unsigned NOT NULL DEFAULT 0,
			is_featured tinyint(1) NOT NULL DEFAULT 0,
			featured_until datetime DEFAULT NULL,
			expires_at datetime DEFAULT NULL,
			claim_status enum('unclaimed','pending','claimed') NOT NULL DEFAULT 'unclaimed',
			ai_citability_score tinyint(3) unsigned DEFAULT NULL,
			view_count int(10) unsigned NOT NULL DEFAULT 0,
			meta longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (listing_id),
			KEY directory_type_id (directory_type_id),
			KEY geohash (geohash),
			KEY latlng (lat,lng),
			KEY featured (is_featured,featured_until),
			KEY expires_at (expires_at),
			KEY rating_avg (rating_avg),
			KEY price (price),
			KEY claim_status (claim_status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}field_index (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			listing_id bigint(20) unsigned NOT NULL,
			field_key varchar(64) NOT NULL,
			value_text varchar(191) DEFAULT NULL,
			value_num decimal(16,4) DEFAULT NULL,
			value_date datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY field_text (field_key,value_text),
			KEY field_num (field_key,value_num),
			KEY field_date (field_key,value_date),
			KEY listing_id (listing_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}directory_types (
			id int(10) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(64) NOT NULL,
			label varchar(191) NOT NULL,
			singular_label varchar(191) NOT NULL,
			config_json longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}fields (
			id int(10) unsigned NOT NULL AUTO_INCREMENT,
			directory_type_id int(10) unsigned NOT NULL,
			field_key varchar(64) NOT NULL,
			label varchar(191) NOT NULL,
			input_type varchar(20) NOT NULL DEFAULT 'text',
			is_facetable tinyint(1) NOT NULL DEFAULT 0,
			is_required tinyint(1) NOT NULL DEFAULT 0,
			options_json longtext DEFAULT NULL,
			schema_property varchar(64) DEFAULT NULL,
			sort_order int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY type_field (directory_type_id,field_key),
			KEY directory_type_id (directory_type_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}reviews (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			listing_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			rating tinyint(3) unsigned NOT NULL,
			title varchar(191) DEFAULT NULL,
			body text DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY listing_status (listing_id,status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}claims (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			listing_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			evidence_json longtext DEFAULT NULL,
			verified_via varchar(20) DEFAULT NULL,
			created_at datetime NOT NULL,
			resolved_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY listing_id (listing_id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}plans (
			id int(10) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(64) NOT NULL,
			label varchar(191) NOT NULL,
			price decimal(12,2) NOT NULL DEFAULT 0,
			billing varchar(20) NOT NULL DEFAULT 'one_time',
			interval_unit varchar(10) DEFAULT NULL,
			listing_limit int(10) unsigned DEFAULT NULL,
			featured_included tinyint(1) NOT NULL DEFAULT 0,
			duration_days int(10) unsigned DEFAULT NULL,
			capabilities_json longtext DEFAULT NULL,
			gateway_meta_json longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}orders (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			plan_id int(10) unsigned DEFAULT NULL,
			listing_id bigint(20) unsigned DEFAULT NULL,
			gateway varchar(20) NOT NULL,
			gateway_ref varchar(191) DEFAULT NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY plan_id (plan_id),
			KEY listing_id (listing_id),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}ai_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			listing_id bigint(20) unsigned DEFAULT NULL,
			provider varchar(32) NOT NULL,
			operation varchar(20) NOT NULL,
			tokens_in int(10) unsigned NOT NULL DEFAULT 0,
			tokens_out int(10) unsigned NOT NULL DEFAULT 0,
			latency_ms int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'ok',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY listing_id (listing_id),
			KEY operation (operation),
			KEY created_at (created_at)
		) {$charset_collate};";

		return implode( "\n", $sql );
	}
}
