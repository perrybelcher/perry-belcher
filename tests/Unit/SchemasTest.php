<?php
/**
 * Unit tests for the REST Schemas (formatting + OpenAPI).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Api\Schemas;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! defined( 'LODESTAR_VERSION' ) ) {
	define( 'LODESTAR_VERSION', '0.1.0' );
}
if ( ! class_exists( Schemas::class ) ) {
	require_once dirname( __DIR__, 2 ) . '/src/Api/Schemas.php';
}

/**
 * @covers \Lodestar\Api\Schemas
 */
final class SchemasTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function listing(): array {
		return array(
			'id'         => 7,
			'title'      => 'Joe Pizza',
			'slug'       => 'joe-pizza',
			'status'     => 'publish',
			'content'    => 'Great slices',
			'author'     => 4,
			'data'       => array( 'lat' => '40.5', 'lng' => '-74.0', 'rating_avg' => '4.5', 'rating_count' => '8', 'ai_citability_score' => '82', 'is_featured' => 1, 'plan_id' => 2, 'expires_at' => null ),
			'facets'     => array(
				array( 'field_key' => 'cuisine', 'value_text' => 'italian' ),
				array( 'field_key' => 'amenity', 'value_text' => 'wifi' ),
				array( 'field_key' => 'amenity', 'value_text' => 'parking' ),
				array( 'field_key' => 'ld_category', 'value_num' => 5 ),
			),
			'meta'       => array( 'about' => 'secret-ish' ),
			'categories' => array( 5 ),
			'locations'  => array( 9 ),
		);
	}

	public function test_public_format_hides_private_fields(): void {
		$out = Schemas::format_listing( $this->listing(), 'https://x.test/joe', false );

		$this->assertSame( 7, $out['id'] );
		$this->assertSame( 'https://x.test/joe', $out['url'] );
		$this->assertSame( array( 'lat' => 40.5, 'lng' => -74.0 ), $out['geo'] );
		$this->assertSame( 4.5, $out['rating']['average'] );
		$this->assertSame( 8, $out['rating']['count'] );
		$this->assertSame( 82, $out['citability'] );
		$this->assertTrue( $out['featured'] );

		// Private keys absent in the public view.
		$this->assertArrayNotHasKey( 'author', $out );
		$this->assertArrayNotHasKey( 'meta', $out );
	}

	public function test_owner_format_includes_private(): void {
		$out = Schemas::format_listing( $this->listing(), 'https://x.test/joe', true );
		$this->assertSame( 4, $out['author'] );
		$this->assertSame( 2, $out['plan_id'] );
		$this->assertArrayHasKey( 'meta', $out );
	}

	public function test_flatten_excludes_taxonomy_rows_and_merges_multivalue(): void {
		$fields = Schemas::flatten_fields( $this->listing() );

		$this->assertSame( 'italian', $fields['cuisine'] );
		$this->assertSame( array( 'wifi', 'parking' ), $fields['amenity'] );
		$this->assertArrayNotHasKey( 'ld_category', $fields );
	}

	public function test_openapi_describes_the_surface(): void {
		$spec = Schemas::openapi( 'https://x.test/wp-json/lodestar/v1' );

		$this->assertSame( '3.0.3', $spec['openapi'] );
		$this->assertArrayHasKey( '/listings', $spec['paths'] );
		$this->assertArrayHasKey( 'get', $spec['paths']['/listings'] );
		$this->assertArrayHasKey( 'post', $spec['paths']['/listings'] );
		$this->assertArrayHasKey( '/listings/{id}/claim', $spec['paths'] );
		$this->assertArrayHasKey( 'Listing', $spec['components']['schemas'] );

		// The document is JSON-serialisable.
		$this->assertNotFalse( json_encode( $spec ) );
	}
}
