<?php
/**
 * Unit tests for AiClient failover and the provider adapters.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Tests\Unit;

use Lodestar\Ai\Adapters\AnthropicAdapter;
use Lodestar\Ai\Adapters\GeminiAdapter;
use Lodestar\Ai\Adapters\ProviderInterface;
use Lodestar\Ai\AiClient;
use Lodestar\Ai\ProviderResponse;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! class_exists( AiClient::class ) ) {
	$lodestar_src = dirname( __DIR__, 2 ) . '/src/Ai';
	require_once $lodestar_src . '/ProviderResponse.php';
	require_once $lodestar_src . '/Adapters/ProviderInterface.php';
	require_once $lodestar_src . '/Adapters/AbstractAdapter.php';
	require_once $lodestar_src . '/Adapters/AnthropicAdapter.php';
	require_once $lodestar_src . '/Adapters/GeminiAdapter.php';
	require_once $lodestar_src . '/AiClient.php';
}

/**
 * @covers \Lodestar\Ai\AiClient
 * @covers \Lodestar\Ai\Adapters\AnthropicAdapter
 * @covers \Lodestar\Ai\Adapters\GeminiAdapter
 */
final class AiClientTest extends TestCase {

	/**
	 * A stub provider returning a fixed response and counting calls.
	 */
	private function provider( string $id, ProviderResponse $response, int &$calls ): ProviderInterface {
		return new class( $id, $response, $calls ) implements ProviderInterface {
			public function __construct( private string $pid, private ProviderResponse $resp, private int &$calls ) {}
			public function id(): string {
				return $this->pid;
			}
			public function complete( string $system, array $messages, array $opts = array() ): ProviderResponse {
				++$this->calls;
				return $this->resp;
			}
		};
	}

	public function test_returns_first_successful_provider(): void {
		$calls  = 0;
		$client = new AiClient(
			array(
				$this->provider( 'a', new ProviderResponse( true, 'primary' ), $calls ),
				$this->provider( 'b', new ProviderResponse( true, 'secondary' ), $calls ),
			)
		);

		$response = $client->complete( 'enrich', 'sys', array( array( 'role' => 'user', 'content' => 'hi' ) ) );
		$this->assertSame( 'primary', $response->text );
		$this->assertSame( 1, $calls ); // second never called.
	}

	public function test_fails_over_when_primary_errors(): void {
		$calls  = 0;
		$client = new AiClient(
			array(
				$this->provider( 'a', ProviderResponse::fail( 'down' ), $calls ),
				$this->provider( 'b', new ProviderResponse( true, 'fallback worked' ), $calls ),
			)
		);

		$response = $client->complete( 'enrich', 'sys', array() );
		$this->assertTrue( $response->ok );
		$this->assertSame( 'fallback worked', $response->text );
		$this->assertSame( 2, $calls ); // both tried.
	}

	public function test_returns_last_failure_when_all_down(): void {
		$calls  = 0;
		$client = new AiClient(
			array(
				$this->provider( 'a', ProviderResponse::fail( 'down a' ), $calls ),
				$this->provider( 'b', ProviderResponse::fail( 'down b' ), $calls ),
			)
		);

		$response = $client->complete( 'enrich', 'sys', array() );
		$this->assertFalse( $response->ok );
		$this->assertSame( 'down b', $response->error );
	}

	public function test_anthropic_missing_key_fails_gracefully(): void {
		$adapter  = new AnthropicAdapter( '', 'claude-x' );
		$response = $adapter->complete( 'sys', array() );
		$this->assertFalse( $response->ok );
		$this->assertStringContainsString( 'missing API key', $response->error );
	}

	public function test_anthropic_parses_response(): void {
		$adapter = new AnthropicAdapter(
			'key',
			'claude-x',
			static fn ( $url, $body, $headers ) => array(
				'content' => array( array( 'type' => 'text', 'text' => 'Hello world' ) ),
				'usage'   => array( 'input_tokens' => 12, 'output_tokens' => 5 ),
			)
		);

		$response = $adapter->complete( 'sys', array( array( 'role' => 'user', 'content' => 'hi' ) ) );
		$this->assertTrue( $response->ok );
		$this->assertSame( 'Hello world', $response->text );
		$this->assertSame( 12, $response->tokensIn );
		$this->assertSame( 5, $response->tokensOut );
	}

	public function test_gemini_parses_response_and_surfaces_api_error(): void {
		$ok = new GeminiAdapter(
			'key',
			'gemini-x',
			static fn ( $url, $body, $headers ) => array(
				'candidates'    => array( array( 'content' => array( 'parts' => array( array( 'text' => 'Gemini says hi' ) ) ) ) ),
				'usageMetadata' => array( 'promptTokenCount' => 7, 'candidatesTokenCount' => 3 ),
			)
		);
		$this->assertSame( 'Gemini says hi', $ok->complete( 'sys', array() )->text );

		$err = new GeminiAdapter( 'key', 'gemini-x', static fn ( $url, $body, $headers ) => array( 'error' => array( 'message' => 'quota' ) ) );
		$response = $err->complete( 'sys', array() );
		$this->assertFalse( $response->ok );
		$this->assertStringContainsString( 'quota', $response->error );
	}
}
