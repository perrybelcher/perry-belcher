<?php
/**
 * REST API surface (/wp-json/lodestar/v1/*).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Api;

use Lodestar\Data\ListingRepository;
use Lodestar\Data\ReviewRepository;
use Lodestar\DirectoryType\DirectoryTypeManager;
use Lodestar\DirectoryType\FieldManager;
use Lodestar\Frontend\FieldSanitizer;
use Lodestar\Frontend\ListingFormData;
use Lodestar\Frontend\SearchRequest;
use Lodestar\Monetize\ClaimManager;
use Lodestar\PostType\ListingPostType;
use Lodestar\Support\Request;
use Lodestar\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the directory over REST so a headless front end or agent can drive
 * search and submission. Reads are public; writes require auth and are
 * rate-limited. Input reuses the same sanitiser/validator/mapper the front-end
 * forms use, so there is one trusted path into the database.
 */
final class RestController {

	private const NS = 'lodestar/v1';

	private const WRITE_LIMIT  = 30;   // requests…
	private const WRITE_WINDOW = 600;  // …per 10 minutes.

	public function __construct(
		private ListingRepository $repo,
		private DirectoryTypeManager $types,
		private FieldManager $fields,
		private ClaimManager $claims,
		private ReviewRepository $reviews,
		private RateLimiter $limiter,
	) {}

	/**
	 * Register all routes on rest_api_init.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Define the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/listings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_listings' ),
					'permission_callback' => '__return_true',
					'args'                => Schemas::search_args(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_listing' ),
					'permission_callback' => array( $this, 'can_create' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/listings/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_listing' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update_listing' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_listing' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/listings/(?P<id>\d+)/claim',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'claim_listing' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			self::NS,
			'/listings/(?P<id>\d+)/reviews',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_review' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			self::NS,
			'/directory-types',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_types' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/openapi',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'openapi' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/*
	 * --------------------------------------------------------------------- *
	 *  Handlers
	 * --------------------------------------------------------------------- */

	/**
	 * GET /listings — faceted search.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_listings( \WP_REST_Request $request ) {
		$type = $this->resolve_type( (string) $request->get_param( 'type' ) );
		if ( ! $type ) {
			return new \WP_Error( 'lodestar_no_type', __( 'No directory type configured.', 'lodestar' ), array( 'status' => 404 ) );
		}

		$defs  = $this->fields->forType( $type->id );
		$query = SearchRequest::build( (array) $request->get_params(), $defs, $type->id, Settings::default_radius_km() );
		$result = $this->repo->search( $query );

		$items = array();
		foreach ( $result->items as $row ) {
			$id      = (int) ( $row->listing_id ?? 0 );
			$listing = $this->repo->find( $id );
			if ( $listing ) {
				$items[] = Schemas::format_listing( $listing, (string) get_permalink( $id ) );
			}
		}

		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $result->total );
		$response->header( 'X-WP-TotalPages', (string) $result->pages() );

		return $response;
	}

	/**
	 * GET /listings/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_listing( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$listing = $this->repo->find( $id );
		if ( ! $listing ) {
			return new \WP_Error( 'lodestar_not_found', __( 'Listing not found.', 'lodestar' ), array( 'status' => 404 ) );
		}

		$owner = $this->owns( $id );
		if ( 'publish' !== get_post_status( $id ) && ! $owner ) {
			// Do not reveal unpublished listings to non-owners.
			return new \WP_Error( 'lodestar_not_found', __( 'Listing not found.', 'lodestar' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( Schemas::format_listing( $listing, (string) get_permalink( $id ), $owner ), 200 );
	}

	/**
	 * POST /listings.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function create_listing( \WP_REST_Request $request ) {
		$limited = $this->rate_guard( 'create' );
		if ( $limited ) {
			return $limited;
		}

		$type = $this->resolve_type( (string) $request->get_param( 'type' ) );
		if ( ! $type ) {
			return new \WP_Error( 'lodestar_no_type', __( 'Unknown directory type.', 'lodestar' ), array( 'status' => 400 ) );
		}

		$defs  = $this->fields->forType( $type->id );
		$built = ListingFormData::build(
			sanitize_text_field( (string) $request->get_param( 'title' ) ),
			wp_kses_post( (string) $request->get_param( 'description' ) ),
			$this->clean_fields( $defs, (array) $request->get_param( 'fields' ) ),
			$defs,
			array(
				'category' => array_map( 'absint', (array) $request->get_param( 'categories' ) ),
				'location' => array_map( 'absint', (array) $request->get_param( 'locations' ) ),
			)
		);

		if ( ! empty( $built['errors'] ) ) {
			return new \WP_Error( 'lodestar_invalid', implode( ' ', $built['errors'] ), array( 'status' => 422 ) );
		}

		$data                      = $built['data'];
		$data['directory_type_id'] = $type->id;
		$data['status']            = Settings::default_listing_status();
		$data['author']            = get_current_user_id();

		$id = $this->repo->create( $data );
		if ( 0 === $id ) {
			return new \WP_Error( 'lodestar_create_failed', __( 'Could not create the listing.', 'lodestar' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( Schemas::format_listing( $this->repo->find( $id ), (string) get_permalink( $id ), true ), 201 );
	}

	/**
	 * PUT/PATCH /listings/{id} — partial update.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function update_listing( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$type = $this->types->find( (int) ( $this->repo->find( $id )['data']['directory_type_id'] ?? 0 ) );
		$defs = $type ? $this->fields->forType( $type->id ) : array();

		$data = array();
		if ( null !== $request->get_param( 'title' ) ) {
			$data['title'] = sanitize_text_field( (string) $request->get_param( 'title' ) );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$data['content'] = wp_kses_post( (string) $request->get_param( 'description' ) );
		}
		if ( null !== $request->get_param( 'categories' ) ) {
			$data['category'] = array_map( 'absint', (array) $request->get_param( 'categories' ) );
		}
		if ( null !== $request->get_param( 'locations' ) ) {
			$data['location'] = array_map( 'absint', (array) $request->get_param( 'locations' ) );
		}
		if ( null !== $request->get_param( 'fields' ) ) {
			$data = array_merge( $data, ListingFormData::map_fields( $this->clean_fields( $defs, (array) $request->get_param( 'fields' ) ), $defs ) );
		}

		if ( ! $this->repo->update( $id, $data ) ) {
			return new \WP_Error( 'lodestar_update_failed', __( 'Could not update the listing.', 'lodestar' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( Schemas::format_listing( $this->repo->find( $id ), (string) get_permalink( $id ), true ), 200 );
	}

	/**
	 * DELETE /listings/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function delete_listing( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $this->repo->delete( $id ) ) {
			return new \WP_Error( 'lodestar_delete_failed', __( 'Could not delete the listing.', 'lodestar' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	/**
	 * POST /listings/{id}/claim.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function claim_listing( \WP_REST_Request $request ) {
		$limited = $this->rate_guard( 'claim' );
		if ( $limited ) {
			return $limited;
		}

		$id       = (int) $request['id'];
		$evidence = is_array( $request->get_param( 'evidence' ) ) ? $request->get_param( 'evidence' ) : array();
		$claim_id = $this->claims->claim( $id, get_current_user_id(), $evidence );

		if ( 0 === $claim_id ) {
			return new \WP_Error( 'lodestar_claim_failed', __( 'Could not open a claim.', 'lodestar' ), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( array( 'claim_id' => $claim_id, 'status' => 'pending' ), 201 );
	}

	/**
	 * POST /listings/{id}/reviews.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function create_review( \WP_REST_Request $request ) {
		$limited = $this->rate_guard( 'review' );
		if ( $limited ) {
			return $limited;
		}

		$id        = (int) $request['id'];
		$review_id = $this->reviews->create(
			array(
				'listing_id' => $id,
				'user_id'    => get_current_user_id(),
				'rating'     => (int) $request->get_param( 'rating' ),
				'title'      => sanitize_text_field( (string) $request->get_param( 'title' ) ),
				'body'       => wp_kses_post( (string) $request->get_param( 'body' ) ),
			)
		);

		if ( 0 === $review_id ) {
			return new \WP_Error( 'lodestar_review_failed', __( 'Could not submit the review.', 'lodestar' ), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( array( 'review_id' => $review_id, 'status' => 'pending' ), 201 );
	}

	/**
	 * GET /directory-types — types + their fields, for client form building.
	 */
	public function list_types() {
		$out = array();
		foreach ( $this->types->all() as $type ) {
			$fields = array();
			foreach ( $this->fields->forType( $type->id ) as $field ) {
				$fields[] = array(
					'key'        => $field->fieldKey,
					'label'      => $field->label,
					'input_type' => $field->inputType,
					'required'   => $field->isRequired,
					'facetable'  => $field->isFacetable,
					'options'    => $field->options,
				);
			}
			$out[] = array(
				'id'             => $type->id,
				'slug'           => $type->slug,
				'label'          => $type->label,
				'singular_label' => $type->singularLabel,
				'fields'         => $fields,
			);
		}

		return new \WP_REST_Response( $out, 200 );
	}

	/**
	 * GET /openapi — the API description.
	 */
	public function openapi() {
		return new \WP_REST_Response( Schemas::openapi( rest_url( self::NS ) ), 200 );
	}

	/*
	 * --------------------------------------------------------------------- *
	 *  Permission + helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Permission: may the current actor create a listing?
	 */
	public function can_create(): bool {
		return is_user_logged_in() || Settings::allow_guest_submissions();
	}

	/**
	 * Permission: may the current user edit/delete this listing?
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_edit( \WP_REST_Request $request ): bool {
		return $this->owns( (int) $request['id'] );
	}

	/**
	 * Whether the current user owns (or can edit others') the listing.
	 *
	 * @param int $id Listing ID.
	 */
	private function owns( int $id ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$post = get_post( $id );
		if ( ! $post || ListingPostType::POST_TYPE !== $post->post_type ) {
			return false;
		}

		return (int) $post->post_author === get_current_user_id() || current_user_can( 'edit_others_posts' );
	}

	/**
	 * Sanitise a raw fields bundle by each field's declared type.
	 *
	 * @param \Lodestar\DirectoryType\FieldDefinition[] $defs  Field definitions.
	 * @param array<string,mixed>                       $raw   Raw field values.
	 * @return array<string,mixed>
	 */
	private function clean_fields( array $defs, array $raw ): array {
		$clean = array();
		foreach ( $defs as $field ) {
			$clean[ $field->fieldKey ] = FieldSanitizer::sanitize( $field, $raw[ $field->fieldKey ] ?? null );
		}

		return $clean;
	}

	/**
	 * Apply the write rate limit; returns a 429 WP_Error when exceeded.
	 *
	 * @param string $bucket Operation bucket name.
	 */
	private function rate_guard( string $bucket ) {
		$actor = get_current_user_id() ?: 'ip_' . md5( Request::ip() );
		$key   = 'lodestar_rl_' . $bucket . '_' . $actor;

		$result = $this->limiter->check( $key, self::WRITE_LIMIT, self::WRITE_WINDOW );
		if ( ! $result['allowed'] ) {
			return new \WP_Error(
				'lodestar_rate_limited',
				__( 'Too many requests. Please slow down.', 'lodestar' ),
				array( 'status' => 429, 'retry_after' => $result['retry_after'] )
			);
		}

		return null;
	}

	/**
	 * Resolve a type by slug, or the first type.
	 *
	 * @param string $slug Type slug.
	 */
	private function resolve_type( string $slug ) {
		if ( '' !== $slug ) {
			return $this->types->findBySlug( $slug );
		}
		$all = $this->types->all();

		return $all[0] ?? null;
	}
}
