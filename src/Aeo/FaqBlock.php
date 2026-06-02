<?php
/**
 * FAQ rendering + FAQPage JSON-LD.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Aeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a list of question/answer pairs into accessible markup and the matching
 * FAQPage schema. FAQs live on a listing's meta (`faq` => [['q'=>, 'a'=>], …])
 * or are passed straight to a hub page.
 *
 * Both methods are pure (input → output), so the schema can be validated and the
 * markup checked for escaping without WordPress.
 */
final class FaqBlock {

	/**
	 * Build FAQPage JSON-LD, or null when there are no valid pairs.
	 *
	 * @param array<int,array<string,string>> $faqs Q/A pairs.
	 * @return array<string,mixed>|null
	 */
	public static function schema( array $faqs ): ?array {
		$entities = array();

		foreach ( self::normalize( $faqs ) as $pair ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $pair['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wp_strip_all_tags( $pair['a'] ),
				),
			);
		}

		if ( empty( $entities ) ) {
			return null;
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
	}

	/**
	 * Render an accessible FAQ list (escaped).
	 *
	 * @param array<int,array<string,string>> $faqs Q/A pairs.
	 */
	public static function render( array $faqs ): string {
		$pairs = self::normalize( $faqs );
		if ( empty( $pairs ) ) {
			return '';
		}

		$html = '<div class="lodestar lodestar-faq">';
		foreach ( $pairs as $pair ) {
			$html .= '<details class="lodestar-faq__item">';
			$html .= '<summary class="lodestar-faq__q">' . esc_html( $pair['q'] ) . '</summary>';
			$html .= '<div class="lodestar-faq__a">' . wp_kses_post( $pair['a'] ) . '</div>';
			$html .= '</details>';
		}

		return $html . '</div>';
	}

	/**
	 * Normalise/validate Q/A pairs (supports q/a and question/answer keys).
	 *
	 * @param array<int,array<string,string>> $faqs Raw pairs.
	 * @return array<int,array{q:string,a:string}>
	 */
	private static function normalize( array $faqs ): array {
		$out = array();
		foreach ( $faqs as $faq ) {
			if ( ! is_array( $faq ) ) {
				continue;
			}
			$q = trim( (string) ( $faq['q'] ?? $faq['question'] ?? '' ) );
			$a = trim( (string) ( $faq['a'] ?? $faq['answer'] ?? '' ) );
			if ( '' !== $q && '' !== $a ) {
				$out[] = array( 'q' => $q, 'a' => $a );
			}
		}

		return $out;
	}
}
