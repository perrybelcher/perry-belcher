<?php
/**
 * Unit tests for FieldDefinition (pure, no WordPress required).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Data\Facet;
use Lodestar\DirectoryType\FieldDefinition;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs.php';

if ( ! class_exists( FieldDefinition::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src';
	require_once $lodestar_src . '/Data/Facet.php';
	require_once $lodestar_src . '/DirectoryType/FieldDefinition.php';
}

/**
 * @covers \Lodestar\DirectoryType\FieldDefinition
 */
final class FieldDefinitionTest extends TestCase {

	private function field( string $input_type, bool $facetable = true ): FieldDefinition {
		return new FieldDefinition( 1, 1, 'k', 'Label', $input_type, $facetable );
	}

	public function test_storage_type_mapping(): void {
		$this->assertSame( Facet::TYPE_NUM, $this->field( FieldDefinition::INPUT_NUMBER )->storageType() );
		$this->assertSame( Facet::TYPE_DATE, $this->field( FieldDefinition::INPUT_DATE )->storageType() );
		$this->assertSame( Facet::TYPE_TEXT, $this->field( FieldDefinition::INPUT_SELECT )->storageType() );
		$this->assertSame( Facet::TYPE_TEXT, $this->field( FieldDefinition::INPUT_TEXT )->storageType() );
	}

	public function test_indexable_requires_facetable(): void {
		$this->assertTrue( $this->field( FieldDefinition::INPUT_TEXT, true )->isIndexable() );
		$this->assertFalse( $this->field( FieldDefinition::INPUT_TEXT, false )->isIndexable() );
	}

	public function test_richtext_file_geo_never_indexable(): void {
		$this->assertFalse( $this->field( FieldDefinition::INPUT_RICHTEXT, true )->isIndexable() );
		$this->assertFalse( $this->field( FieldDefinition::INPUT_FILE, true )->isIndexable() );
		$this->assertFalse( $this->field( FieldDefinition::INPUT_GEO, true )->isIndexable() );
	}

	public function test_multiselect_is_multiple(): void {
		$this->assertTrue( $this->field( FieldDefinition::INPUT_MULTISELECT )->isMultiple() );
		$this->assertFalse( $this->field( FieldDefinition::INPUT_SELECT )->isMultiple() );
	}

	public function test_from_row_decodes_options(): void {
		$field = FieldDefinition::fromRow(
			array(
				'id'                => '5',
				'directory_type_id' => '2',
				'field_key'         => 'cuisine',
				'label'             => 'Cuisine',
				'input_type'        => 'select',
				'is_facetable'      => '1',
				'is_required'       => '0',
				'options_json'      => (string) json_encode( array( 'italian', 'thai' ) ),
				'sort_order'        => '3',
			)
		);

		$this->assertSame( 5, $field->id );
		$this->assertSame( 'cuisine', $field->fieldKey );
		$this->assertTrue( $field->isFacetable );
		$this->assertSame( array( 'italian', 'thai' ), $field->options );
		$this->assertSame( 3, $field->sortOrder );
	}
}
