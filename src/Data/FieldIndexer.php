<?php
/**
 * Writes facet rows into lodestar_field_index on save.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maintains the faceted-search index for a listing.
 *
 * The index is fully rebuilt per listing on save (delete-then-insert), which
 * keeps it consistent and avoids partial-update drift. Only facetable fields
 * land here; non-facetable extras live in the `meta` JSON column on
 * `listing_data` and are never indexed.
 */
final class FieldIndexer {

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( private \wpdb $db ) {}

	/**
	 * Replace all index rows for a listing.
	 *
	 * @param int                                                                              $listing_id Listing (post) ID.
	 * @param array<int,array{field_key:string,value_text?:?string,value_num?:?float,value_date?:?string}> $rows Typed rows.
	 */
	public function replace( int $listing_id, array $rows ): void {
		$this->delete( $listing_id );

		$table = $this->table();

		foreach ( $rows as $row ) {
			if ( empty( $row['field_key'] ) ) {
				continue;
			}

			$this->db->insert(
				$table,
				array(
					'listing_id' => $listing_id,
					'field_key'  => (string) $row['field_key'],
					'value_text' => $row['value_text'] ?? null,
					'value_num'  => $row['value_num'] ?? null,
					'value_date' => $row['value_date'] ?? null,
				),
				array( '%d', '%s', '%s', '%f', '%s' )
			);
		}
	}

	/**
	 * Remove every index row for a listing.
	 *
	 * @param int $listing_id Listing (post) ID.
	 */
	public function delete( int $listing_id ): void {
		$this->db->delete( $this->table(), array( 'listing_id' => $listing_id ), array( '%d' ) );
	}

	/**
	 * Translate raw field values + their definitions into typed index rows.
	 *
	 * Definitions map a field_key to its facetability and storage type:
	 *   [ 'cuisine' => [ 'facetable' => true, 'type' => 'text' ], ... ]
	 * where `type` is one of text|num|date. Non-facetable fields are skipped.
	 * Array values (multiselect) produce one row each.
	 *
	 * @param array<string,mixed>                                     $values      field_key => value(s).
	 * @param array<string,array{facetable?:bool,type?:string}>       $definitions field_key => def.
	 * @return array<int,array{field_key:string,value_text?:?string,value_num?:?float,value_date?:?string}>
	 */
	public static function buildRows( array $values, array $definitions ): array {
		$rows = array();

		foreach ( $values as $key => $value ) {
			$def = $definitions[ $key ] ?? array();

			if ( empty( $def['facetable'] ) ) {
				continue;
			}

			$type = (string) ( $def['type'] ?? Facet::TYPE_TEXT );
			$list = is_array( $value ) ? $value : array( $value );

			foreach ( $list as $single ) {
				if ( null === $single || '' === $single ) {
					continue;
				}
				$rows[] = self::typedRow( (string) $key, $type, $single );
			}
		}

		return $rows;
	}

	/**
	 * Build a single typed row for the given storage type.
	 *
	 * @param string $key   Field key.
	 * @param string $type  text|num|date.
	 * @param mixed  $value Raw value.
	 * @return array{field_key:string,value_text:?string,value_num:?float,value_date:?string}
	 */
	private static function typedRow( string $key, string $type, $value ): array {
		$row = array(
			'field_key'  => $key,
			'value_text' => null,
			'value_num'  => null,
			'value_date' => null,
		);

		switch ( $type ) {
			case Facet::TYPE_NUM:
				$row['value_num'] = (float) $value;
				break;
			case Facet::TYPE_DATE:
				$row['value_date'] = gmdate( 'Y-m-d H:i:s', is_numeric( $value ) ? (int) $value : (int) strtotime( (string) $value ) );
				break;
			default:
				// Indexed column is varchar(191); keep within bounds.
				$row['value_text'] = mb_substr( (string) $value, 0, 191 );
				break;
		}

		return $row;
	}

	/**
	 * Fully-qualified field_index table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'lodestar_field_index';
	}
}
