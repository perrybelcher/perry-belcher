<?php
/**
 * A single facet filter within a search query.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable description of one facet constraint against `lodestar_field_index`.
 *
 * A facet always filters by `field_key` first (matching the composite index),
 * then narrows by value(s) or a numeric/date range depending on {@see $type}.
 */
final class Facet {

	public const TYPE_TEXT = 'text';
	public const TYPE_NUM  = 'num';
	public const TYPE_DATE = 'date';

	/**
	 * @param string                $key     Field key (e.g. `cuisine`, `wifi`).
	 * @param string                $type    One of the TYPE_* constants.
	 * @param array<int,string|int> $values  Allowed values (OR'd, i.e. IN()).
	 * @param float|null            $numMin  Inclusive numeric/date lower bound.
	 * @param float|null            $numMax  Inclusive numeric/date upper bound.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $type = self::TYPE_TEXT,
		public readonly array $values = array(),
		public readonly ?float $numMin = null,
		public readonly ?float $numMax = null,
	) {}

	/**
	 * Convenience constructor for a text facet matching any of $values.
	 *
	 * @param array<int,string|int> $values Allowed values.
	 */
	public static function text( string $key, array $values ): self {
		return new self( $key, self::TYPE_TEXT, array_values( $values ) );
	}

	/**
	 * Convenience constructor for a numeric range facet.
	 */
	public static function range( string $key, ?float $min, ?float $max ): self {
		return new self( $key, self::TYPE_NUM, array(), $min, $max );
	}
}
