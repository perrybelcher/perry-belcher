<?php
/**
 * AI-citability scoring.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Ai;

use Lodestar\DirectoryType\FieldDefinition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grades a listing 0–100 for how citable it is by AI search — completeness,
 * structured-data coverage, FAQ presence, and freshness — and returns the
 * actionable gaps. Fully deterministic and pure, so it is unit-tested and the
 * score is stable.
 *
 * Weights (sum 100): description 20, structured fields 20, FAQ 15, title 10,
 * geo 10, rating 10, terms 10, freshness 5.
 */
final class CitabilityScorer {

	private const FRESH_DAYS = 180;

	/**
	 * Score a listing.
	 *
	 * @param array<string,mixed> $listing Hydrated listing (repo->find shape).
	 * @param FieldDefinition[]   $defs    Facetable field definitions for coverage.
	 * @param string|null         $now     Reference datetime (defaults to now, GMT).
	 * @return array{score:int,gaps:array<int,string>,breakdown:array<string,int>}
	 */
	public static function score( array $listing, array $defs = array(), ?string $now = null ): array {
		$now       = $now ?? gmdate( 'Y-m-d H:i:s' );
		$data      = (array) ( $listing['data'] ?? array() );
		$breakdown = array();
		$gaps      = array();

		// Title (10).
		$breakdown['title'] = '' !== trim( (string) ( $listing['title'] ?? '' ) ) ? 10 : 0;
		if ( 0 === $breakdown['title'] ) {
			$gaps[] = __( 'Add a descriptive title.', 'lodestar' );
		}

		// Description (20): graded by length.
		$desc_len           = strlen( trim( wp_strip_all_tags( (string) ( $listing['content'] ?? '' ) ) ) );
		$breakdown['description'] = $desc_len >= 300 ? 20 : ( $desc_len >= 120 ? 12 : ( $desc_len >= 40 ? 6 : 0 ) );
		if ( $breakdown['description'] < 20 ) {
			$gaps[] = __( 'Expand the description to at least a few sentences (300+ characters).', 'lodestar' );
		}

		// Structured field coverage (20).
		$breakdown['fields'] = self::field_coverage_points( $listing, $defs, $gaps );

		// FAQ (15).
		$faq_count          = self::faq_count( $listing );
		$breakdown['faq']   = $faq_count >= 2 ? 15 : ( 1 === $faq_count ? 7 : 0 );
		if ( $breakdown['faq'] < 15 ) {
			$gaps[] = __( 'Add at least two FAQ entries — they are highly citable.', 'lodestar' );
		}

		// Geo (10).
		$has_geo            = isset( $data['lat'], $data['lng'] ) && '' !== (string) $data['lat'] && '' !== (string) $data['lng'];
		$breakdown['geo']   = $has_geo ? 10 : 0;
		if ( ! $has_geo ) {
			$gaps[] = __( 'Add a location (latitude/longitude) for local citability.', 'lodestar' );
		}

		// Rating (10).
		$breakdown['rating'] = (int) ( $data['rating_count'] ?? 0 ) > 0 ? 10 : 0;
		if ( 0 === $breakdown['rating'] ) {
			$gaps[] = __( 'Collect reviews — ratings strengthen citations.', 'lodestar' );
		}

		// Taxonomy terms (10): 5 category + 5 location.
		$breakdown['terms'] = 0;
		if ( ! empty( $listing['categories'] ) ) {
			$breakdown['terms'] += 5;
		} else {
			$gaps[] = __( 'Assign a category.', 'lodestar' );
		}
		if ( ! empty( $listing['locations'] ) ) {
			$breakdown['terms'] += 5;
		} else {
			$gaps[] = __( 'Assign a location.', 'lodestar' );
		}

		// Freshness (5).
		$updated              = (string) ( $data['updated_at'] ?? '' );
		$fresh                = '' !== $updated && ( strtotime( $now ) - strtotime( $updated ) ) <= self::FRESH_DAYS * DAY_IN_SECONDS;
		$breakdown['freshness'] = $fresh ? 5 : 0;
		if ( ! $fresh ) {
			$gaps[] = __( 'Update the listing — fresh content is favoured.', 'lodestar' );
		}

		$score = (int) array_sum( $breakdown );

		return array(
			'score'     => max( 0, min( 100, $score ) ),
			'gaps'      => $gaps,
			'breakdown' => $breakdown,
		);
	}

	/**
	 * Points (0–20) for the share of facetable fields that are filled.
	 *
	 * @param array<string,mixed> $listing Hydrated listing.
	 * @param FieldDefinition[]   $defs    Field definitions.
	 * @param array<int,string>   $gaps    Gaps accumulator (by reference).
	 */
	private static function field_coverage_points( array $listing, array $defs, array &$gaps ): int {
		$facetable = array_values(
			array_filter( $defs, static fn ( FieldDefinition $d ) => $d->isIndexable() )
		);

		if ( empty( $facetable ) ) {
			return 20; // Nothing to fill; don't penalise.
		}

		$filled_keys = array();
		foreach ( (array) ( $listing['facets'] ?? array() ) as $facet ) {
			$key = (string) ( $facet['field_key'] ?? '' );
			if ( '' !== $key && ! str_starts_with( $key, 'ld_' ) ) {
				$filled_keys[ $key ] = true;
			}
		}

		$filled = 0;
		foreach ( $facetable as $def ) {
			if ( isset( $filled_keys[ $def->fieldKey ] ) ) {
				++$filled;
			}
		}

		$ratio = $filled / count( $facetable );
		if ( $ratio < 1.0 ) {
			$gaps[] = __( 'Fill in more of the structured fields for richer data.', 'lodestar' );
		}

		return (int) round( 20 * $ratio );
	}

	/**
	 * Count valid FAQ pairs on a listing.
	 *
	 * @param array<string,mixed> $listing Hydrated listing.
	 */
	private static function faq_count( array $listing ): int {
		$faqs = $listing['meta']['faq'] ?? array();
		if ( ! is_array( $faqs ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $faqs as $faq ) {
			if ( is_array( $faq )
				&& '' !== trim( (string) ( $faq['q'] ?? $faq['question'] ?? '' ) )
				&& '' !== trim( (string) ( $faq['a'] ?? $faq['answer'] ?? '' ) ) ) {
				++$count;
			}
		}

		return $count;
	}
}
