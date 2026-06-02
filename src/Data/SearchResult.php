<?php
/**
 * Search result set.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable result of {@see ListingRepository::search()}: the page of rows plus
 * pagination metadata.
 */
final class SearchResult {

	/**
	 * @param array<int,object> $items   Result rows for the requested page.
	 * @param int               $total   Total matching rows across all pages.
	 * @param int               $page    1-based page number returned.
	 * @param int               $perPage Page size used.
	 */
	public function __construct(
		public readonly array $items,
		public readonly int $total,
		public readonly int $page,
		public readonly int $perPage,
	) {}

	/**
	 * Total number of pages.
	 */
	public function pages(): int {
		return $this->perPage > 0 ? (int) ceil( $this->total / $this->perPage ) : 0;
	}
}
