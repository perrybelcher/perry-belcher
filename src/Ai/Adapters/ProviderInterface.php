<?php
/**
 * AI provider contract.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Ai\Adapters;

use Lodestar\Ai\ProviderResponse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A chat-completion provider. Adapters normalise to one shape so {@see \Lodestar\Ai\AiClient}
 * can route/fail over between them. Keys stay server-side inside the adapter.
 */
interface ProviderInterface {

	/**
	 * Provider identifier (e.g. 'anthropic').
	 */
	public function id(): string;

	/**
	 * Run a completion.
	 *
	 * @param string                          $system   System prompt.
	 * @param array<int,array{role:string,content:string}> $messages Conversation.
	 * @param array<string,mixed>             $opts     max_tokens, temperature, …
	 */
	public function complete( string $system, array $messages, array $opts = array() ): ProviderResponse;
}
