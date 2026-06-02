<?php
/**
 * The `ld_listing` custom post type.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\PostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the listing CPT.
 *
 * The post row stays minimal on purpose (title, slug, status, author, optional
 * long description). All facetable/searchable data lives in the custom tables —
 * never in postmeta. Revisions are OFF to keep the posts table lean at scale.
 */
final class ListingPostType {

	/**
	 * Post type key.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'ld_listing';

	/**
	 * Hook registration on `init`.
	 */
	public function register(): void {
		$labels = array(
			'name'               => _x( 'Listings', 'post type general name', 'lodestar' ),
			'singular_name'      => _x( 'Listing', 'post type singular name', 'lodestar' ),
			'menu_name'          => _x( 'Listings', 'admin menu', 'lodestar' ),
			'add_new'            => __( 'Add New', 'lodestar' ),
			'add_new_item'       => __( 'Add New Listing', 'lodestar' ),
			'edit_item'          => __( 'Edit Listing', 'lodestar' ),
			'new_item'           => __( 'New Listing', 'lodestar' ),
			'view_item'          => __( 'View Listing', 'lodestar' ),
			'search_items'       => __( 'Search Listings', 'lodestar' ),
			'not_found'          => __( 'No listings found.', 'lodestar' ),
			'not_found_in_trash' => __( 'No listings found in Trash.', 'lodestar' ),
			'all_items'          => __( 'All Listings', 'lodestar' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => true,
			'has_archive'     => true,
			'show_in_rest'    => true,
			'rest_base'       => 'ld_listings',
			'menu_icon'       => 'dashicons-location-alt',
			'menu_position'   => 25,
			'supports'        => array( 'title', 'editor', 'author' ),
			'rewrite'         => array(
				'slug'       => apply_filters( 'lodestar_listing_rewrite_slug', 'listing' ),
				'with_front' => false,
			),
			'capability_type' => 'post',
			'hierarchical'    => false,
		);

		register_post_type( self::POST_TYPE, apply_filters( 'lodestar_listing_post_type_args', $args ) );
	}
}
