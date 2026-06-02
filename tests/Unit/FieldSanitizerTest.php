<?php
/**
 * Unit tests for FieldSanitizer (the public-submission front line).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\DirectoryType\FieldDefinition;
use Lodestar\Frontend\FieldSanitizer;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs.php';

if ( ! class_exists( FieldSanitizer::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src';
	require_once $lodestar_src . '/Data/Facet.php';
	require_once $lodestar_src . '/DirectoryType/FieldDefinition.php';
	require_once $lodestar_src . '/Frontend/FieldSanitizer.php';
}

/**
 * @covers \Lodestar\Frontend\FieldSanitizer
 */
final class FieldSanitizerTest extends TestCase {

	private function field( string $type, array $options = array() ): FieldDefinition {
		return new FieldDefinition( 1, 1, 'k', 'Label', $type, true, false, $options );
	}

	public function test_text_strips_script_tags(): void {
		$clean = FieldSanitizer::sanitize( $this->field( FieldDefinition::INPUT_TEXT ), '<script>alert(1)</script>Joe' );
		$this->assertStringNotContainsString( '<script', $clean );
		$this->assertStringNotContainsString( '</script>', $clean );
		$this->assertStringContainsString( 'Joe', $clean );
	}

	public function test_richtext_removes_script_but_keeps_safe_markup(): void {
		$clean = FieldSanitizer::sanitize(
			$this->field( FieldDefinition::INPUT_RICHTEXT ),
			'<p>Hello <strong>world</strong></p><script>steal()</script>'
		);
		$this->assertStringContainsString( '<strong>world</strong>', $clean );
		$this->assertStringNotContainsString( '<script', $clean );
		$this->assertStringNotContainsString( 'steal()', $clean );
	}

	public function test_richtext_strips_event_handlers(): void {
		$clean = FieldSanitizer::sanitize(
			$this->field( FieldDefinition::INPUT_RICHTEXT ),
			'<a href="#" onclick="evil()">x</a>'
		);
		$this->assertStringNotContainsString( 'onclick', $clean );
	}

	public function test_url_neutralizes_javascript_scheme(): void {
		$this->assertSame( '', FieldSanitizer::sanitize( $this->field( FieldDefinition::INPUT_URL ), 'javascript:alert(1)' ) );
		$this->assertSame( 'https://example.com', FieldSanitizer::sanitize( $this->field( FieldDefinition::INPUT_URL ), 'https://example.com' ) );
	}

	public function test_number_coerces_and_nulls_empty(): void {
		$this->assertSame( 19.5, FieldSanitizer::sanitize( $this->field( FieldDefinition::INPUT_NUMBER ), '19.5' ) );
		$this->assertNull( FieldSanitizer::sanitize( $this->field( FieldDefinition::INPUT_NUMBER ), '' ) );
		// A SQL-ish payload in a number field becomes a harmless float, never a
		// string that could reach a query unparameterised.
		$result = FieldSanitizer::sanitize( $this->field( FieldDefinition::INPUT_NUMBER ), "'; DROP TABLE wp_posts; --" );
		$this->assertIsFloat( $result );
		$this->assertSame( 0.0, $result );
	}

	public function test_select_whitelists_against_options(): void {
		$field = $this->field( FieldDefinition::INPUT_SELECT, array( 'italian', 'thai' ) );
		$this->assertSame( 'italian', FieldSanitizer::sanitize( $field, 'italian' ) );
		// A forged option not in the list is rejected.
		$this->assertSame( '', FieldSanitizer::sanitize( $field, 'mexican' ) );
	}

	public function test_multiselect_whitelists_and_dedupes(): void {
		$field = $this->field( FieldDefinition::INPUT_MULTISELECT, array( 'wifi', 'parking', 'pool' ) );
		$clean = FieldSanitizer::sanitize( $field, array( 'wifi', 'wifi', 'forged', 'pool' ) );
		$this->assertSame( array( 'wifi', 'pool' ), $clean );
	}

	public function test_geo_rejects_out_of_range(): void {
		$field = $this->field( FieldDefinition::INPUT_GEO );
		$this->assertSame( array( 'lat' => 40.5, 'lng' => -74.0 ), FieldSanitizer::sanitize( $field, array( 'lat' => '40.5', 'lng' => '-74.0' ) ) );
		$this->assertNull( FieldSanitizer::sanitize( $field, array( 'lat' => '200', 'lng' => '0' ) ) );
	}

	public function test_checkbox_is_binary(): void {
		$this->assertSame( '1', FieldSanitizer::sanitize( $this->field( FieldDefinition::INPUT_CHECKBOX ), 'on' ) );
		$this->assertSame( '0', FieldSanitizer::sanitize( $this->field( FieldDefinition::INPUT_CHECKBOX ), '' ) );
	}
}
