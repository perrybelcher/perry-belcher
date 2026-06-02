<?php
/**
 * Unit tests for ListingFormData (validation + repository mapping).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Data\Facet;
use Lodestar\DirectoryType\FieldDefinition;
use Lodestar\Frontend\ListingFormData;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs.php';

if ( ! class_exists( ListingFormData::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src';
	require_once $lodestar_src . '/Data/Facet.php';
	require_once $lodestar_src . '/DirectoryType/FieldDefinition.php';
	require_once $lodestar_src . '/Frontend/FieldSanitizer.php';
	require_once $lodestar_src . '/Frontend/ListingFormData.php';
}

/**
 * @covers \Lodestar\Frontend\ListingFormData
 */
final class ListingFormDataTest extends TestCase {

	/**
	 * @return FieldDefinition[]
	 */
	private function defs(): array {
		return array(
			new FieldDefinition( 1, 1, 'cuisine', 'Cuisine', FieldDefinition::INPUT_SELECT, true, true ),
			new FieldDefinition( 2, 1, 'price', 'Price', FieldDefinition::INPUT_NUMBER, true, false ),
			new FieldDefinition( 3, 1, 'about', 'About', FieldDefinition::INPUT_RICHTEXT, false, false ),
			new FieldDefinition( 4, 1, 'location', 'Location', FieldDefinition::INPUT_GEO, false, false ),
		);
	}

	public function test_requires_title(): void {
		$result = ListingFormData::build( '', 'desc', array(), $this->defs() );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_requires_required_custom_field(): void {
		$result = ListingFormData::build( 'Joe', '', array( 'cuisine' => '' ), $this->defs() );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_splits_facetable_meta_and_geo(): void {
		$result = ListingFormData::build(
			'Joe Pizza',
			'Great pizza',
			array(
				'cuisine'  => 'italian',
				'price'    => 12.5,
				'about'    => '<strong>About</strong>',
				'location' => array( 'lat' => 40.5, 'lng' => -74.0 ),
			),
			$this->defs()
		);

		$this->assertSame( array(), $result['errors'] );
		$data = $result['data'];

		// Facetable fields → indexed via fields/field_defs.
		$this->assertArrayHasKey( 'fields', $data );
		$this->assertArrayHasKey( 'cuisine', $data['fields'] );
		$this->assertArrayHasKey( 'price', $data['fields'] );
		$this->assertSame( Facet::TYPE_NUM, $data['field_defs']['price']['type'] );

		// Non-facetable richtext → meta, NOT indexed.
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'about', $data['meta'] );
		$this->assertArrayNotHasKey( 'about', $data['fields'] );

		// Geo → native lat/lng columns.
		$this->assertSame( 40.5, $data['lat'] );
		$this->assertSame( -74.0, $data['lng'] );
	}

	public function test_taxonomy_terms_passed_through(): void {
		$result = ListingFormData::build(
			'Joe',
			'',
			array( 'cuisine' => 'italian' ),
			$this->defs(),
			array( 'category' => array( 3, 5 ), 'location' => array( 9 ) )
		);

		$this->assertSame( array( 3, 5 ), $result['data']['category'] );
		$this->assertSame( array( 9 ), $result['data']['location'] );
	}

	public function test_status_and_author_are_not_taken_from_the_form(): void {
		// The mapper must never set status/author/directory_type_id — those come
		// from request context in the controller, so a submitter can't self-publish.
		$result = ListingFormData::build( 'Joe', '', array( 'cuisine' => 'italian' ), $this->defs() );
		$this->assertArrayNotHasKey( 'status', $result['data'] );
		$this->assertArrayNotHasKey( 'author', $result['data'] );
		$this->assertArrayNotHasKey( 'directory_type_id', $result['data'] );
	}
}
