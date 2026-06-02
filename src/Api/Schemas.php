<?php
/**
 * REST request/response schemas + OpenAPI description.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helpers for the REST layer: argument definitions (for WP's built-in
 * validation/sanitisation), the public listing response shape, and an OpenAPI
 * document. Keeping these pure makes them testable and keeps the controller thin.
 */
final class Schemas {

	/**
	 * Argument schema for the search/list endpoint.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function search_args(): array {
		return array(
			'type'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			'page'      => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'sanitize_callback' => 'absint' ),
			'per_page'  => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 50, 'sanitize_callback' => 'absint' ),
			'sort'      => array( 'type' => 'string', 'default' => 'relevance', 'sanitize_callback' => 'sanitize_key' ),
			'near'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'lat'       => array( 'type' => 'number' ),
			'lng'       => array( 'type' => 'number' ),
			'radius_km' => array( 'type' => 'number' ),
			'facets'    => array( 'type' => 'object', 'default' => array() ),
		);
	}

	/**
	 * Shape a hydrated listing for a public API response.
	 *
	 * Private/internal columns (author, expiry, plan) are only included for the
	 * owner/privileged view.
	 *
	 * @param array<string,mixed> $listing         Hydrated listing.
	 * @param string              $url             Permalink.
	 * @param bool                $include_private Whether to include owner-only data.
	 * @return array<string,mixed>
	 */
	public static function format_listing( array $listing, string $url = '', bool $include_private = false ): array {
		$data = (array) ( $listing['data'] ?? array() );

		$out = array(
			'id'          => (int) ( $listing['id'] ?? 0 ),
			'title'       => (string) ( $listing['title'] ?? '' ),
			'slug'        => (string) ( $listing['slug'] ?? '' ),
			'status'      => (string) ( $listing['status'] ?? '' ),
			'url'         => $url,
			'description' => (string) ( $listing['content'] ?? '' ),
			'fields'      => self::flatten_fields( $listing ),
			'categories'  => array_map( 'intval', (array) ( $listing['categories'] ?? array() ) ),
			'locations'   => array_map( 'intval', (array) ( $listing['locations'] ?? array() ) ),
			'rating'      => array(
				'average' => (float) ( $data['rating_avg'] ?? 0 ),
				'count'   => (int) ( $data['rating_count'] ?? 0 ),
			),
			'citability'  => isset( $data['ai_citability_score'] ) ? (int) $data['ai_citability_score'] : null,
			'featured'    => (bool) ( $data['is_featured'] ?? false ),
		);

		if ( isset( $data['lat'], $data['lng'] ) && '' !== (string) $data['lat'] && '' !== (string) $data['lng'] ) {
			$out['geo'] = array( 'lat' => (float) $data['lat'], 'lng' => (float) $data['lng'] );
		}

		if ( $include_private ) {
			$out['author']     = (int) ( $listing['author'] ?? 0 );
			$out['plan_id']    = isset( $data['plan_id'] ) ? (int) $data['plan_id'] : null;
			$out['expires_at'] = $data['expires_at'] ?? null;
			$out['meta']       = (array) ( $listing['meta'] ?? array() );
		}

		return $out;
	}

	/**
	 * Flatten facet rows into a field_key => value(s) map (excludes ld_* terms).
	 *
	 * @param array<string,mixed> $listing Hydrated listing.
	 * @return array<string,mixed>
	 */
	public static function flatten_fields( array $listing ): array {
		$out = array();
		foreach ( (array) ( $listing['facets'] ?? array() ) as $facet ) {
			$key = (string) ( $facet['field_key'] ?? '' );
			if ( '' === $key || str_starts_with( $key, 'ld_' ) ) {
				continue;
			}

			$value = null;
			if ( isset( $facet['value_text'] ) && '' !== (string) $facet['value_text'] ) {
				$value = (string) $facet['value_text'];
			} elseif ( isset( $facet['value_num'] ) && null !== $facet['value_num'] ) {
				$value = 0.0 + $facet['value_num'];
			}

			if ( array_key_exists( $key, $out ) ) {
				$out[ $key ] = array_merge( (array) $out[ $key ], array( $value ) );
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Minimal OpenAPI 3 document for the API surface.
	 *
	 * @param string $base_url REST base, e.g. https://site/wp-json/lodestar/v1.
	 * @return array<string,mixed>
	 */
	public static function openapi( string $base_url = '' ): array {
		return array(
			'openapi' => '3.0.3',
			'info'    => array(
				'title'   => 'Lodestar Directory API',
				'version' => defined( 'LODESTAR_VERSION' ) ? LODESTAR_VERSION : '0.1.0',
			),
			'servers' => array( array( 'url' => $base_url ) ),
			'paths'   => array(
				'/listings'             => array(
					'get'  => array( 'summary' => 'Search/list listings', 'responses' => array( '200' => array( 'description' => 'OK' ) ) ),
					'post' => array( 'summary' => 'Create a listing', 'security' => array( array( 'cookieAuth' => array() ) ), 'responses' => array( '201' => array( 'description' => 'Created' ), '429' => array( 'description' => 'Rate limited' ) ) ),
				),
				'/listings/{id}'        => array(
					'get'    => array( 'summary' => 'Get a listing', 'responses' => array( '200' => array( 'description' => 'OK' ), '404' => array( 'description' => 'Not found' ) ) ),
					'put'    => array( 'summary' => 'Update a listing', 'security' => array( array( 'cookieAuth' => array() ) ), 'responses' => array( '200' => array( 'description' => 'OK' ) ) ),
					'delete' => array( 'summary' => 'Delete a listing', 'security' => array( array( 'cookieAuth' => array() ) ), 'responses' => array( '200' => array( 'description' => 'OK' ) ) ),
				),
				'/listings/{id}/claim'  => array(
					'post' => array( 'summary' => 'Claim a listing', 'security' => array( array( 'cookieAuth' => array() ) ), 'responses' => array( '201' => array( 'description' => 'Claim opened' ) ) ),
				),
				'/listings/{id}/reviews' => array(
					'post' => array( 'summary' => 'Submit a review', 'security' => array( array( 'cookieAuth' => array() ) ), 'responses' => array( '201' => array( 'description' => 'Review submitted' ) ) ),
				),
				'/directory-types'      => array(
					'get' => array( 'summary' => 'List directory types + fields', 'responses' => array( '200' => array( 'description' => 'OK' ) ) ),
				),
			),
			'components' => array(
				'securitySchemes' => array(
					'cookieAuth' => array( 'type' => 'apiKey', 'in' => 'header', 'name' => 'X-WP-Nonce' ),
				),
				'schemas'         => array(
					'Listing' => array(
						'type'       => 'object',
						'properties' => array(
							'id'          => array( 'type' => 'integer' ),
							'title'       => array( 'type' => 'string' ),
							'slug'        => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'fields'      => array( 'type' => 'object' ),
							'geo'         => array( 'type' => 'object' ),
							'rating'      => array( 'type' => 'object' ),
							'citability'  => array( 'type' => array( 'integer', 'null' ) ),
						),
					),
				),
			),
		);
	}
}
