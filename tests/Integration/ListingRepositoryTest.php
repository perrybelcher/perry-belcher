<?php
/**
 * Integration tests for ListingRepository against a real WordPress + MySQL.
 *
 * Requires the WP test suite (see tests/bootstrap.php).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Integration;

use Lodestar\Data\Facet;
use Lodestar\Data\GeoFilter;
use Lodestar\Data\ListingRepository;
use Lodestar\Data\SearchQuery;
use WP_UnitTestCase;

/**
 * @covers \Lodestar\Data\ListingRepository
 * @covers \Lodestar\Data\FieldIndexer
 */
final class ListingRepositoryTest extends WP_UnitTestCase {

	private ListingRepository $repo;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->repo = new ListingRepository( $wpdb );
	}

	public function test_create_read_update_delete(): void {
		global $wpdb;

		$id = $this->repo->create(
			array(
				'title'             => 'Joe Pizza',
				'content'           => 'Best slice in town',
				'status'            => 'publish',
				'author'            => 1,
				'directory_type_id' => 1,
				'lat'               => 40.5,
				'lng'               => -74.0,
				'price'             => 9.99,
				'meta'              => array( 'hours' => '9-5' ),
				'facets'            => array(
					array( 'field_key' => 'cuisine', 'value_text' => 'italian' ),
					array( 'field_key' => 'wifi', 'value_text' => 'yes' ),
				),
			)
		);

		$this->assertGreaterThan( 0, $id );

		// READ — full hydration from the custom tables, no meta_query.
		$found = $this->repo->find( $id );
		$this->assertSame( 'Joe Pizza', $found['title'] );
		$this->assertSame( '9-5', $found['meta']['hours'] );
		$this->assertCount( 2, $found['facets'] );

		// Geohash was precomputed on save.
		$geohash = $wpdb->get_var(
			$wpdb->prepare( "SELECT geohash FROM {$wpdb->prefix}lodestar_listing_data WHERE listing_id = %d", $id )
		);
		$this->assertNotEmpty( $geohash );

		// Facet rows landed in the index.
		$facet_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lodestar_field_index WHERE listing_id = %d AND field_key = %s", $id, 'cuisine' )
		);
		$this->assertSame( 1, $facet_count );

		// UPDATE — partial.
		$this->assertTrue( $this->repo->update( $id, array( 'title' => 'Joe Pizza NYC', 'price' => 12.50 ) ) );
		$this->assertSame( 'Joe Pizza NYC', $this->repo->find( $id )['title'] );

		// DELETE — removes the post and all custom rows.
		$this->assertTrue( $this->repo->delete( $id ) );
		$this->assertNull( $this->repo->find( $id ) );

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lodestar_field_index WHERE listing_id = %d", $id )
		);
		$this->assertSame( 0, $remaining );
	}

	public function test_search_filters_on_custom_tables(): void {
		// Near + italian (match).
		$near_italian = $this->repo->create(
			array(
				'title'             => 'Near Italian',
				'status'            => 'publish',
				'directory_type_id' => 1,
				'lat'               => 40.5,
				'lng'               => -74.0,
				'facets'            => array( array( 'field_key' => 'cuisine', 'value_text' => 'italian' ) ),
			)
		);

		// Near + thai (wrong facet).
		$near_thai = $this->repo->create(
			array(
				'title'             => 'Near Thai',
				'status'            => 'publish',
				'directory_type_id' => 1,
				'lat'               => 40.5,
				'lng'               => -74.0,
				'facets'            => array( array( 'field_key' => 'cuisine', 'value_text' => 'thai' ) ),
			)
		);

		// Far + italian (outside radius).
		$far_italian = $this->repo->create(
			array(
				'title'             => 'Far Italian',
				'status'            => 'publish',
				'directory_type_id' => 1,
				'lat'               => 45.0,
				'lng'               => -100.0,
				'facets'            => array( array( 'field_key' => 'cuisine', 'value_text' => 'italian' ) ),
			)
		);

		$result = $this->repo->search(
			new SearchQuery(
				directoryTypeId: 1,
				facets: array( Facet::text( 'cuisine', array( 'italian' ) ) ),
				geo: new GeoFilter( 40.5, -74.0, 10.0 ),
				status: 'publish',
			)
		);

		$ids = array_map( static fn ( $row ) => (int) $row->listing_id, $result->items );

		$this->assertContains( $near_italian, $ids );
		$this->assertNotContains( $near_thai, $ids );
		$this->assertNotContains( $far_italian, $ids );
		$this->assertSame( 1, $result->total );
	}

	public function test_draft_excluded_by_status_filter(): void {
		$draft = $this->repo->create(
			array(
				'title'             => 'Hidden',
				'status'            => 'draft',
				'directory_type_id' => 1,
				'lat'               => 40.5,
				'lng'               => -74.0,
				'facets'            => array( array( 'field_key' => 'cuisine', 'value_text' => 'italian' ) ),
			)
		);

		$result = $this->repo->search(
			new SearchQuery(
				directoryTypeId: 1,
				facets: array( Facet::text( 'cuisine', array( 'italian' ) ) ),
				geo: new GeoFilter( 40.5, -74.0, 10.0 ),
				status: 'publish',
			)
		);

		$ids = array_map( static fn ( $row ) => (int) $row->listing_id, $result->items );
		$this->assertNotContains( $draft, $ids );
	}
}
