<?php
/**
 * CRUD for directory types.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\DirectoryType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence gateway for the `lodestar_directory_types` table.
 */
final class DirectoryTypeManager {

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( private \wpdb $db ) {}

	/**
	 * Create a directory type.
	 *
	 * @param array<string,mixed> $data slug, label, singular_label, config.
	 * @return int New ID, or 0 on failure (e.g. duplicate slug).
	 */
	public function create( array $data ): int {
		$slug = sanitize_key( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug || $this->findBySlug( $slug ) ) {
			return 0;
		}

		$ok = $this->db->insert(
			$this->table(),
			array(
				'slug'           => $slug,
				'label'          => (string) ( $data['label'] ?? '' ),
				'singular_label' => (string) ( $data['singular_label'] ?? '' ),
				'config_json'    => wp_json_encode( (array) ( $data['config'] ?? array() ) ),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Update a directory type. Only present keys are touched.
	 *
	 * @param int                 $id   Type ID.
	 * @param array<string,mixed> $data Partial data.
	 */
	public function update( int $id, array $data ): bool {
		$fields = array();
		$format = array();

		if ( array_key_exists( 'label', $data ) ) {
			$fields['label'] = (string) $data['label'];
			$format[]        = '%s';
		}
		if ( array_key_exists( 'singular_label', $data ) ) {
			$fields['singular_label'] = (string) $data['singular_label'];
			$format[]                 = '%s';
		}
		if ( array_key_exists( 'config', $data ) ) {
			$fields['config_json'] = wp_json_encode( (array) $data['config'] );
			$format[]              = '%s';
		}

		if ( empty( $fields ) ) {
			return false;
		}

		return false !== $this->db->update( $this->table(), $fields, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/**
	 * Delete a directory type.
	 *
	 * @param int $id Type ID.
	 */
	public function delete( int $id ): bool {
		return (bool) $this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Find a type by ID.
	 *
	 * @param int $id Type ID.
	 */
	public function find( int $id ): ?DirectoryType {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ? DirectoryType::fromRow( $row ) : null;
	}

	/**
	 * Find a type by slug.
	 *
	 * @param string $slug Type slug.
	 */
	public function findBySlug( string $slug ): ?DirectoryType {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE slug = %s', $slug ),
			ARRAY_A
		);

		return $row ? DirectoryType::fromRow( $row ) : null;
	}

	/**
	 * All directory types, ordered by label.
	 *
	 * @return DirectoryType[]
	 */
	public function all(): array {
		$rows = $this->db->get_results( 'SELECT * FROM ' . $this->table() . ' ORDER BY label ASC', ARRAY_A );

		return array_map( array( DirectoryType::class, 'fromRow' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Ensure a baseline "general" directory type exists; return its ID.
	 *
	 * Called on activation so a stock install is usable immediately.
	 */
	public function ensureDefault(): int {
		$existing = $this->findBySlug( 'general' );
		if ( $existing ) {
			return $existing->id;
		}

		return $this->create(
			array(
				'slug'           => 'general',
				'label'          => __( 'Listings', 'lodestar' ),
				'singular_label' => __( 'Listing', 'lodestar' ),
				'config'         => array( 'schema_type' => 'LocalBusiness' ),
			)
		);
	}

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'lodestar_directory_types';
	}
}
