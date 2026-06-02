<?php
/**
 * Unit tests for SchemaGenerator (pure JSON-LD building).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Aeo\SchemaGenerator;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs.php';

if ( ! class_exists( SchemaGenerator::class ) ) {
	require_once dirname( __DIR__, 2 ) . '/src/Aeo/FaqBlock.php';
	require_once dirname( __DIR__, 2 ) . '/src/Aeo/SchemaGenerator.php';
}

/**
 * @covers \Lodestar\Aeo\SchemaGenerator
 */
final class SchemaGeneratorTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function listing(): array {
		return array(
			'title'   => 'Joe Pizza',
			'content' => '<p>The <strong>best</strong> slice.</p>',
			'data'    => array(
				'lat'          => '40.5',
				'lng'          => '-74.0',
				'price'        => '12.50',
				'rating_avg'   => '4.6',
				'rating_count' => '37',
			),
			'facets'  => array(
				array( 'field_key' => 'cuisine', 'value_text' => 'Italian', 'value_num' => null, 'value_date' => null ),
				array( 'field_key' => 'ld_category', 'value_text' => null, 'value_num' => 5, 'value_date' => null ),
			),
			'meta'    => array( 'about' => 'Family owned' ),
		);
	}

	public function test_builds_core_fields(): void {
		$schema = SchemaGenerator::build( $this->listing(), 'Restaurant', array(), array( 'url' => 'https://x.test/joe' ) );

		$this->assertSame( 'https://schema.org', $schema['@context'] );
		$this->assertSame( 'Restaurant', $schema['@type'] );
		$this->assertSame( 'Joe Pizza', $schema['name'] );
		$this->assertSame( 'https://x.test/joe', $schema['url'] );
		$this->assertStringNotContainsString( '<strong>', $schema['description'] );
		$this->assertStringContainsString( 'best slice', $schema['description'] );
	}

	public function test_geo_rating_and_offer(): void {
		$schema = SchemaGenerator::build( $this->listing(), 'Restaurant' );

		$this->assertSame( 'GeoCoordinates', $schema['geo']['@type'] );
		$this->assertSame( 40.5, $schema['geo']['latitude'] );
		$this->assertSame( -74.0, $schema['geo']['longitude'] );

		$this->assertSame( 'AggregateRating', $schema['aggregateRating']['@type'] );
		$this->assertSame( 4.6, $schema['aggregateRating']['ratingValue'] );
		$this->assertSame( 37, $schema['aggregateRating']['reviewCount'] );

		$this->assertSame( 12.5, $schema['offers']['price'] );
		$this->assertSame( 'USD', $schema['offers']['priceCurrency'] );
	}

	public function test_configurable_field_mapping(): void {
		// Map is per-type, not hardcoded: cuisine -> servesCuisine.
		$schema = SchemaGenerator::build( $this->listing(), 'Restaurant', array( 'cuisine' => 'servesCuisine' ) );
		$this->assertSame( 'Italian', $schema['servesCuisine'] );
	}

	public function test_taxonomy_denorm_rows_are_excluded(): void {
		$schema = SchemaGenerator::build( $this->listing(), 'Restaurant', array( 'ld_category' => 'category' ) );
		// ld_* keys are filtered out of the value map, so no stray property.
		$this->assertArrayNotHasKey( 'category', $schema );
	}

	public function test_no_rating_when_count_zero(): void {
		$listing                         = $this->listing();
		$listing['data']['rating_count'] = '0';
		$schema                          = SchemaGenerator::build( $listing, 'Restaurant' );
		$this->assertArrayNotHasKey( 'aggregateRating', $schema );
	}

	public function test_render_is_valid_json_and_script_safe(): void {
		$schema = SchemaGenerator::build(
			array( 'title' => 'Break</script><script>alert(1)</script>', 'data' => array() ),
			'Thing'
		);
		$html = SchemaGenerator::render( $schema );

		$this->assertStringStartsWith( '<script type="application/ld+json">', $html );
		// The closing tag in the data must be hex-escaped, not literal.
		$this->assertStringNotContainsString( '</script><script>alert(1)', $html );

		$json = substr( $html, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' ) );
		$this->assertNotNull( json_decode( $json ) );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
	}
}
