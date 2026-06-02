<?php
/**
 * Field definition value object.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\DirectoryType;

use Lodestar\Data\Facet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One field within a directory type. Drives both the dynamic form
 * ({@see FormBuilder}) and, when facetable, the search index
 * ({@see \Lodestar\Data\FieldIndexer}).
 */
final class FieldDefinition {

	public const INPUT_TEXT        = 'text';
	public const INPUT_NUMBER      = 'number';
	public const INPUT_SELECT      = 'select';
	public const INPUT_MULTISELECT = 'multiselect';
	public const INPUT_CHECKBOX    = 'checkbox';
	public const INPUT_DATE        = 'date';
	public const INPUT_URL         = 'url';
	public const INPUT_TEL         = 'tel';
	public const INPUT_GEO         = 'geo';
	public const INPUT_FILE        = 'file';
	public const INPUT_RICHTEXT    = 'richtext';

	/**
	 * All valid input types.
	 *
	 * @return string[]
	 */
	public static function inputTypes(): array {
		return array(
			self::INPUT_TEXT,
			self::INPUT_NUMBER,
			self::INPUT_SELECT,
			self::INPUT_MULTISELECT,
			self::INPUT_CHECKBOX,
			self::INPUT_DATE,
			self::INPUT_URL,
			self::INPUT_TEL,
			self::INPUT_GEO,
			self::INPUT_FILE,
			self::INPUT_RICHTEXT,
		);
	}

	/**
	 * Input types that are never indexed (stored in listing_data.meta or as
	 * native columns), regardless of the is_facetable flag.
	 */
	private const NON_INDEXABLE = array(
		self::INPUT_RICHTEXT,
		self::INPUT_FILE,
		self::INPUT_GEO,
	);

	/**
	 * @param int                 $id              Row ID (0 for unsaved).
	 * @param int                 $directoryTypeId Owning directory type.
	 * @param string              $fieldKey        Machine key (a-z0-9_).
	 * @param string              $label           Human label.
	 * @param string              $inputType       One of the INPUT_* constants.
	 * @param bool                $isFacetable     Whether it feeds the facet index.
	 * @param bool                $isRequired      Whether the form requires it.
	 * @param array<int,mixed>    $options         Choices for select/multiselect.
	 * @param string|null         $schemaProperty  schema.org property mapping.
	 * @param int                 $sortOrder       Display order.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $directoryTypeId,
		public readonly string $fieldKey,
		public readonly string $label,
		public readonly string $inputType = self::INPUT_TEXT,
		public readonly bool $isFacetable = false,
		public readonly bool $isRequired = false,
		public readonly array $options = array(),
		public readonly ?string $schemaProperty = null,
		public readonly int $sortOrder = 0,
	) {}

	/**
	 * Whether this field should be written to the facet index.
	 */
	public function isIndexable(): bool {
		return $this->isFacetable && ! in_array( $this->inputType, self::NON_INDEXABLE, true );
	}

	/**
	 * The field_index storage column type for this field: text|num|date.
	 */
	public function storageType(): string {
		return match ( $this->inputType ) {
			self::INPUT_NUMBER => Facet::TYPE_NUM,
			self::INPUT_DATE   => Facet::TYPE_DATE,
			default            => Facet::TYPE_TEXT,
		};
	}

	/**
	 * Whether the field accepts multiple values.
	 */
	public function isMultiple(): bool {
		return self::INPUT_MULTISELECT === $this->inputType;
	}

	/**
	 * Hydrate from a DB row.
	 *
	 * @param array<string,mixed> $row Associative DB row.
	 */
	public static function fromRow( array $row ): self {
		$options = array();
		if ( ! empty( $row['options_json'] ) ) {
			$decoded = json_decode( (string) $row['options_json'], true );
			$options = is_array( $decoded ) ? $decoded : array();
		}

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['directory_type_id'] ?? 0 ),
			(string) ( $row['field_key'] ?? '' ),
			(string) ( $row['label'] ?? '' ),
			(string) ( $row['input_type'] ?? self::INPUT_TEXT ),
			! empty( $row['is_facetable'] ),
			! empty( $row['is_required'] ),
			$options,
			isset( $row['schema_property'] ) ? (string) $row['schema_property'] : null,
			(int) ( $row['sort_order'] ?? 0 ),
		);
	}
}
