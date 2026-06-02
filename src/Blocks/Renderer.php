<?php
/**
 * Server-side render callbacks for the Gutenberg blocks.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Blocks;

use Lodestar\Aeo\FaqBlock;
use Lodestar\Data\Facet;
use Lodestar\Data\ListingRepository;
use Lodestar\Data\SearchQuery;
use Lodestar\DirectoryType\DirectoryTypeManager;
use Lodestar\DirectoryType\FieldManager;
use Lodestar\Frontend\Assets;
use Lodestar\Frontend\MapRenderer;
use Lodestar\Frontend\SearchController;
use Lodestar\Frontend\SubmissionController;
use Lodestar\Frontend\TemplateLoader;
use Lodestar\PostType\ListingPostType;
use Lodestar\PostType\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamic blocks render through these methods so block output always matches
 * the shortcodes and stays update-safe (templates remain theme-overridable).
 * Blocks are dynamic (no saved markup), so a plugin update never leaves stale
 * HTML in post content.
 */
final class Renderer {

	/**
	 * The search-form block → the search controller.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function search( array $attributes ): string {
		[ $repo, $types, $fields, $templates ] = self::deps();

		return ( new SearchController( $repo, $types, $fields, $templates ) )->render(
			array(
				'type' => (string) ( $attributes['type'] ?? '' ),
				'map'  => empty( $attributes['showMap'] ) ? '0' : '1',
			)
		);
	}

	/**
	 * The submit-form block → the submission controller.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function submit( array $attributes ): string {
		[ $repo, $types, $fields, $templates ] = self::deps();

		return ( new SubmissionController( $repo, $types, $fields, $templates ) )->render(
			array( 'type' => (string) ( $attributes['type'] ?? '' ) )
		);
	}

	/**
	 * The listings-grid block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function grid( array $attributes ): string {
		[ $repo, $types, $fields, $templates ] = self::deps();

		$type = self::resolve_type( $types, (string) ( $attributes['type'] ?? '' ) );
		if ( ! $type ) {
			return '';
		}

		$facets = array();
		$category = (int) ( $attributes['category'] ?? 0 );
		if ( $category > 0 ) {
			$facets[] = new Facet( Taxonomies::CATEGORY, Facet::TYPE_NUM, array(), (float) $category, (float) $category );
		}

		$result = $repo->search(
			new SearchQuery(
				directoryTypeId: $type->id,
				facets: $facets,
				orderBy: SearchQuery::ORDER_RELEVANCE,
				perPage: max( 1, min( 48, (int) ( $attributes['perPage'] ?? 12 ) ) ),
				status: 'publish',
			)
		);

		$cards = array();
		foreach ( $result->items as $row ) {
			$id      = (int) ( $row->listing_id ?? 0 );
			$cards[] = array(
				'title'     => get_the_title( $id ),
				'url'       => (string) get_permalink( $id ),
				'rating'    => isset( $row->rating_avg ) ? (float) $row->rating_avg : 0.0,
				'featured'  => ! empty( $row->is_featured ),
				'thumbnail' => (string) get_the_post_thumbnail_url( $id, 'medium' ),
			);
		}

		Assets::enqueue_style();

		return $templates->render( 'listings-grid', array( 'cards' => $cards, 'total' => $result->total ) );
	}

	/**
	 * The single-listing details block (current post).
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function single( array $attributes ): string {
		[ $repo, $types, $fields, $templates ] = self::deps();

		$id      = get_the_ID();
		$listing = $id ? $repo->find( (int) $id ) : null;
		if ( ! $listing ) {
			return '';
		}

		$type_id = (int) ( $listing['data']['directory_type_id'] ?? 0 );
		$labels  = array();
		foreach ( $fields->forType( $type_id ) as $field ) {
			$labels[ $field->fieldKey ] = $field->label;
		}

		$rows = array();
		foreach ( (array) $listing['facets'] as $facet ) {
			$key = (string) ( $facet['field_key'] ?? '' );
			if ( '' === $key || str_starts_with( $key, 'ld_' ) ) {
				continue;
			}
			$value = $facet['value_text'] ?? ( $facet['value_num'] ?? '' );
			$rows[] = array( 'label' => $labels[ $key ] ?? $key, 'value' => (string) $value );
		}

		$faq_html = '';
		if ( ! empty( $listing['meta']['faq'] ) && is_array( $listing['meta']['faq'] ) ) {
			$faq_html = FaqBlock::render( $listing['meta']['faq'] );
		}

		Assets::enqueue_style();

		return $templates->render(
			'single-listing',
			array(
				'rows'     => $rows,
				'rating'   => array( 'average' => (float) ( $listing['data']['rating_avg'] ?? 0 ), 'count' => (int) ( $listing['data']['rating_count'] ?? 0 ) ),
				'faq_html' => $faq_html,
			)
		);
	}

	/**
	 * The map block — plots a type's geocoded listings.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function map( array $attributes ): string {
		[ $repo, $types, $fields, $templates ] = self::deps();

		$type = self::resolve_type( $types, (string) ( $attributes['type'] ?? '' ) );
		if ( ! $type ) {
			return '';
		}

		$result = $repo->search(
			new SearchQuery(
				directoryTypeId: $type->id,
				perPage: 200,
				status: 'publish',
			)
		);

		$markers = array();
		foreach ( $result->items as $row ) {
			if ( ! empty( $row->lat ) && ! empty( $row->lng ) ) {
				$id        = (int) ( $row->listing_id ?? 0 );
				$markers[] = array( 'lat' => (float) $row->lat, 'lng' => (float) $row->lng, 'title' => get_the_title( $id ), 'url' => (string) get_permalink( $id ) );
			}
		}

		if ( empty( $markers ) ) {
			return '';
		}

		Assets::enqueue_map();

		return MapRenderer::render( $markers, array( 'height' => (int) ( $attributes['height'] ?? 420 ) ) );
	}

	/**
	 * Build the shared dependencies.
	 *
	 * @return array{0:ListingRepository,1:DirectoryTypeManager,2:FieldManager,3:TemplateLoader}
	 */
	private static function deps(): array {
		global $wpdb;

		return array(
			new ListingRepository( $wpdb ),
			new DirectoryTypeManager( $wpdb ),
			new FieldManager( $wpdb ),
			new TemplateLoader(),
		);
	}

	/**
	 * Resolve a type by slug or fall back to the first.
	 *
	 * @param DirectoryTypeManager $types Manager.
	 * @param string               $slug  Slug.
	 */
	private static function resolve_type( DirectoryTypeManager $types, string $slug ) {
		if ( '' !== $slug ) {
			return $types->findBySlug( $slug );
		}
		$all = $types->all();

		return $all[0] ?? null;
	}
}
