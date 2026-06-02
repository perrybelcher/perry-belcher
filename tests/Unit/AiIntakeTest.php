<?php
/**
 * Unit tests for ListingEnricher parsing + CitabilityScorer rubric.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Ai\CitabilityScorer;
use Lodestar\Ai\ListingEnricher;
use Lodestar\DirectoryType\FieldDefinition;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs.php';

if ( ! class_exists( CitabilityScorer::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src';
	require_once $lodestar_src . '/Data/Facet.php';
	require_once $lodestar_src . '/DirectoryType/FieldDefinition.php';
	require_once $lodestar_src . '/Ai/ProviderResponse.php';
	require_once $lodestar_src . '/Ai/Adapters/ProviderInterface.php';
	require_once $lodestar_src . '/Ai/AiClient.php';
	require_once $lodestar_src . '/Ai/ListingEnricher.php';
	require_once $lodestar_src . '/Ai/CitabilityScorer.php';
}

/**
 * @covers \Lodestar\Ai\ListingEnricher
 * @covers \Lodestar\Ai\CitabilityScorer
 */
final class AiIntakeTest extends TestCase {

	/**
	 * @return FieldDefinition[]
	 */
	private function defs(): array {
		return array(
			new FieldDefinition( 1, 1, 'cuisine', 'Cuisine', FieldDefinition::INPUT_SELECT, true ),
			new FieldDefinition( 2, 1, 'price', 'Price', FieldDefinition::INPUT_NUMBER, true ),
		);
	}

	public function test_extract_json_tolerates_prose_and_fences(): void {
		$text = "Sure! Here is the data:\n```json\n{\"title\":\"Joe\"}\n```\nHope it helps.";
		$this->assertSame( '{"title":"Joe"}', ListingEnricher::extract_json( $text ) );
	}

	public function test_parse_proposal_whitelists_fields(): void {
		$text = wp_json_encode_compat_ai(
			array(
				'title'       => 'Joe Pizza',
				'description' => 'Great slices',
				'fields'      => array( 'cuisine' => 'italian', 'evil_injected' => 'x', 'price' => 12 ),
				'faq'         => array( array( 'q' => 'Open?', 'a' => 'Yes' ), array( 'bad' => 'no q/a' ) ),
			)
		);

		$proposal = ListingEnricher::parse_proposal( $text, $this->defs() );

		$this->assertSame( 'Joe Pizza', $proposal['title'] );
		$this->assertArrayHasKey( 'cuisine', $proposal['fields'] );
		$this->assertArrayHasKey( 'price', $proposal['fields'] );
		// The model cannot inject an unknown field.
		$this->assertArrayNotHasKey( 'evil_injected', $proposal['fields'] );
		$this->assertCount( 1, $proposal['faq'] );
	}

	public function test_parse_proposal_handles_garbage(): void {
		$proposal = ListingEnricher::parse_proposal( 'not json at all', $this->defs() );
		$this->assertSame( '', $proposal['title'] );
		$this->assertSame( array(), $proposal['fields'] );
	}

	public function test_user_prompt_lists_field_keys_and_options(): void {
		$defs   = array( new FieldDefinition( 1, 1, 'cuisine', 'Cuisine', FieldDefinition::INPUT_SELECT, true, false, array( 'italian', 'thai' ) ) );
		$prompt = ListingEnricher::user_prompt( 'A pizza place', $defs );
		$this->assertStringContainsString( 'cuisine', $prompt );
		$this->assertStringContainsString( 'italian, thai', $prompt );
		$this->assertStringContainsString( 'A pizza place', $prompt );
	}

	public function test_citability_high_for_complete_listing(): void {
		$listing = array(
			'title'      => 'Joe Pizza',
			'content'    => str_repeat( 'A genuinely detailed description of the pizzeria. ', 10 ),
			'data'       => array( 'lat' => '40.5', 'lng' => '-74.0', 'rating_count' => '20', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			'facets'     => array(
				array( 'field_key' => 'cuisine', 'value_text' => 'italian' ),
				array( 'field_key' => 'price', 'value_num' => 12 ),
			),
			'meta'       => array( 'faq' => array( array( 'q' => 'Open?', 'a' => 'Yes' ), array( 'q' => 'Parking?', 'a' => 'Yes' ) ) ),
			'categories' => array( 3 ),
			'locations'  => array( 9 ),
		);

		$result = CitabilityScorer::score( $listing, $this->defs() );
		$this->assertSame( 100, $result['score'] );
		$this->assertSame( array(), $result['gaps'] );
	}

	public function test_citability_low_with_actionable_gaps(): void {
		$listing = array(
			'title'   => '',
			'content' => 'short',
			'data'    => array( 'rating_count' => '0' ),
			'facets'  => array(),
			'meta'    => array(),
		);

		$result = CitabilityScorer::score( $listing, $this->defs() );

		$this->assertLessThan( 30, $result['score'] );
		$this->assertNotEmpty( $result['gaps'] );
		// Gaps are human-readable suggestions.
		$this->assertTrue( (bool) count( array_filter( $result['gaps'], static fn ( $g ) => str_contains( $g, 'title' ) ) ) );
	}

	public function test_citability_partial_field_coverage(): void {
		$listing = array(
			'title'   => 'Joe',
			'content' => str_repeat( 'word ', 80 ),
			'data'    => array( 'lat' => '1', 'lng' => '2', 'rating_count' => '0', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			'facets'  => array( array( 'field_key' => 'cuisine', 'value_text' => 'italian' ) ), // 1 of 2 facetable.
			'meta'    => array(),
		);

		$result = CitabilityScorer::score( $listing, $this->defs() );
		// 1/2 facetable filled -> 10 of 20 for fields.
		$this->assertSame( 10, $result['breakdown']['fields'] );
	}
}

if ( ! function_exists( 'Lodestar\\Tests\\Unit\\wp_json_encode_compat_ai' ) ) {
	function wp_json_encode_compat_ai( $data ): string {
		return (string) json_encode( $data );
	}
}
