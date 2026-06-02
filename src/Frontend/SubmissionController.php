<?php
/**
 * Front-end listing submission (add/edit).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Frontend;

use Lodestar\Data\ListingRepository;
use Lodestar\DirectoryType\DirectoryTypeManager;
use Lodestar\DirectoryType\FieldManager;
use Lodestar\DirectoryType\FormBuilder;
use Lodestar\PostType\ListingPostType;
use Lodestar\Support\Request;
use Lodestar\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the submission form (shortcode) and processes submissions.
 *
 * Security posture (this is a public write path):
 *  - Nonce verified on every POST (check_admin_referer).
 *  - Capability/auth enforced: guests blocked unless explicitly allowed;
 *    edits require ownership (or edit_others_posts).
 *  - All input flows through Request + FieldSanitizer; values are whitelisted
 *    by field type before they ever reach the database.
 *  - Submitted status is forced to the configured default — a submitter can
 *    never self-publish by posting post_status=publish.
 */
final class SubmissionController {

	private const NONCE  = 'lodestar_submit_listing';
	private const ACTION = 'lodestar_submit_listing';

	public function __construct(
		private ListingRepository $repo,
		private DirectoryTypeManager $types,
		private FieldManager $fields,
		private TemplateLoader $templates,
	) {}

	/**
	 * Register the shortcode and POST handlers.
	 */
	public function register(): void {
		add_shortcode( 'lodestar_submit', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Shortcode renderer.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts( array( 'type' => '' ), (array) $atts, 'lodestar_submit' );

		if ( ! is_user_logged_in() && ! Settings::allow_guest_submissions() ) {
			return $this->templates->render(
				'submit-login-required',
				array( 'login_url' => wp_login_url( get_permalink() ?: home_url() ) )
			);
		}

		$type = $this->resolve_type( (string) $atts['type'] );
		if ( ! $type ) {
			return esc_html__( 'No directory type is configured yet.', 'lodestar' );
		}

		// Edit mode (ownership-checked).
		$listing_id = Request::getInt( 'listing_id' );
		$prefill    = array();
		if ( $listing_id > 0 ) {
			if ( ! $this->can_edit( $listing_id ) ) {
				return esc_html__( 'You do not have permission to edit this listing.', 'lodestar' );
			}
			$prefill = $this->prefill_values( $listing_id );
		}

		$defs    = $this->fields->forType( $type->id );
		$builder = new FormBuilder( $defs );

		$errors = $this->take_errors();

		// AI intake box (only when a provider is configured).
		$ai_enabled = Settings::ai_enabled();
		if ( $ai_enabled ) {
			Assets::enqueue_intake(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( \Lodestar\Ai\IntakeController::NONCE ),
					'type'    => $type->slug,
				)
			);
		}

		$hidden  = wp_nonce_field( self::NONCE, '_wpnonce', true, false );
		$hidden .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		$hidden .= '<input type="hidden" name="directory_type_id" value="' . esc_attr( (string) $type->id ) . '" />';
		$hidden .= '<input type="hidden" name="listing_id" value="' . esc_attr( (string) $listing_id ) . '" />';
		$hidden .= '<input type="hidden" name="redirect_to" value="' . esc_url( get_permalink() ?: home_url() ) . '" />';

		return $this->templates->render(
			'submit-form',
			array(
				'action_url'    => esc_url( admin_url( 'admin-post.php' ) ),
				'hidden_fields' => $hidden,
				'title_value'   => (string) ( $prefill['title'] ?? '' ),
				'content_value' => (string) ( $prefill['content'] ?? '' ),
				'fields_html'   => $builder->renderFields( $prefill['fields'] ?? array() ),
				'tags_value'    => (string) ( $prefill['tags'] ?? '' ),
				'errors'        => $errors,
				'is_edit'       => $listing_id > 0,
				'ai_enabled'    => $ai_enabled,
				'submit_label'  => $listing_id > 0 ? __( 'Update listing', 'lodestar' ) : __( 'Submit listing', 'lodestar' ),
			)
		);
	}

	/**
	 * Process a submission, then redirect (Post/Redirect/Get).
	 */
	public function handle(): void {
		check_admin_referer( self::NONCE );

		if ( ! is_user_logged_in() && ! Settings::allow_guest_submissions() ) {
			$this->bail( wp_login_url() );
		}

		$type = $this->types->find( Request::postInt( 'directory_type_id' ) );
		if ( ! $type ) {
			$this->bail( $this->redirect_target(), 'invalid_type' );
		}

		$listing_id = Request::postInt( 'listing_id' );
		$is_edit    = $listing_id > 0;

		if ( $is_edit && ! $this->can_edit( $listing_id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this listing.', 'lodestar' ) );
		}

		$defs = $this->fields->forType( $type->id );

		// Sanitise every custom field by its declared type.
		$raw   = Request::rawPostArray( 'lodestar_fields' );
		$clean = array();
		foreach ( $defs as $field ) {
			$clean[ $field->fieldKey ] = FieldSanitizer::sanitize( $field, $raw[ $field->fieldKey ] ?? null );
		}

		$built = ListingFormData::build(
			Request::postText( 'listing_title' ),
			Request::postHtml( 'listing_content' ),
			$clean,
			$defs,
			array(
				'category' => Request::postIntArray( 'lodestar_category' ),
				'location' => Request::postIntArray( 'lodestar_location' ),
			)
		);

		if ( ! empty( $built['errors'] ) ) {
			$this->stash_errors( $built['errors'] );
			$this->bail( $this->redirect_target( $listing_id ) );
		}

		$data                      = $built['data'];
		$data['directory_type_id'] = $type->id;

		if ( $is_edit ) {
			// Never let the body change ownership; status policy applies on edit.
			if ( Settings::moderate_edits() ) {
				$data['status'] = 'pending';
			}
			$this->repo->update( $listing_id, $data );
			$saved_id = $listing_id;
		} else {
			$data['status'] = Settings::default_listing_status();
			$data['author'] = get_current_user_id();
			$saved_id       = $this->repo->create( $data );
		}

		$this->bail( $this->success_target( $saved_id ), 'saved' );
	}

	/*
	 * --------------------------------------------------------------------- *
	 *  Internals
	 * --------------------------------------------------------------------- */

	/**
	 * Resolve a directory type from a slug, falling back to the first type.
	 *
	 * @param string $slug Requested type slug (may be empty).
	 */
	private function resolve_type( string $slug ) {
		if ( '' !== $slug ) {
			return $this->types->findBySlug( $slug );
		}

		$all = $this->types->all();

		return $all[0] ?? null;
	}

	/**
	 * Whether the current user may edit the given listing.
	 *
	 * @param int $listing_id Listing ID.
	 */
	private function can_edit( int $listing_id ): bool {
		$post = get_post( $listing_id );
		if ( ! $post || ListingPostType::POST_TYPE !== $post->post_type ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return false;
		}

		return (int) $post->post_author === $user_id || current_user_can( 'edit_others_posts' );
	}

	/**
	 * Build prefill values for edit mode.
	 *
	 * @param int $listing_id Listing ID.
	 * @return array<string,mixed>
	 */
	private function prefill_values( int $listing_id ): array {
		$listing = $this->repo->find( $listing_id );
		if ( ! $listing ) {
			return array();
		}

		$fields = array();
		foreach ( (array) $listing['facets'] as $facet ) {
			$key = $facet['field_key'] ?? '';
			if ( '' === $key || str_starts_with( (string) $key, 'ld_' ) ) {
				continue; // taxonomy denorm rows are not form fields.
			}
			$value = $facet['value_text'] ?? ( $facet['value_num'] ?? '' );
			if ( isset( $fields[ $key ] ) ) {
				$fields[ $key ] = array_merge( (array) $fields[ $key ], array( $value ) );
			} else {
				$fields[ $key ] = $value;
			}
		}
		// Non-facetable values live in meta.
		foreach ( (array) $listing['meta'] as $key => $value ) {
			$fields[ $key ] = $value;
		}

		return array(
			'title'   => $listing['title'],
			'content' => $listing['content'],
			'fields'  => $fields,
		);
	}

	/**
	 * Stash validation errors in a short-lived transient, keyed for this user.
	 *
	 * @param string[] $errors Error messages.
	 */
	private function stash_errors( array $errors ): void {
		set_transient( $this->error_key(), $errors, 60 );
	}

	/**
	 * Retrieve and clear stashed validation errors.
	 *
	 * @return string[]
	 */
	private function take_errors(): array {
		$key    = $this->error_key();
		$errors = get_transient( $key );
		if ( false === $errors ) {
			return array();
		}
		delete_transient( $key );

		return array_map( 'strval', (array) $errors );
	}

	/**
	 * Per-user (or per-session) transient key for stashed errors.
	 */
	private function error_key(): string {
		$who = get_current_user_id();
		if ( 0 === $who ) {
			$who = 'guest_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'anon' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		return 'lodestar_submit_errors_' . $who;
	}

	/**
	 * Where to send the user back to on validation failure.
	 *
	 * @param int $listing_id Optional listing being edited.
	 */
	private function redirect_target( int $listing_id = 0 ): string {
		$base = wp_validate_redirect( Request::postText( 'redirect_to' ), home_url( '/' ) );

		return $listing_id > 0 ? add_query_arg( 'listing_id', $listing_id, $base ) : $base;
	}

	/**
	 * Where to send the user after a successful save.
	 *
	 * @param int $listing_id Saved listing ID.
	 */
	private function success_target( int $listing_id ): string {
		$permalink = $listing_id > 0 ? get_permalink( $listing_id ) : '';
		if ( $permalink && 'publish' === get_post_status( $listing_id ) ) {
			return $permalink;
		}

		return add_query_arg( 'lodestar_notice', 'submitted', $this->redirect_target() );
	}

	/**
	 * Redirect and exit.
	 *
	 * @param string $url    Destination.
	 * @param string $notice Optional notice slug.
	 */
	private function bail( string $url, string $notice = '' ): void {
		if ( '' !== $notice ) {
			$url = add_query_arg( 'lodestar_notice', $notice, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
