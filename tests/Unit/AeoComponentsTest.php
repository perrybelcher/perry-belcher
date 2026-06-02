<?php
/**
 * Unit tests for FaqBlock, ProgrammaticPages, and SitemapProvider helpers.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Aeo\FaqBlock;
use Lodestar\Aeo\ProgrammaticPages;
use Lodestar\Aeo\SitemapProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs.php';

if ( ! class_exists( FaqBlock::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src';
	require_once $lodestar_src . '/PostType/ListingPostType.php';
	require_once $lodestar_src . '/PostType/Taxonomies.php';
	require_once $lodestar_src . '/Aeo/FaqBlock.php';
	require_once $lodestar_src . '/Aeo/SchemaGenerator.php';
	require_once $lodestar_src . '/Aeo/ProgrammaticPages.php';
	require_once $lodestar_src . '/Aeo/SitemapProvider.php';
}

/**
 * @covers \Lodestar\Aeo\FaqBlock
 * @covers \Lodestar\Aeo\ProgrammaticPages
 * @covers \Lodestar\Aeo\SitemapProvider
 */
final class AeoComponentsTest extends TestCase {

	public function test_faq_schema_shape(): void {
		$schema = FaqBlock::schema(
			array(
				array( 'q' => 'Do you deliver?', 'a' => 'Yes, within 5 miles.' ),
				array( 'question' => 'Hours?', 'answer' => '9 to 9.' ),
			)
		);

		$this->assertSame( 'FAQPage', $schema['@type'] );
		$this->assertCount( 2, $schema['mainEntity'] );
		$this->assertSame( 'Question', $schema['mainEntity'][0]['@type'] );
		$this->assertSame( 'Do you deliver?', $schema['mainEntity'][0]['name'] );
		$this->assertSame( 'Yes, within 5 miles.', $schema['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function test_faq_schema_null_when_empty(): void {
		$this->assertNull( FaqBlock::schema( array() ) );
		$this->assertNull( FaqBlock::schema( array( array( 'q' => '', 'a' => '' ) ) ) );
	}

	public function test_faq_render_escapes_questions(): void {
		$html = FaqBlock::render( array( array( 'q' => '<script>x</script>Open?', 'a' => 'Yes' ) ) );
		$this->assertStringContainsString( '<details', $html );
		$this->assertStringNotContainsString( '<script>x', $html );
	}

	public function test_breadcrumbs_positions(): void {
		$crumbs = ProgrammaticPages::build_breadcrumbs(
			array(
				array( 'name' => 'Home', 'url' => 'https://x.test/' ),
				array( 'name' => 'Restaurants', 'url' => 'https://x.test/c/restaurants' ),
				array( 'name' => 'Italian', 'url' => 'https://x.test/c/italian' ),
			)
		);

		$this->assertSame( 'BreadcrumbList', $crumbs['@type'] );
		$this->assertCount( 3, $crumbs['itemListElement'] );
		$this->assertSame( 1, $crumbs['itemListElement'][0]['position'] );
		$this->assertSame( 3, $crumbs['itemListElement'][2]['position'] );
		$this->assertSame( 'Italian', $crumbs['itemListElement'][2]['name'] );
	}

	public function test_intro_text_pluralizes(): void {
		$this->assertStringContainsString( '1 listing in Brooklyn', ProgrammaticPages::intro_text( 'Brooklyn', 1 ) );
		$this->assertStringContainsString( '12 listings in Brooklyn', ProgrammaticPages::intro_text( 'Brooklyn', 12 ) );
	}

	public function test_comparison_table_renders_and_escapes(): void {
		$html = ProgrammaticPages::render_comparison(
			array(
				array( 'name' => 'Joe', 'url' => 'https://x.test/joe', 'cells' => array( 'price' => '$$' ) ),
				array( 'name' => '<b>Evil</b>', 'url' => 'https://x.test/evil', 'cells' => array( 'price' => '$' ) ),
			),
			array( 'price' => 'Price' )
		);

		$this->assertStringContainsString( '<table', $html );
		$this->assertStringContainsString( 'Price', $html );
		$this->assertStringContainsString( 'Joe', $html );
		$this->assertStringNotContainsString( '<b>Evil</b>', $html );
	}

	public function test_comparison_empty_is_blank(): void {
		$this->assertSame( '', ProgrammaticPages::render_comparison( array() ) );
	}

	public function test_sitemap_chunk(): void {
		$items  = range( 1, 25 );
		$chunks = SitemapProvider::chunk( $items, 10 );

		$this->assertCount( 3, $chunks );
		$this->assertCount( 10, $chunks[0] );
		$this->assertCount( 5, $chunks[2] );
		// per_page is floored at 1.
		$this->assertCount( 25, SitemapProvider::chunk( $items, 0 ) );
	}
}
