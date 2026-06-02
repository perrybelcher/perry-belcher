<?php
/**
 * Anthropic (Claude) adapter — primary provider.
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
 * Adapter for the Anthropic Messages API.
 */
final class AnthropicAdapter extends AbstractAdapter {

	private const API_VERSION = '2023-06-01';

	public function id(): string {
		return 'anthropic';
	}

	protected function endpoint(): string {
		return 'https://api.anthropic.com/v1/messages';
	}

	/**
	 * @param string                          $system   System prompt.
	 * @param array<int,array{role:string,content:string}> $messages Conversation.
	 * @param array<string,mixed>             $opts     Options.
	 * @return array<string,mixed>
	 */
	protected function build_body( string $system, array $messages, array $opts ): array {
		return array(
			'model'      => $this->model,
			'max_tokens' => (int) ( $opts['max_tokens'] ?? 1024 ),
			'system'     => $system,
			'messages'   => array_map(
				static fn ( $m ) => array(
					'role'    => 'assistant' === ( $m['role'] ?? 'user' ) ? 'assistant' : 'user',
					'content' => (string) ( $m['content'] ?? '' ),
				),
				$messages
			),
		);
	}

	/**
	 * @return array<string,string>
	 */
	protected function build_headers(): array {
		return array(
			'x-api-key'         => $this->api_key,
			'anthropic-version' => self::API_VERSION,
		);
	}

	/**
	 * @param array<mixed> $json Decoded response.
	 */
	protected function parse( array $json ): ProviderResponse {
		$text = '';
		foreach ( (array) ( $json['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}

		if ( '' === $text ) {
			return ProviderResponse::fail( 'anthropic: empty response' );
		}

		return new ProviderResponse(
			true,
			$text,
			(int) ( $json['usage']['input_tokens'] ?? 0 ),
			(int) ( $json['usage']['output_tokens'] ?? 0 )
		);
	}
}
