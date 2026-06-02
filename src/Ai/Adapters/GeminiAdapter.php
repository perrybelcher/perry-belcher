<?php
/**
 * Google Gemini adapter — fallback provider.
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
 * Adapter for the Gemini generateContent API.
 */
final class GeminiAdapter extends AbstractAdapter {

	public function id(): string {
		return 'gemini';
	}

	protected function endpoint(): string {
		// The key travels in the query string of a server-side request only.
		return 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model )
			. ':generateContent?key=' . rawurlencode( $this->api_key );
	}

	/**
	 * @param string                          $system   System prompt.
	 * @param array<int,array{role:string,content:string}> $messages Conversation.
	 * @param array<string,mixed>             $opts     Options.
	 * @return array<string,mixed>
	 */
	protected function build_body( string $system, array $messages, array $opts ): array {
		$contents = array_map(
			static fn ( $m ) => array(
				'role'  => 'assistant' === ( $m['role'] ?? 'user' ) ? 'model' : 'user',
				'parts' => array( array( 'text' => (string) ( $m['content'] ?? '' ) ) ),
			),
			$messages
		);

		$body = array(
			'contents'         => $contents,
			'generationConfig' => array( 'maxOutputTokens' => (int) ( $opts['max_tokens'] ?? 1024 ) ),
		);

		if ( '' !== $system ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system ) ) );
		}

		return $body;
	}

	/**
	 * @return array<string,string>
	 */
	protected function build_headers(): array {
		return array();
	}

	/**
	 * @param array<mixed> $json Decoded response.
	 */
	protected function parse( array $json ): ProviderResponse {
		$parts = $json['candidates'][0]['content']['parts'] ?? array();
		$text  = '';
		foreach ( (array) $parts as $part ) {
			$text .= (string) ( $part['text'] ?? '' );
		}

		if ( '' === $text ) {
			return ProviderResponse::fail( 'gemini: empty response' );
		}

		return new ProviderResponse(
			true,
			$text,
			(int) ( $json['usageMetadata']['promptTokenCount'] ?? 0 ),
			(int) ( $json['usageMetadata']['candidatesTokenCount'] ?? 0 )
		);
	}
}
