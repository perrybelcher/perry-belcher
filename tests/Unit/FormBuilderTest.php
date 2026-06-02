<?php
/**
 * Unit tests for FormBuilder (pure, with WP escaping stubs).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\DirectoryType\FieldDefinition;
use Lodestar\DirectoryType\FormBuilder;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs.php';

if ( ! class_exists( FormBuilder::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src';
	require_once $lodestar_src . '/Data/Facet.php';
	require_once $lodestar_src . '/DirectoryType/FieldDefinition.php';
	require_once $lodestar_src . '/DirectoryType/FormBuilder.php';
}

/**
 * @covers \Lodestar\DirectoryType\FormBuilder
 */
final class FormBuilderTest extends TestCase {

	public function test_renders_a_control_per_field(): void {
		$builder = new FormBuilder(
			array(
				new FieldDefinition( 1, 1, 'name', 'Name', FieldDefinition::INPUT_TEXT, false, true ),
				new FieldDefinition( 2, 1, 'price', 'Price', FieldDefinition::INPUT_NUMBER ),
				new FieldDefinition( 3, 1, 'cuisine', 'Cuisine', FieldDefinition::INPUT_SELECT, true, false, array( 'italian', 'thai' ) ),
			)
		);

		$html = $builder->renderFields();

		$this->assertSame( 3, substr_count( $html, 'lodestar-field' ) - substr_count( $html, 'lodestar-field__' ) );
		$this->assertStringContainsString( 'name="lodestar_fields[name]"', $html );
		$this->assertStringContainsString( 'type="number"', $html );
		$this->assertStringContainsString( '<select', $html );
		$this->assertStringContainsString( '<option value="italian"', $html );
	}

	public function test_required_attribute(): void {
		$builder = new FormBuilder( array( new FieldDefinition( 1, 1, 'name', 'Name', FieldDefinition::INPUT_TEXT, false, true ) ) );
		$this->assertStringContainsString( ' required', $builder->renderFields() );
	}

	public function test_multiselect_uses_array_name_and_marks_selected(): void {
		$builder = new FormBuilder(
			array(
				new FieldDefinition( 1, 1, 'amenities', 'Amenities', FieldDefinition::INPUT_MULTISELECT, true, false, array( 'wifi', 'parking', 'pool' ) ),
			)
		);

		$html = $builder->renderFields( array( 'amenities' => array( 'wifi', 'pool' ) ) );

		$this->assertStringContainsString( 'name="lodestar_fields[amenities][]"', $html );
		$this->assertStringContainsString( ' multiple', $html );
		$this->assertStringContainsString( '<option value="wifi" selected>', $html );
		$this->assertStringContainsString( '<option value="pool" selected>', $html );
		$this->assertStringContainsString( '<option value="parking">', $html );
	}

	public function test_values_are_escaped(): void {
		$builder = new FormBuilder( array( new FieldDefinition( 1, 1, 'name', 'Name', FieldDefinition::INPUT_TEXT ) ) );
		$html    = $builder->renderFields( array( 'name' => '"><script>alert(1)</script>' ) );

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_geo_renders_lat_lng_pair(): void {
		$builder = new FormBuilder( array( new FieldDefinition( 1, 1, 'location', 'Location', FieldDefinition::INPUT_GEO ) ) );
		$html    = $builder->renderFields( array( 'location' => array( 'lat' => '40.5', 'lng' => '-74.0' ) ) );

		$this->assertStringContainsString( 'name="lodestar_fields[location][lat]"', $html );
		$this->assertStringContainsString( 'name="lodestar_fields[location][lng]"', $html );
		$this->assertStringContainsString( 'value="40.5"', $html );
	}
}
