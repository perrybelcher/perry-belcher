<?php
/**
 * Directory type value object.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\DirectoryType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One directory type (e.g. "Restaurants", "Jobs"). Multiple types coexist on a
 * single install, each owning an isolated set of {@see FieldDefinition}s.
 */
final class DirectoryType {

	/**
	 * @param int                 $id            Row ID (0 for unsaved).
	 * @param string              $slug          Machine slug.
	 * @param string              $label         Plural label.
	 * @param string              $singularLabel Singular label.
	 * @param array<string,mixed> $config        Free-form config (schema map, default plan, layout).
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $slug,
		public readonly string $label,
		public readonly string $singularLabel,
		public readonly array $config = array(),
	) {}

	/**
	 * Hydrate from a DB row.
	 *
	 * @param array<string,mixed> $row Associative DB row.
	 */
	public static function fromRow( array $row ): self {
		$config = array();
		if ( ! empty( $row['config_json'] ) ) {
			$decoded = json_decode( (string) $row['config_json'], true );
			$config  = is_array( $decoded ) ? $decoded : array();
		}

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['slug'] ?? '' ),
			(string) ( $row['label'] ?? '' ),
			(string) ( $row['singular_label'] ?? '' ),
			$config,
		);
	}
}
