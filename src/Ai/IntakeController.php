<?php
/**
 * AI intake endpoint + citability score persistence.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Ai;

use Lodestar\Data\ListingRepository;
use Lodestar\DirectoryType\DirectoryTypeManager;
use Lodestar\DirectoryType\FieldManager;
use Lodestar\PostType\ListingPostType;
use Lodestar\Support\Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the enrichment endpoint (returns a *proposal* the form pre-fills — it
 * never saves) and recomputes the citability score whenever a listing is saved.
 *
 * The endpoint is nonce- and login-gated; the AI proposal contains only listing
 * field values, never any key.
 */
final class IntakeController {

	public const NONCE = 'lodestar_enrich';

	public function __construct(
		private ListingEnricher $enricher,
		private DirectoryTypeManager $types,
		private ListingRepository $repo,
		private FieldManager $fields,
		private \wpdb $db,
	) {}

	/**
	 * Register the AJAX endpoint and the score hook.
	 */
	public function register(): void {
		add_action( 'wp_ajax_lodestar_enrich', array( $this, 'handle_enrich' ) );
		add_action( 'save_post_' . ListingPostType::POST_TYPE, array( $this, 'persist_score' ), 20, 1 );
	}

	/**
	 * Handle an enrichment request: return a draft proposal for human review.
	 */
	public function handle_enrich(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'lodestar' ) ), 403 );
		}

		$type = $this->resolve_type( Request::postKey( 'type' ) );
		if ( ! $type ) {
			wp_send_json_error( array( 'message' => __( 'Unknown directory type.', 'lodestar' ) ), 400 );
		}

		$result = $this->enricher->enrich( Request::postText( 'input' ), $type->id );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['error'] ?? __( 'Could not enrich.', 'lodestar' ) ) ) );
		}

		// Proposal only — title/description/fields/faq for the form to pre-fill.
		wp_send_json_success(
			array(
				'title'       => $result['title'] ?? '',
				'description' => $result['description'] ?? '',
				'fields'      => $result['fields'] ?? array(),
				'faq'         => $result['faq'] ?? array(),
			)
		);
	}

	/**
	 * Recompute and persist the citability score after a listing is saved.
	 *
	 * @param int $post_id Listing ID.
	 */
	public function persist_score( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		$listing = $this->repo->find( $post_id );
		if ( ! $listing ) {
			return;
		}

		$defs   = $this->fields->forType( (int) ( $listing['data']['directory_type_id'] ?? 0 ) );
		$result = CitabilityScorer::score( $listing, $defs );

		$this->db->update(
			$this->db->prefix . 'lodestar_listing_data',
			array( 'ai_citability_score' => $result['score'] ),
			array( 'listing_id' => $post_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Resolve a directory type from a slug, falling back to the first.
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
