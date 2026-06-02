<?php
/**
 * Programmatic category/location hub pages.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Aeo;

use Lodestar\PostType\ListingPostType;
use Lodestar\PostType\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enriches the (free, WP-native) category/location term archives into citable
 * hub pages: an auto intro, a comparison table of listings, breadcrumb +
 * CollectionPage JSON-LD, and internal links.
 *
 * The schema/markup builders are pure and unit-tested; WordPress only supplies
 * the term/query context.
 */
final class ProgrammaticPages {

	/** Taxonomies that get the hub treatment. */
	private const HUB_TAXONOMIES = array( Taxonomies::CATEGORY, Taxonomies::LOCATION );

	/**
	 * Hook archive output.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output_archive_schema' ) );
		add_filter( 'get_the_archive_description', array( $this, 'enrich_description' ) );
	}

	/**
	 * Emit BreadcrumbList + CollectionPage JSON-LD on hub archives.
	 */
	public function output_archive_schema(): void {
		$term = $this->current_hub_term();
		if ( ! $term ) {
			return;
		}

		$crumbs = self::build_breadcrumbs( $this->breadcrumb_trail( $term ) );
		echo "\n" . SchemaGenerator::render( $crumbs ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$collection = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'CollectionPage',
			'name'        => $term->name,
			'url'         => (string) get_term_link( $term ),
			'description' => self::intro_text( $term->name, (int) $term->count ),
		);
		echo SchemaGenerator::render( $collection ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Prepend the auto intro + comparison table to the archive description.
	 *
	 * @param string $description Existing archive description.
	 */
	public function enrich_description( string $description ): string {
		$term = $this->current_hub_term();
		if ( ! $term ) {
			return $description;
		}

		$intro = '<p class="lodestar-hub__intro">' . esc_html( self::intro_text( $term->name, (int) $term->count ) ) . '</p>';

		return $intro . self::render_comparison( $this->comparison_rows( $term ) ) . $description;
	}

	/*
	 * --------------------------------------------------------------------- *
	 *  Pure builders (unit-tested)
	 * --------------------------------------------------------------------- */

	/**
	 * Build BreadcrumbList JSON-LD from an ordered trail.
	 *
	 * @param array<int,array{name:string,url:string}> $trail Ordered crumbs.
	 * @return array<string,mixed>
	 */
	public static function build_breadcrumbs( array $trail ): array {
		$items = array();
		$position = 1;
		foreach ( $trail as $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => (string) ( $crumb['name'] ?? '' ),
				'item'     => (string) ( $crumb['url'] ?? '' ),
			);
		}

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}

	/**
	 * Generate the intro sentence for a hub.
	 *
	 * @param string $term_name Term name.
	 * @param int    $count     Listing count.
	 */
	public static function intro_text( string $term_name, int $count ): string {
		return sprintf(
			/* translators: 1: number of listings, 2: term name. */
			_n( 'Browse %1$d listing in %2$s.', 'Browse %1$d listings in %2$s.', $count, 'lodestar' ),
			$count,
			$term_name
		);
	}

	/**
	 * Render a comparison table from prepared rows (escaped).
	 *
	 * @param array<int,array{name:string,url:string,cells:array<string,string>}> $rows    Rows.
	 * @param array<string,string>                                                 $columns column key => label (optional).
	 */
	public static function render_comparison( array $rows, array $columns = array() ): string {
		if ( empty( $rows ) ) {
			return '';
		}

		// Derive columns from the first row when not supplied.
		if ( empty( $columns ) ) {
			$keys    = array_keys( (array) ( $rows[0]['cells'] ?? array() ) );
			$columns = array_combine( $keys, $keys ) ?: array();
		}

		$html  = '<table class="lodestar-table lodestar-hub__compare"><thead><tr>';
		$html .= '<th>' . esc_html__( 'Listing', 'lodestar' ) . '</th>';
		foreach ( $columns as $label ) {
			$html .= '<th>' . esc_html( (string) $label ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$html .= '<tr><td><a href="' . esc_url( (string) ( $row['url'] ?? '' ) ) . '">' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</a></td>';
			foreach ( array_keys( $columns ) as $key ) {
				$html .= '<td>' . esc_html( (string) ( $row['cells'][ $key ] ?? '' ) ) . '</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/*
	 * --------------------------------------------------------------------- *
	 *  WordPress context gathering
	 * --------------------------------------------------------------------- */

	/**
	 * The current hub term, or null if not on a hub archive.
	 *
	 * @return \WP_Term|null
	 */
	private function current_hub_term() {
		if ( ! is_tax( self::HUB_TAXONOMIES ) ) {
			return null;
		}

		$term = get_queried_object();

		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * Build the breadcrumb trail for a term (home → ancestors → term).
	 *
	 * @param \WP_Term $term Current term.
	 * @return array<int,array{name:string,url:string}>
	 */
	private function breadcrumb_trail( \WP_Term $term ): array {
		$trail = array( array( 'name' => __( 'Home', 'lodestar' ), 'url' => home_url( '/' ) ) );

		$ancestors = array_reverse( (array) get_ancestors( $term->term_id, $term->taxonomy ) );
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor instanceof \WP_Term ) {
				$trail[] = array( 'name' => $ancestor->name, 'url' => (string) get_term_link( $ancestor ) );
			}
		}

		$trail[] = array( 'name' => $term->name, 'url' => (string) get_term_link( $term ) );

		return $trail;
	}

	/**
	 * Gather comparison rows for the term's listings.
	 *
	 * @param \WP_Term $term Current term.
	 * @return array<int,array{name:string,url:string,cells:array<string,string>}>
	 */
	private function comparison_rows( \WP_Term $term ): array {
		$posts = get_posts(
			array(
				'post_type'      => ListingPostType::POST_TYPE,
				'post_status'    => 'publish',
				'numberposts'    => (int) apply_filters( 'lodestar_hub_compare_count', 10 ),
				'tax_query'      => array(
					array( 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => $term->term_id ),
				),
			)
		);

		$rows = array();
		foreach ( $posts as $post ) {
			$rows[] = array(
				'name'  => $post->post_title,
				'url'   => (string) get_permalink( $post ),
				'cells' => array(),
			);
		}

		return $rows;
	}
}
