<?php
/**
 * Observability log for the AI layer.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes one row per provider call to `lodestar_ai_log` for cost/latency
 * observability. No prompt or completion text is stored — only metadata.
 */
final class AiLog {

	/**
	 * @param \wpdb $db WordPress database handle.
	 */
	public function __construct( private \wpdb $db ) {}

	/**
	 * Record a provider call.
	 *
	 * @param string           $operation  enrich|describe|score|schema.
	 * @param string           $provider   Provider id.
	 * @param ProviderResponse $response   The result.
	 * @param int|null         $listing_id Related listing, if any.
	 * @param int              $latency_ms Round-trip latency.
	 */
	public function record( string $operation, string $provider, ProviderResponse $response, ?int $listing_id = null, int $latency_ms = 0 ): void {
		$this->db->insert(
			$this->db->prefix . 'lodestar_ai_log',
			array(
				'listing_id' => $listing_id,
				'provider'   => $provider,
				'operation'  => $operation,
				'tokens_in'  => $response->tokensIn,
				'tokens_out' => $response->tokensOut,
				'latency_ms' => $latency_ms,
				'status'     => $response->ok ? 'ok' : 'error',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
	}
}
