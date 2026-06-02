<?php
/**
 * Provider-agnostic AI client with failover.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Ai;

use Lodestar\Ai\Adapters\AnthropicAdapter;
use Lodestar\Ai\Adapters\GeminiAdapter;
use Lodestar\Ai\Adapters\ProviderInterface;
use Lodestar\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes completions through an ordered list of providers, falling over to the
 * next on failure. `AI_PROVIDER=auto` ⇒ [Anthropic, Gemini]. Every attempt is
 * logged. Keys live in the adapters (server-side); nothing here is exposed to
 * the client.
 */
final class AiClient {

	/**
	 * @param ProviderInterface[] $providers Ordered by preference.
	 * @param AiLog|null          $log       Optional observability log.
	 */
	public function __construct(
		private array $providers,
		private ?AiLog $log = null,
	) {}

	/**
	 * Build a client from configuration.
	 *
	 * @param callable|null $poster Optional HTTP poster (tests).
	 */
	public static function from_config( ?callable $poster = null ): self {
		$anthropic = new AnthropicAdapter( Settings::anthropic_key(), Settings::anthropic_model(), $poster );
		$gemini    = new GeminiAdapter( Settings::gemini_key(), Settings::gemini_model(), $poster );

		$providers = match ( Settings::ai_provider() ) {
			'anthropic' => array( $anthropic ),
			'gemini'    => array( $gemini ),
			default     => array( $anthropic, $gemini ),
		};

		global $wpdb;

		return new self( $providers, new AiLog( $wpdb ) );
	}

	/**
	 * Whether any provider is usable (has a key).
	 */
	public function is_available(): bool {
		return ! empty( $this->providers );
	}

	/**
	 * Run a completion with failover.
	 *
	 * @param string                          $operation  Operation name (for the log).
	 * @param string                          $system     System prompt.
	 * @param array<int,array{role:string,content:string}> $messages Conversation.
	 * @param array<string,mixed>             $opts       Options.
	 * @param int|null                        $listing_id Related listing.
	 */
	public function complete( string $operation, string $system, array $messages, array $opts = array(), ?int $listing_id = null ): ProviderResponse {
		$last = ProviderResponse::fail( 'No AI provider configured.' );

		foreach ( $this->providers as $provider ) {
			$start    = microtime( true );
			$response = $provider->complete( $system, $messages, $opts );
			$latency  = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( $this->log ) {
				$this->log->record( $operation, $provider->id(), $response, $listing_id, $latency );
			}

			if ( $response->ok ) {
				return $response;
			}

			$last = $response;
		}

		return $last;
	}
}
