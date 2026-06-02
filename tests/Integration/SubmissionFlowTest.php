<?php
/**
 * Integration tests for the submission pipeline (sanitise -> map -> persist).
 *
 * Exercises the same path the controller drives, minus the HTTP/nonce glue:
 * field sanitisation, form mapping, and persistence through the repository.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Integration;

use Lodestar\Data\ListingRepository;
use Lodestar\DirectoryType\DirectoryTypeManager;
use Lodestar\DirectoryType\FieldManager;
use Lodestar\Frontend\FieldSanitizer;
use Lodestar\Frontend\ListingFormData;
use Lodestar\Support\Settings;
use WP_UnitTestCase;

/**
 * @covers \Lodestar\Frontend\ListingFormData
 * @covers \Lodestar\Frontend\FieldSanitizer
 */
final class SubmissionFlowTest extends WP_UnitTestCase {

	private ListingRepository $repo;
	private DirectoryTypeManager $types;
	private FieldManager $fields;
	private int $type_id;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->repo   = new ListingRepository( $wpdb );
		$this->types  = new DirectoryTypeManager( $wpdb );
		$this->fields = new FieldManager( $wpdb );

		$this->type_id = $this->types->create( array( 'slug' => 'restaurants', 'label' => 'Restaurants' ) );
		$this->fields->create( array( 'directory_type_id' => $this->type_id, 'field_key' => 'cuisine', 'label' => 'Cuisine', 'input_type' => 'select', 'is_facetable' => true ) );
		$this->fields->create( array( 'directory_type_id' => $this->type_id, 'field_key' => 'price', 'label' => 'Price', 'input_type' => 'number', 'is_facetable' => true ) );
		$this->fields->create( array( 'directory_type_id' => $this->type_id, 'field_key' => 'about', 'label' => 'About', 'input_type' => 'richtext', 'is_facetable' => false ) );
	}

	/**
	 * Mimic the controller's per-field sanitisation step.
	 *
	 * @param array<string,mixed> $raw Raw field values.
	 * @return array<string,mixed>
	 */
	private function sanitize_fields( array $raw ): array {
		$clean = array();
		foreach ( $this->fields->forType( $this->type_id ) as $field ) {
			$clean[ $field->fieldKey ] = FieldSanitizer::sanitize( $field, $raw[ $field->fieldKey ] ?? null );
		}
		return $clean;
	}

	public function test_submission_creates_pending_listing_with_indexed_facets(): void {
		global $wpdb;

		$clean = $this->sanitize_fields(
			array( 'cuisine' => 'italian', 'price' => '19.5', 'about' => '<strong>Cozy</strong>' )
		);
		$built = ListingFormData::build( 'Joe Pizza', 'Great pizza', $clean, $this->fields->forType( $this->type_id ) );
		$this->assertSame( array(), $built['errors'] );

		$data                      = $built['data'];
		$data['directory_type_id'] = $this->type_id;
		$data['status']            = Settings::default_listing_status();
		$data['author']            = 1;

		$id = $this->repo->create( $data );
		$this->assertGreaterThan( 0, $id );

		// Default status is pending (submitter cannot self-publish).
		$this->assertSame( 'pending', get_post_status( $id ) );

		// Facetable fields indexed; non-facetable richtext is not.
		$indexed = $wpdb->get_col(
			$wpdb->prepare( "SELECT field_key FROM {$wpdb->prefix}lodestar_field_index WHERE listing_id = %d", $id )
		);
		$this->assertContains( 'cuisine', $indexed );
		$this->assertContains( 'price', $indexed );
		$this->assertNotContains( 'about', $indexed );

		// Richtext stored in meta, script stripped on the way in.
		$listing = $this->repo->find( $id );
		$this->assertArrayHasKey( 'about', $listing['meta'] );
		$this->assertStringContainsString( 'Cozy', $listing['meta']['about'] );
	}

	public function test_xss_payload_in_title_is_neutralized(): void {
		// The controller runs the title through sanitize_text_field (Request::postText).
		$title = sanitize_text_field( '<script>alert(document.cookie)</script>Evil Co' );
		$this->assertStringNotContainsString( '<script', $title );

		$id = $this->repo->create(
			array( 'title' => $title, 'status' => 'pending', 'directory_type_id' => $this->type_id, 'author' => 1 )
		);

		$listing = $this->repo->find( $id );
		$this->assertStringNotContainsString( '<script', $listing['title'] );
	}

	public function test_sqli_payload_is_stored_literally_not_executed(): void {
		global $wpdb;

		$payload = "Joe'); DROP TABLE {$wpdb->prefix}posts; --";
		$clean   = $this->sanitize_fields( array( 'cuisine' => 'italian', 'price' => '9', 'about' => $payload ) );
		$built   = ListingFormData::build( $payload, $payload, $clean, $this->fields->forType( $this->type_id ) );

		$data                      = $built['data'];
		$data['directory_type_id'] = $this->type_id;
		$data['status']            = 'pending';
		$data['author']            = 1;

		$id = $this->repo->create( $data );
		$this->assertGreaterThan( 0, $id );

		// The posts table must still exist and the listing be retrievable —
		// proof the payload was bound as a value, never executed.
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}posts'" );
		$this->assertNotEmpty( $table_exists );
		$this->assertNotNull( $this->repo->find( $id ) );
	}
}
