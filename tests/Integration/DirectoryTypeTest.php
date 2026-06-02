<?php
/**
 * Integration tests for directory types and fields (WordPress + MySQL).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Integration;

use Lodestar\Data\Facet;
use Lodestar\Data\ListingRepository;
use Lodestar\DirectoryType\DirectoryTypeManager;
use Lodestar\DirectoryType\FieldDefinition;
use Lodestar\DirectoryType\FieldManager;
use WP_UnitTestCase;

/**
 * @covers \Lodestar\DirectoryType\DirectoryTypeManager
 * @covers \Lodestar\DirectoryType\FieldManager
 */
final class DirectoryTypeTest extends WP_UnitTestCase {

	private DirectoryTypeManager $types;
	private FieldManager $fields;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->types  = new DirectoryTypeManager( $wpdb );
		$this->fields = new FieldManager( $wpdb );
	}

	public function test_type_crud_and_unique_slug(): void {
		$id = $this->types->create(
			array( 'slug' => 'restaurants', 'label' => 'Restaurants', 'singular_label' => 'Restaurant' )
		);
		$this->assertGreaterThan( 0, $id );

		$found = $this->types->find( $id );
		$this->assertSame( 'restaurants', $found->slug );

		// Duplicate slug rejected.
		$this->assertSame( 0, $this->types->create( array( 'slug' => 'restaurants', 'label' => 'Dupe' ) ) );

		$this->assertTrue( $this->types->update( $id, array( 'label' => 'Eateries' ) ) );
		$this->assertSame( 'Eateries', $this->types->find( $id )->label );

		$this->assertTrue( $this->types->delete( $id ) );
		$this->assertNull( $this->types->find( $id ) );
	}

	public function test_fields_are_isolated_per_type(): void {
		$restaurants = $this->types->create( array( 'slug' => 'restaurants', 'label' => 'Restaurants' ) );
		$jobs        = $this->types->create( array( 'slug' => 'jobs', 'label' => 'Jobs' ) );

		$this->fields->create(
			array( 'directory_type_id' => $restaurants, 'field_key' => 'cuisine', 'label' => 'Cuisine', 'input_type' => 'select', 'is_facetable' => true )
		);
		$this->fields->create(
			array( 'directory_type_id' => $restaurants, 'field_key' => 'wifi', 'label' => 'WiFi', 'input_type' => 'checkbox', 'is_facetable' => true )
		);
		$this->fields->create(
			array( 'directory_type_id' => $jobs, 'field_key' => 'salary', 'label' => 'Salary', 'input_type' => 'number', 'is_facetable' => true )
		);

		$restaurant_keys = array_map( static fn ( $f ) => $f->fieldKey, $this->fields->forType( $restaurants ) );
		$job_keys        = array_map( static fn ( $f ) => $f->fieldKey, $this->fields->forType( $jobs ) );

		$this->assertEqualsCanonicalizing( array( 'cuisine', 'wifi' ), $restaurant_keys );
		$this->assertSame( array( 'salary' ), $job_keys );

		// The same key may exist on different types, but not twice on one type.
		$this->assertGreaterThan( 0, $this->fields->create( array( 'directory_type_id' => $jobs, 'field_key' => 'cuisine', 'label' => 'X' ) ) );
		$this->assertSame( 0, $this->fields->create( array( 'directory_type_id' => $restaurants, 'field_key' => 'cuisine', 'label' => 'Dupe' ) ) );
	}

	public function test_index_definitions_drive_the_indexer(): void {
		global $wpdb;

		$type = $this->types->create( array( 'slug' => 'restaurants', 'label' => 'Restaurants' ) );
		$this->fields->create( array( 'directory_type_id' => $type, 'field_key' => 'cuisine', 'label' => 'Cuisine', 'input_type' => 'select', 'is_facetable' => true ) );
		$this->fields->create( array( 'directory_type_id' => $type, 'field_key' => 'price', 'label' => 'Price', 'input_type' => 'number', 'is_facetable' => true ) );
		// Non-facetable + richtext: must NOT be indexed.
		$this->fields->create( array( 'directory_type_id' => $type, 'field_key' => 'phone', 'label' => 'Phone', 'input_type' => 'tel', 'is_facetable' => false ) );
		$this->fields->create( array( 'directory_type_id' => $type, 'field_key' => 'about', 'label' => 'About', 'input_type' => 'richtext', 'is_facetable' => true ) );

		$defs = $this->fields->indexDefinitions( $type );

		$this->assertArrayHasKey( 'cuisine', $defs );
		$this->assertArrayHasKey( 'price', $defs );
		$this->assertArrayNotHasKey( 'phone', $defs );
		$this->assertArrayNotHasKey( 'about', $defs );
		$this->assertSame( Facet::TYPE_NUM, $defs['price']['type'] );

		// Feed those defs through the repository's raw-fields path.
		$repo = new ListingRepository( $wpdb );
		$id   = $repo->create(
			array(
				'title'             => 'Indexed via defs',
				'status'            => 'publish',
				'directory_type_id' => $type,
				'fields'            => array(
					'cuisine' => 'italian',
					'price'   => 19.5,
					'phone'   => '555-1234',
					'about'   => 'Long description',
				),
				'field_defs'        => $defs,
			)
		);

		$indexed = $wpdb->get_col(
			$wpdb->prepare( "SELECT field_key FROM {$wpdb->prefix}lodestar_field_index WHERE listing_id = %d", $id )
		);

		$this->assertContains( 'cuisine', $indexed );
		$this->assertContains( 'price', $indexed );
		$this->assertNotContains( 'phone', $indexed );
		$this->assertNotContains( 'about', $indexed );
	}

	public function test_ensure_default_is_idempotent(): void {
		$first  = $this->types->ensureDefault();
		$second = $this->types->ensureDefault();

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( $first, $second );
	}
}
