<?php
/**
 * CRUD for field definitions.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\DirectoryType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence gateway for the `lodestar_fields` table.
 *
 * Also produces the definition map consumed by
 * {@see \Lodestar\Data\FieldIndexer::buildRows()}, which is how a directory
 * type's facetable fields end up in the search index.
 */
final class FieldManager {

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( private \wpdb $db ) {}

	/**
	 * Create a field on a directory type.
	 *
	 * @param array<string,mixed> $data Field attributes.
	 * @return int New ID, or 0 on failure (e.g. duplicate key on the type).
	 */
	public function create( array $data ): int {
		$type_id = (int) ( $data['directory_type_id'] ?? 0 );
		$key     = sanitize_key( (string) ( $data['field_key'] ?? '' ) );

		if ( 0 === $type_id || '' === $key || $this->findByKey( $type_id, $key ) ) {
			return 0;
		}

		$ok = $this->db->insert(
			$this->table(),
			array(
				'directory_type_id' => $type_id,
				'field_key'         => $key,
				'label'             => (string) ( $data['label'] ?? $key ),
				'input_type'        => $this->normalizeInputType( (string) ( $data['input_type'] ?? FieldDefinition::INPUT_TEXT ) ),
				'is_facetable'      => ! empty( $data['is_facetable'] ) ? 1 : 0,
				'is_required'       => ! empty( $data['is_required'] ) ? 1 : 0,
				'options_json'      => wp_json_encode( array_values( (array) ( $data['options'] ?? array() ) ) ),
				'schema_property'   => isset( $data['schema_property'] ) ? (string) $data['schema_property'] : null,
				'sort_order'        => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : $this->nextSortOrder( $type_id ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d' )
		);

		return $ok ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Update a field. Only present keys are touched.
	 *
	 * @param int                 $id   Field ID.
	 * @param array<string,mixed> $data Partial attributes.
	 */
	public function update( int $id, array $data ): bool {
		$map = array(
			'label'           => '%s',
			'input_type'      => '%s',
			'schema_property' => '%s',
			'sort_order'      => '%d',
		);

		$fields = array();
		$format = array();

		foreach ( $map as $key => $fmt ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = 'input_type' === $key ? $this->normalizeInputType( (string) $data[ $key ] ) : $data[ $key ];
				$format[]       = $fmt;
			}
		}

		if ( array_key_exists( 'is_facetable', $data ) ) {
			$fields['is_facetable'] = ! empty( $data['is_facetable'] ) ? 1 : 0;
			$format[]               = '%d';
		}
		if ( array_key_exists( 'is_required', $data ) ) {
			$fields['is_required'] = ! empty( $data['is_required'] ) ? 1 : 0;
			$format[]              = '%d';
		}
		if ( array_key_exists( 'options', $data ) ) {
			$fields['options_json'] = wp_json_encode( array_values( (array) $data['options'] ) );
			$format[]               = '%s';
		}

		if ( empty( $fields ) ) {
			return false;
		}

		return false !== $this->db->update( $this->table(), $fields, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/**
	 * Delete a field.
	 *
	 * @param int $id Field ID.
	 */
	public function delete( int $id ): bool {
		return (bool) $this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Find a field by ID.
	 *
	 * @param int $id Field ID.
	 */
	public function find( int $id ): ?FieldDefinition {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ? FieldDefinition::fromRow( $row ) : null;
	}

	/**
	 * Find a field by its key within a type.
	 *
	 * @param int    $type_id Directory type ID.
	 * @param string $key     Field key.
	 */
	public function findByKey( int $type_id, string $key ): ?FieldDefinition {
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE directory_type_id = %d AND field_key = %s',
				$type_id,
				$key
			),
			ARRAY_A
		);

		return $row ? FieldDefinition::fromRow( $row ) : null;
	}

	/**
	 * All fields for a directory type, in display order.
	 *
	 * @param int $type_id Directory type ID.
	 * @return FieldDefinition[]
	 */
	public function forType( int $type_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE directory_type_id = %d ORDER BY sort_order ASC, id ASC',
				$type_id
			),
			ARRAY_A
		);

		return array_map( array( FieldDefinition::class, 'fromRow' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Build the indexer definition map for a type's facetable fields.
	 *
	 * @param int $type_id Directory type ID.
	 * @return array<string,array{facetable:bool,type:string}>
	 */
	public function indexDefinitions( int $type_id ): array {
		$defs = array();
		foreach ( $this->forType( $type_id ) as $field ) {
			if ( $field->isIndexable() ) {
				$defs[ $field->fieldKey ] = array(
					'facetable' => true,
					'type'      => $field->storageType(),
				);
			}
		}

		return $defs;
	}

	/**
	 * Next sort order value for a type.
	 *
	 * @param int $type_id Directory type ID.
	 */
	private function nextSortOrder( int $type_id ): int {
		$max = $this->db->get_var(
			$this->db->prepare(
				'SELECT MAX(sort_order) FROM ' . $this->table() . ' WHERE directory_type_id = %d',
				$type_id
			)
		);

		return null === $max ? 0 : (int) $max + 1;
	}

	/**
	 * Coerce an input type to a known value (defaults to text).
	 *
	 * @param string $type Candidate input type.
	 */
	private function normalizeInputType( string $type ): string {
		return in_array( $type, FieldDefinition::inputTypes(), true ) ? $type : FieldDefinition::INPUT_TEXT;
	}

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'lodestar_fields';
	}
}
