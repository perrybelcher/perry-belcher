<?php
/**
 * Shared HTTP plumbing for AI adapters.
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
 * Base adapter: concrete providers implement endpoint/body/headers/parse; this
 * handles the POST + JSON decode. The HTTP poster is injectable so request
 * building and response parsing are testable without network, and the API key
 * never leaves the server.
 */
abstract class AbstractAdapter implements ProviderInterface {

	/**
	 * Optional poster: callable(string $url, array $body, array $headers): ?array.
	 *
	 * @var callable|null
	 */
	private $poster;

	/**
	 * @param string        $api_key API key (server-side).
	 * @param string        $model   Model id.
	 * @param callable|null $poster  Custom HTTP poster (tests).
	 */
	public function __construct(
		protected string $api_key,
		protected string $model,
		?callable $poster = null,
	) {
		$this->poster = $poster;
	}

	/**
	 * Endpoint URL for the request.
	 */
	abstract protected function endpoint(): string;

	/**
	 * Build the request body.
	 *
	 * @param string                          $system   System prompt.
	 * @param array<int,array{role:string,content:string}> $messages Conversation.
	 * @param array<string,mixed>             $opts     Options.
	 * @return array<string,mixed>
	 */
	abstract protected function build_body( string $system, array $messages, array $opts ): array;

	/**
	 * Request headers.
	 *
	 * @return array<string,string>
	 */
	abstract protected function build_headers(): array;

	/**
	 * Parse a decoded response into the normalised shape.
	 *
	 * @param array<mixed> $json Decoded response.
	 */
	abstract protected function parse( array $json ): ProviderResponse;

	/**
	 * Run a completion.
	 *
	 * @param string                          $system   System prompt.
	 * @param array<int,array{role:string,content:string}> $messages Conversation.
	 * @param array<string,mixed>             $opts     Options.
	 */
	public function complete( string $system, array $messages, array $opts = array() ): ProviderResponse {
		if ( '' === $this->api_key ) {
			return ProviderResponse::fail( $this->id() . ': missing API key' );
		}

		$json = $this->post( $this->endpoint(), $this->build_body( $system, $messages, $opts ), $this->build_headers() );
		if ( null === $json ) {
			return ProviderResponse::fail( $this->id() . ': request failed' );
		}
		if ( isset( $json['error'] ) ) {
			$message = is_array( $json['error'] ) ? ( $json['error']['message'] ?? 'error' ) : (string) $json['error'];
			return ProviderResponse::fail( $this->id() . ': ' . $message );
		}

		return $this->parse( $json );
	}

	/**
	 * POST JSON and decode.
	 *
	 * @param string               $url     Endpoint.
	 * @param array<string,mixed>  $body    Body.
	 * @param array<string,string> $headers Headers.
	 * @return array<mixed>|null
	 */
	protected function post( string $url, array $body, array $headers ): ?array {
		if ( null !== $this->poster ) {
			$result = ( $this->poster )( $url, $body, $headers );
			return is_array( $result ) ? $result : null;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array_merge( array( 'content-type' => 'application/json' ), $headers ),
				'body'    => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : null;
	}
}
