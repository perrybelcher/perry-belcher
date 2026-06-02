<?php
/**
 * Unit tests for SearchRequest (args -> SearchQuery mapping).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Data\SearchQuery;
use Lodestar\DirectoryType\FieldDefinition;
use Lodestar\Frontend\SearchRequest;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! class_exists( SearchRequest::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src';
	require_once $lodestar_src . '/Data/Facet.php';
	require_once $lodestar_src . '/Data/GeoFilter.php';
	require_once $lodestar_src . '/Data/SearchQuery.php';
	require_once $lodestar_src . '/Data/SearchResult.php';
	require_once $lodestar_src . '/PostType/ListingPostType.php';
	require_once $lodestar_src . '/DirectoryType/FieldDefinition.php';
	require_once $lodestar_src . '/Frontend/SearchRequest.php';
}

/**
 * @covers \Lodestar\Frontend\SearchRequest
 */
final class SearchRequestTest extends TestCase {

	/**
	 * @return FieldDefinition[]
	 */
	private function defs(): array {
		return array(
			new FieldDefinition( 1, 1, 'cuisine', 'Cuisine', FieldDefinition::INPUT_SELECT, true ),
			new FieldDefinition( 2, 1, 'price', 'Price', FieldDefinition::INPUT_NUMBER, true ),
			new FieldDefinition( 3, 1, 'note', 'Note', FieldDefinition::INPUT_TEXT, false ),
		);
	}

	public function test_text_and_numeric_facets(): void {
		$query = SearchRequest::build(
			array( 'cuisine' => 'italian', 'price_min' => '10', 'price_max' => '50' ),
			$this->defs(),
			7
		);

		$this->assertSame( 7, $query->directoryTypeId );
		$this->assertCount( 2, $query->facets );
	}

	public function test_non_facetable_field_cannot_be_filtered(): void {
		// A forged value on a non-searchable field must be ignored.
		$query = SearchRequest::build( array( 'note' => 'anything' ), $this->defs(), 1 );
		$this->assertCount( 0, $query->facets );
	}

	public function test_geo_and_distance_sort(): void {
		$query = SearchRequest::build(
			array( 'lat' => '40.5', 'lng' => '-74.0', 'radius_km' => '15', 'sort' => 'distance' ),
			$this->defs(),
			1
		);

		$this->assertNotNull( $query->geo );
		$this->assertEqualsWithDelta( 15.0, $query->geo->radiusKm, 0.001 );
		$this->assertSame( SearchQuery::ORDER_DISTANCE, $query->orderBy );
	}

	public function test_distance_sort_without_geo_falls_back(): void {
		$query = SearchRequest::build( array( 'sort' => 'distance' ), $this->defs(), 1 );
		$this->assertNull( $query->geo );
		$this->assertSame( SearchQuery::ORDER_RELEVANCE, $query->orderBy );
	}

	public function test_default_radius_applied_when_missing(): void {
		$query = SearchRequest::build(
			array( 'lat' => '40.5', 'lng' => '-74.0' ),
			$this->defs(),
			1,
			30.0
		);
		$this->assertEqualsWithDelta( 30.0, $query->geo->radiusKm, 0.001 );
	}

	public function test_price_sort_maps_to_columns(): void {
		$low = SearchRequest::build( array( 'sort' => 'price_low' ), $this->defs(), 1 );
		$this->assertSame( SearchQuery::ORDER_PRICE, $low->orderBy );
		$this->assertSame( 'ASC', $low->order );

		$high = SearchRequest::build( array( 'sort' => 'price_high' ), $this->defs(), 1 );
		$this->assertSame( 'DESC', $high->order );
	}
}
