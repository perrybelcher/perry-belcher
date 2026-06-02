<?php
/**
 * Integration tests for the monetization flow (WordPress + MySQL).
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Integration;

use Lodestar\Data\ListingRepository;
use Lodestar\Monetize\ClaimManager;
use Lodestar\Monetize\FeaturedManager;
use Lodestar\Monetize\OrderManager;
use Lodestar\Monetize\PlanManager;
use WP_UnitTestCase;

/**
 * @covers \Lodestar\Monetize\PlanManager
 * @covers \Lodestar\Monetize\FeaturedManager
 * @covers \Lodestar\Monetize\ClaimManager
 * @covers \Lodestar\Monetize\OrderManager
 */
final class MonetizationFlowTest extends WP_UnitTestCase {

	private ListingRepository $repo;
	private PlanManager $plans;
	private FeaturedManager $featured;
	private ClaimManager $claims;
	private OrderManager $orders;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->repo     = new ListingRepository( $wpdb );
		$this->plans    = new PlanManager( $wpdb );
		$this->featured = new FeaturedManager( $wpdb );
		$this->claims   = new ClaimManager( $wpdb );
		$this->orders   = new OrderManager( $wpdb );
	}

	private function make_listing( int $author = 1, string $status = 'publish' ): int {
		return $this->repo->create(
			array( 'title' => 'L', 'status' => $status, 'author' => $author, 'directory_type_id' => 1, 'lat' => 40.5, 'lng' => -74.0 )
		);
	}

	public function test_plan_limit_blocks_over_quota(): void {
		$plan = $this->plans->create( array( 'slug' => 'solo', 'label' => 'Solo', 'price' => 0, 'listing_limit' => 1 ) );
		$this->assertGreaterThan( 0, $plan );
		$plan = $this->plans->find( $plan );

		$user = self::factory()->user->create();
		$this->assertTrue( $this->plans->can_user_submit( $user, $plan ) );

		$this->make_listing( $user );
		$this->assertFalse( $this->plans->can_user_submit( $user, $plan ) );
	}

	public function test_featured_sweep_unfeatures_and_expires(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'lodestar_listing_data';

		$featured_expired = $this->make_listing();
		$wpdb->update(
			$table,
			array( 'is_featured' => 1, 'featured_until' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
			array( 'listing_id' => $featured_expired )
		);

		$past_expiry = $this->make_listing();
		$wpdb->update( $table, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'listing_id' => $past_expiry ) );

		$result = $this->featured->sweep();

		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_featured FROM {$table} WHERE listing_id = %d", $featured_expired ) ) );
		$this->assertSame( 'draft', get_post_status( $past_expiry ) );
		$this->assertGreaterThanOrEqual( 1, $result['unfeatured'] );
		$this->assertGreaterThanOrEqual( 1, $result['expired'] );
	}

	public function test_claim_approval_transfers_ownership(): void {
		$owner    = self::factory()->user->create();
		$claimant = self::factory()->user->create();
		$listing  = $this->make_listing( $owner );

		$claim_id = $this->claims->claim( $listing, $claimant, array( 'doc' => 'license' ) );
		$this->assertGreaterThan( 0, $claim_id );
		$this->assertSame( 'pending', $this->claim_status( $listing ) );

		$this->assertTrue( $this->claims->approve( $claim_id ) );
		$this->assertSame( $claimant, (int) get_post( $listing )->post_author );
		$this->assertSame( 'claimed', $this->claim_status( $listing ) );

		// A resolved claim cannot be approved again.
		$this->assertFalse( $this->claims->approve( $claim_id ) );
	}

	public function test_order_lifecycle(): void {
		$id = $this->orders->create(
			array( 'user_id' => 1, 'plan_id' => 5, 'gateway' => 'stripe', 'gateway_ref' => 'cs_abc', 'amount' => 19.0 )
		);
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'pending', $this->orders->find( $id )['status'] );

		$this->orders->update_status_by_ref( 'cs_abc', 'paid' );
		$this->assertSame( 'paid', $this->orders->find( $id )['status'] );

		$this->orders->update_status_by_ref( 'cs_abc', 'refunded' );
		$this->assertSame( 'refunded', $this->orders->find( $id )['status'] );
	}

	private function claim_status( int $listing_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT claim_status FROM {$wpdb->prefix}lodestar_listing_data WHERE listing_id = %d", $listing_id )
		);
	}
}
