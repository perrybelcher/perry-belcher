<?php
/**
 * Per-listing JSON-LD generator.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Aeo;

use Lodestar\Data\ListingRepository;
use Lodestar\DirectoryType\DirectoryType;
use Lodestar\DirectoryType\DirectoryTypeManager;
use Lodestar\DirectoryType\FieldManager;
use Lodestar\PostType\ListingPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits schema.org JSON-LD for single listings (and a FAQPage when present).
 *
 * The schema @type and the field→property mapping are configurable per
 * directory type — never hardcoded. Each {@see \Lodestar\DirectoryType\FieldDefinition}
 * carries an optional `schema_property`; the type's `config['schema_type']`
 * picks the top-level type and `config['schema_map']` can override per field.
 *
 * {@see self::build()} is pure (array in, array out) so it is fully unit-testable
 * and the output can be validated against Google's Rich Results expectations.
 */
final class SchemaGenerator {

	public function __construct(
		private ListingRepository $repo,
		private DirectoryTypeManager $types,
		private FieldManager $fields,
	) {}

	/**
	 * Hook the head output.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output_listing_schema' ) );
	}

	/**
	 * Print JSON-LD on single listing pages.
	 */
	public function output_listing_schema(): void {
		if ( ! is_singular( ListingPostType::POST_TYPE ) ) {
			return;
		}

		$id      = get_queried_object_id();
		$listing = $this->repo->find( $id );
		if ( ! $listing ) {
			return;
		}

		$type_id     = (int) ( $listing['data']['directory_type_id'] ?? 0 );
		$type        = $type_id > 0 ? $this->types->find( $type_id ) : null;
		$schema_type = $type && isset( $type->config['schema_type'] ) ? (string) $type->config['schema_type'] : 'LocalBusiness';

		$schema = self::build(
			$listing,
			$schema_type,
			$this->schema_map( $type_id, $type ),
			array( 'url' => (string) get_permalink( $id ) )
		);

		/** @var array<string,mixed> $schema */
		$schema = (array) apply_filters( 'lodestar_listing_schema', $schema, $listing, $type );

		// JSON-LD is encoded with JSON_HEX_TAG, so it cannot break out of the script.
		echo "\n" . self::render( $schema ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$faqs = $listing['meta']['faq'] ?? array();
		if ( is_array( $faqs ) ) {
			$faq_schema = FaqBlock::schema( $faqs );
			if ( null !== $faq_schema ) {
				echo self::render( $faq_schema ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}

	/**
	 * Build the JSON-LD array for a listing.
	 *
	 * @param array<string,mixed>   $listing Hydrated listing (repo->find shape).
	 * @param string                $type    schema.org @type.
	 * @param array<string,string>  $map     field_key => schema property.
	 * @param array<string,mixed>   $ctx     url, name, currency overrides.
	 * @return array<string,mixed>
	 */
	public static function build( array $listing, string $type = 'LocalBusiness', array $map = array(), array $ctx = array() ): array {
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
		);

		$name = (string) ( $ctx['name'] ?? ( $listing['title'] ?? '' ) );
		if ( '' !== $name ) {
			$schema['name'] = $name;
		}

		if ( ! empty( $ctx['url'] ) ) {
			$schema['url'] = (string) $ctx['url'];
		}

		$description = trim( wp_strip_all_tags( (string) ( $listing['content'] ?? '' ) ) );
		if ( '' !== $description ) {
			$schema['description'] = $description;
		}

		$data = (array) ( $listing['data'] ?? array() );

		$lat = $data['lat'] ?? null;
		$lng = $data['lng'] ?? null;
		if ( null !== $lat && null !== $lng && '' !== (string) $lat && '' !== (string) $lng ) {
			$schema['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lng,
			);
		}

		$rating_count = (int) ( $data['rating_count'] ?? 0 );
		if ( $rating_count > 0 ) {
			$schema['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => round( (float) ( $data['rating_avg'] ?? 0 ), 2 ),
				'reviewCount' => $rating_count,
			);
		}

		$price = $data['price'] ?? null;
		if ( null !== $price && '' !== (string) $price && (float) $price > 0 ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => (float) $price,
				'priceCurrency' => (string) ( $ctx['currency'] ?? 'USD' ),
			);
		}

		$values = self::flatten( $listing );
		foreach ( $map as $field_key => $property ) {
			$property = (string) $property;
			if ( '' === $property || ! array_key_exists( $field_key, $values ) ) {
				continue;
			}
			$value = $values[ $field_key ];
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			$schema[ $property ] = $value;
		}

		return $schema;
	}

	/**
	 * Wrap a JSON-LD array in a script tag (safe for inline output).
	 *
	 * @param array<string,mixed> $schema JSON-LD data.
	 */
	public static function render( array $schema ): string {
		$json = (string) wp_json_encode( $schema, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE );

		return '<script type="application/ld+json">' . $json . '</script>';
	}

	/**
	 * Flatten a listing's facet + meta values into a field_key => value map.
	 *
	 * @param array<string,mixed> $listing Hydrated listing.
	 * @return array<string,mixed>
	 */
	private static function flatten( array $listing ): array {
		$out = array();

		foreach ( (array) ( $listing['facets'] ?? array() ) as $facet ) {
			$key = (string) ( $facet['field_key'] ?? '' );
			if ( '' === $key || str_starts_with( $key, 'ld_' ) ) {
				continue; // taxonomy denorm rows are not schema fields.
			}

			$value = null;
			if ( isset( $facet['value_text'] ) && '' !== (string) $facet['value_text'] ) {
				$value = (string) $facet['value_text'];
			} elseif ( isset( $facet['value_num'] ) && null !== $facet['value_num'] ) {
				$value = (float) $facet['value_num'];
			} elseif ( isset( $facet['value_date'] ) ) {
				$value = (string) $facet['value_date'];
			}

			if ( array_key_exists( $key, $out ) ) {
				$out[ $key ] = array_merge( (array) $out[ $key ], array( $value ) );
			} else {
				$out[ $key ] = $value;
			}
		}

		foreach ( (array) ( $listing['meta'] ?? array() ) as $key => $value ) {
			if ( 'faq' === $key ) {
				continue;
			}
			if ( ! array_key_exists( $key, $out ) ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Assemble the field→property map for a type (defs + config override).
	 *
	 * @param int                $type_id Directory type ID.
	 * @param DirectoryType|null $type    Directory type (for config override).
	 * @return array<string,string>
	 */
	private function schema_map( int $type_id, ?DirectoryType $type ): array {
		$map = array();
		foreach ( $this->fields->forType( $type_id ) as $field ) {
			if ( $field->schemaProperty ) {
				$map[ $field->fieldKey ] = $field->schemaProperty;
			}
		}

		if ( $type && ! empty( $type->config['schema_map'] ) && is_array( $type->config['schema_map'] ) ) {
			$map = array_merge( $map, $type->config['schema_map'] );
		}

		return $map;
	}
}
