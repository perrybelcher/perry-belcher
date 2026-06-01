<?php
/**
 * Unit tests for the QueryBuilder (pure, no WordPress required).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Data\Facet;
use Lodestar\Data\GeoFilter;
use Lodestar\Data\QueryBuilder;
use Lodestar\Data\SearchQuery;
use PHPUnit\Framework\TestCase;

// Allow standalone execution without the WP test suite.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! class_exists( QueryBuilder::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src';
	require_once $lodestar_src . '/PostType/ListingPostType.php';
	require_once $lodestar_src . '/Data/Facet.php';
	require_once $lodestar_src . '/Data/GeoFilter.php';
	require_once $lodestar_src . '/Data/SearchQuery.php';
	require_once $lodestar_src . '/Data/QueryBuilder.php';
}

/**
 * @covers \Lodestar\Data\QueryBuilder
 */
final class QueryBuilderTest extends TestCase {

	private QueryBuilder $builder;

	protected function setUp(): void {
		$this->builder = new QueryBuilder( 'wp_' );
	}

	/**
	 * Count $wpdb placeholders in a SQL string.
	 */
	private function placeholders( string $sql ): int {
		preg_match_all( '/%[dsf]/', $sql, $m );
		return count( $m[0] );
	}

	/**
	 * The single most important invariant: every placeholder has exactly one
	 * arg, or $wpdb->prepare() will silently corrupt the query.
	 *
	 * @dataProvider queryProvider
	 */
	public function test_placeholder_and_arg_counts_match( SearchQuery $query ): void {
		[ $sql, $args ] = $this->builder->build( $query );
		$this->assertSame( $this->placeholders( $sql ), count( $args ), "build(): {$sql}" );

		[ $csql, $cargs ] = $this->builder->buildCount( $query );
		$this->assertSame( $this->placeholders( $csql ), count( $cargs ), "buildCount(): {$csql}" );
	}

	/**
	 * @return array<string,array{0:SearchQuery}>
	 */
	public static function queryProvider(): array {
		return array(
			'minimal'          => array( new SearchQuery() ),
			'no status'        => array( new SearchQuery( status: null ) ),
			'single facet'     => array( new SearchQuery( facets: array( Facet::text( 'wifi', array( 'yes' ) ) ) ) ),
			'multi-value facet' => array( new SearchQuery( facets: array( Facet::text( 'cuisine', array( 'italian', 'thai', 'indian' ) ) ) ) ),
			'numeric facet'    => array( new SearchQuery( facets: array( Facet::range( 'price_tier', 2.0, 4.0 ) ) ) ),
			'geo'              => array( new SearchQuery( geo: new GeoFilter( 40.5, -74.0, 10.0 ) ) ),
			'everything'       => array(
				new SearchQuery(
					directoryTypeId: 1,
					facets: array(
						Facet::text( 'cuisine', array( 'italian', 'thai' ) ),
						Facet::text( 'wifi', array( 'yes' ) ),
						Facet::range( 'price_tier', 2.0, 4.0 ),
					),
					geo: new GeoFilter( 40.5, -74.0, 10.0 ),
					priceMin: 10.0,
					priceMax: 500.0,
					ratingMin: 3.5,
					featuredOnly: true,
				),
			),
		);
	}

	public function test_facets_become_exists_subqueries(): void {
		$query = new SearchQuery(
			facets: array(
				Facet::text( 'cuisine', array( 'italian' ) ),
				Facet::text( 'wifi', array( 'yes' ) ),
				Facet::range( 'price_tier', 2.0, 4.0 ),
			)
		);

		[ $sql ] = $this->builder->build( $query );

		$this->assertSame( 3, substr_count( $sql, 'EXISTS' ) );
		$this->assertStringContainsString( 'lodestar_field_index', $sql );
		$this->assertStringNotContainsString( 'postmeta', $sql );
	}

	public function test_multi_value_facet_uses_in_clause(): void {
		$query = new SearchQuery( facets: array( Facet::text( 'cuisine', array( 'italian', 'thai' ) ) ) );
		[ $sql, $args ] = $this->builder->build( $query );

		$this->assertStringContainsString( 'value_text IN ( %s, %s )', $sql );
		$this->assertContains( 'italian', $args );
		$this->assertContains( 'thai', $args );
	}

	public function test_geo_adds_bounding_box_and_distance(): void {
		$query = new SearchQuery( geo: new GeoFilter( 40.5, -74.0, 10.0 ) );
		[ $sql ] = $this->builder->build( $query );

		$this->assertStringContainsString( 'd.lat BETWEEN %f AND %f', $sql );
		$this->assertStringContainsString( 'd.lng BETWEEN %f AND %f', $sql );
		$this->assertStringContainsString( 'AS distance', $sql );
		$this->assertStringContainsString( 'ORDER BY distance', $sql );
	}

	public function test_order_by_is_allowlisted(): void {
		$query   = new SearchQuery( orderBy: 'price); DROP TABLE wp_posts; --' );
		[ $sql ] = $this->builder->build( $query );

		$this->assertStringNotContainsString( 'DROP', $sql );
		$this->assertStringContainsString( 'ORDER BY', $sql );
	}

	public function test_status_filter_joins_posts_only_when_set(): void {
		[ $with ] = $this->builder->build( new SearchQuery( status: 'publish' ) );
		$this->assertStringContainsString( 'wp_posts p ON', $with );

		[ $without ] = $this->builder->build( new SearchQuery( status: null ) );
		$this->assertStringNotContainsString( 'wp_posts', $without );
	}

	public function test_pagination_offset(): void {
		$query          = new SearchQuery( page: 3, perPage: 25 );
		[ $sql, $args ] = $this->builder->build( $query );

		$this->assertStringEndsWith( 'LIMIT %d OFFSET %d', $sql );
		$this->assertSame( 25, $args[ count( $args ) - 2 ] );
		$this->assertSame( 50, $args[ count( $args ) - 1 ] );
	}
}
