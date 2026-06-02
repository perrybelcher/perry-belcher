<?php
/**
 * Front-end user dashboard for managing own listings.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Frontend;

use Lodestar\Data\ListingRepository;
use Lodestar\PostType\ListingPostType;
use Lodestar\Support\Request;
use Lodestar\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the current user's listings with edit/delete controls.
 *
 * Login is required to view; delete is gated by nonce + ownership. Deletion
 * goes through {@see ListingRepository} so the custom-table rows are cleaned up
 * too — never an orphaned facet index row.
 */
final class DashboardController {

	private const NONCE  = 'lodestar_delete_listing';
	private const ACTION = 'lodestar_delete_listing';

	public function __construct(
		private ListingRepository $repo,
		private TemplateLoader $templates,
	) {}

	/**
	 * Register the shortcode and delete handler.
	 */
	public function register(): void {
		add_shortcode( 'lodestar_dashboard', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_delete' ) );
	}

	/**
	 * Shortcode renderer.
	 */
	public function render(): string {
		if ( ! is_user_logged_in() ) {
			return $this->templates->render(
				'submit-login-required',
				array( 'login_url' => wp_login_url( get_permalink() ?: home_url() ) )
			);
		}

		$posts = get_posts(
			array(
				'post_type'        => ListingPostType::POST_TYPE,
				'author'           => get_current_user_id(),
				'post_status'      => array( 'publish', 'pending', 'draft' ),
				'numberposts'      => 100,
				'suppress_filters' => false,
			)
		);

		$rows      = array();
		$submit_url = Settings::submit_page_url();
		foreach ( $posts as $post ) {
			$rows[] = array(
				'title'       => $post->post_title,
				'status'      => $post->post_status,
				'view_url'    => 'publish' === $post->post_status ? (string) get_permalink( $post ) : '',
				'edit_url'    => add_query_arg( 'listing_id', $post->ID, $submit_url ),
				'delete_form' => $this->delete_form( (int) $post->ID ),
			);
		}

		return $this->templates->render(
			'dashboard',
			array(
				'rows'   => $rows,
				'notice' => Request::getKey( 'lodestar_notice' ),
			)
		);
	}

	/**
	 * Handle a delete request, then redirect.
	 */
	public function handle_delete(): void {
		check_admin_referer( self::NONCE );

		if ( ! is_user_logged_in() ) {
			$this->bail( wp_login_url() );
		}

		$listing_id = Request::postInt( 'listing_id' );
		if ( $listing_id > 0 && $this->owns( $listing_id ) ) {
			$this->repo->delete( $listing_id );
			$this->bail( $this->redirect_target(), 'deleted' );
		}

		$this->bail( $this->redirect_target(), 'delete_failed' );
	}

	/**
	 * Whether the current user owns the listing (or can edit others).
	 *
	 * @param int $listing_id Listing ID.
	 */
	private function owns( int $listing_id ): bool {
		$post = get_post( $listing_id );
		if ( ! $post || ListingPostType::POST_TYPE !== $post->post_type ) {
			return false;
		}

		return (int) $post->post_author === get_current_user_id() || current_user_can( 'delete_others_posts' );
	}

	/**
	 * Build the inline delete form markup for a listing.
	 *
	 * @param int $listing_id Listing ID.
	 */
	private function delete_form( int $listing_id ): string {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lodestar-dashboard__delete">';
		$html .= wp_nonce_field( self::NONCE, '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		$html .= '<input type="hidden" name="listing_id" value="' . esc_attr( (string) $listing_id ) . '" />';
		$html .= '<input type="hidden" name="redirect_to" value="' . esc_url( get_permalink() ?: home_url() ) . '" />';
		$html .= '<button type="submit" class="lodestar-button lodestar-button--danger">' . esc_html__( 'Delete', 'lodestar' ) . '</button>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * Validated redirect back to the dashboard page.
	 */
	private function redirect_target(): string {
		return wp_validate_redirect( Request::postText( 'redirect_to' ), home_url( '/' ) );
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
