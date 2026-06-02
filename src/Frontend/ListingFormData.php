<?php
/**
 * Validates and maps a submitted listing form to repository data.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Frontend;

use Lodestar\DirectoryType\FieldDefinition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure transform: (sanitised form input + field definitions) -> ($data,
 * $errors) where $data is ready for {@see \Lodestar\Data\ListingRepository}.
 *
 * Responsibilities:
 *  - Enforce required fields (title + any required custom field).
 *  - Split custom fields into facetable (indexed) vs non-facetable (meta),
 *    and route geo into the listing_data lat/lng columns.
 *  - Never invent state — directory_type_id, status and author are supplied by
 *    the controller from the request context, not the form body.
 *
 * No WordPress calls here, so it is unit-testable in isolation. Values must be
 * pre-sanitised (see {@see FieldSanitizer}).
 */
final class ListingFormData {

	/**
	 * @param string                                              $title  Sanitised listing title.
	 * @param string                                              $content Sanitised long description.
	 * @param array<string,mixed>                                 $fields Sanitised field_key => value(s).
	 * @param FieldDefinition[]                                   $defs   Field definitions for the type.
	 * @param array{category?:int[],location?:int[],tag?:int[]}   $terms  Taxonomy term IDs.
	 * @return array{data:array<string,mixed>,errors:array<int,string>}
	 */
	public static function build( string $title, string $content, array $fields, array $defs, array $terms = array() ): array {
		$errors = array();

		if ( '' === trim( $title ) ) {
			$errors[] = __( 'A title is required.', 'lodestar' );
		}

		$data = array(
			'title'   => $title,
			'content' => $content,
		);

		$index_values = array();
		$index_defs   = array();
		$meta         = array();

		foreach ( $defs as $field ) {
			$value = $fields[ $field->fieldKey ] ?? null;

			if ( $field->isRequired && FieldSanitizer::isEmpty( $value ) ) {
				/* translators: %s: field label. */
				$errors[] = sprintf( __( '“%s” is required.', 'lodestar' ), $field->label );
			}

			if ( FieldSanitizer::isEmpty( $value ) ) {
				continue;
			}

			// Geo routes to the native, indexed lat/lng columns.
			if ( FieldDefinition::INPUT_GEO === $field->inputType ) {
				if ( is_array( $value ) && isset( $value['lat'], $value['lng'] ) ) {
					$data['lat'] = (float) $value['lat'];
					$data['lng'] = (float) $value['lng'];
				}
				continue;
			}

			if ( $field->isIndexable() ) {
				$index_values[ $field->fieldKey ] = $value;
				$index_defs[ $field->fieldKey ]   = array(
					'facetable' => true,
					'type'      => $field->storageType(),
				);
			} else {
				$meta[ $field->fieldKey ] = $value;
			}
		}

		if ( $index_values ) {
			$data['fields']     = $index_values;
			$data['field_defs'] = $index_defs;
		}
		if ( $meta ) {
			$data['meta'] = $meta;
		}

		foreach ( array( 'category', 'location', 'tag' ) as $taxonomy ) {
			if ( ! empty( $terms[ $taxonomy ] ) ) {
				$data[ $taxonomy ] = array_values( array_map( 'intval', (array) $terms[ $taxonomy ] ) );
			}
		}

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}
}
