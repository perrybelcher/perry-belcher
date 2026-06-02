<?php
/**
 * Listing taxonomies.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\PostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the WP-native taxonomies for listings.
 *
 * These exist so WordPress gives us free archives, permalinks, and sitemaps.
 * Term relationships use WP core, but a denormalised copy of the assigned term
 * IDs is mirrored into {@see \Lodestar\Data\FieldIndexer} on save so faceted
 * filtering stays on the custom tables.
 */
final class Taxonomies {

	public const CATEGORY = 'ld_category';
	public const LOCATION = 'ld_location';
	public const TAG      = 'ld_tag';

	/**
	 * Hook registration on `init`.
	 */
	public function register(): void {
		register_taxonomy(
			self::CATEGORY,
			ListingPostType::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => _x( 'Categories', 'taxonomy general name', 'lodestar' ),
					'singular_name' => _x( 'Category', 'taxonomy singular name', 'lodestar' ),
					'search_items'  => __( 'Search Categories', 'lodestar' ),
					'all_items'     => __( 'All Categories', 'lodestar' ),
					'edit_item'     => __( 'Edit Category', 'lodestar' ),
					'add_new_item'  => __( 'Add New Category', 'lodestar' ),
					'menu_name'     => __( 'Categories', 'lodestar' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'listing-category' ),
			)
		);

		register_taxonomy(
			self::LOCATION,
			ListingPostType::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => _x( 'Locations', 'taxonomy general name', 'lodestar' ),
					'singular_name' => _x( 'Location', 'taxonomy singular name', 'lodestar' ),
					'search_items'  => __( 'Search Locations', 'lodestar' ),
					'all_items'     => __( 'All Locations', 'lodestar' ),
					'edit_item'     => __( 'Edit Location', 'lodestar' ),
					'add_new_item'  => __( 'Add New Location', 'lodestar' ),
					'menu_name'     => __( 'Locations', 'lodestar' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'listing-location' ),
			)
		);

		register_taxonomy(
			self::TAG,
			ListingPostType::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => _x( 'Tags', 'taxonomy general name', 'lodestar' ),
					'singular_name' => _x( 'Tag', 'taxonomy singular name', 'lodestar' ),
					'search_items'  => __( 'Search Tags', 'lodestar' ),
					'all_items'     => __( 'All Tags', 'lodestar' ),
					'edit_item'     => __( 'Edit Tag', 'lodestar' ),
					'add_new_item'  => __( 'Add New Tag', 'lodestar' ),
					'menu_name'     => __( 'Tags', 'lodestar' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'listing-tag' ),
			)
		);
	}
}
