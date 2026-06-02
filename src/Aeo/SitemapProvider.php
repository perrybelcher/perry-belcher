<?php
/**
 * Sitemap integration.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Aeo;

use Lodestar\PostType\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layers on top of WP core sitemaps.
 *
 * Listings (a public CPT) and their taxonomies are already included by core,
 * chunked at the core max. This adds a dedicated, prioritised "hubs" sitemap of
 * category/location archive URLs — the citable hub pages — and exposes the
 * chunk size for tuning. The chunking math is a pure, tested helper.
 */
final class SitemapProvider {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_filter( 'wp_sitemaps_max_urls', array( $this, 'max_urls' ), 10, 2 );
		// Register after taxonomies exist.
		add_action( 'init', array( $this, 'register_hub_provider' ), 20 );
	}

	/**
	 * Filter the per-page URL cap.
	 *
	 * @param int    $max  Core default (2000).
	 * @param string $type Object type.
	 */
	public function max_urls( int $max, string $type ): int {
		return (int) apply_filters( 'lodestar_sitemap_max_urls', $max, $type );
	}

	/**
	 * Register the custom hub-pages sitemap provider.
	 */
	public function register_hub_provider(): void {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
			return;
		}

		$provider = new class() extends \WP_Sitemaps_Provider {

			public function __construct() {
				$this->name        = 'lodestar-hubs';
				$this->object_type = 'term';
			}

			/**
			 * @param int    $page_num       Page of results.
			 * @param string $object_subtype Unused.
			 * @return array<int,array{loc:string}>
			 */
			public function get_url_list( $page_num, $object_subtype = '' ) {
				$urls = $this->hub_urls();
				$per  = wp_sitemaps_get_max_urls( $this->object_type );
				$page = array_slice( $urls, ( max( 1, (int) $page_num ) - 1 ) * $per, $per );

				return array_map( static fn ( $loc ) => array( 'loc' => $loc ), $page );
			}

			/**
			 * @param string $object_subtype Unused.
			 */
			public function get_max_num_pages( $object_subtype = '' ) {
				$per = max( 1, wp_sitemaps_get_max_urls( $this->object_type ) );

				return (int) ceil( count( $this->hub_urls() ) / $per );
			}

			/**
			 * All non-empty hub term URLs.
			 *
			 * @return string[]
			 */
			private function hub_urls(): array {
				$urls = array();
				foreach ( array( Taxonomies::CATEGORY, Taxonomies::LOCATION ) as $taxonomy ) {
					$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
					if ( is_array( $terms ) ) {
						foreach ( $terms as $term ) {
							$link = get_term_link( $term );
							if ( is_string( $link ) ) {
								$urls[] = $link;
							}
						}
					}
				}

				return $urls;
			}
		};

		wp_register_sitemap_provider( 'lodestar-hubs', $provider );
	}

	/**
	 * Split a list of items into pages of at most $per_page (pure helper).
	 *
	 * @param array<int,mixed> $items    Items to chunk.
	 * @param int              $per_page Max per page.
	 * @return array<int,array<int,mixed>>
	 */
	public static function chunk( array $items, int $per_page ): array {
		$per_page = max( 1, $per_page );

		return array_values( array_chunk( $items, $per_page ) );
	}
}
