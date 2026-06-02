<?php
/**
 * Shared HTTP plumbing for geocoder providers.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Geo\Provider;

use Lodestar\Geo\GeocoderProvider;
use Lodestar\Geo\GeoPoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class that handles the request/decode cycle so concrete providers only
 * implement URL building and response parsing.
 *
 * The HTTP fetcher is injectable: production uses `wp_remote_get`, tests pass a
 * closure returning a decoded array, so parsing is verifiable without network.
 */
abstract class AbstractProvider implements GeocoderProvider {

	/**
	 * Optional fetcher: callable(string $url): ?array.
	 *
	 * @var callable|null
	 */
	private $fetcher;

	/**
	 * @param callable|null $fetcher Custom HTTP fetcher (mainly for tests).
	 */
	public function __construct( ?callable $fetcher = null ) {
		$this->fetcher = $fetcher;
	}

	/**
	 * Build the request URL for an address.
	 *
	 * @param string $address Free-text address.
	 */
	abstract protected function build_url( string $address ): string;

	/**
	 * Parse a decoded JSON response into a point.
	 *
	 * @param array<mixed> $json Decoded JSON.
	 */
	abstract protected function parse( array $json ): ?GeoPoint;

	/**
	 * Request headers (overridable; Nominatim wants a User-Agent).
	 *
	 * @return array<string,string>
	 */
	protected function headers(): array {
		return array( 'User-Agent' => 'LodestarDirectory/1.0' );
	}

	/**
	 * Resolve an address to a point.
	 *
	 * @param string $address Free-text address.
	 */
	public function geocode( string $address ): ?GeoPoint {
		$address = trim( $address );
		if ( '' === $address ) {
			return null;
		}

		$json = $this->fetch( $this->build_url( $address ) );

		return is_array( $json ) ? $this->parse( $json ) : null;
	}

	/**
	 * Fetch and JSON-decode a URL.
	 *
	 * @param string $url Request URL.
	 * @return array<mixed>|null
	 */
	protected function fetch( string $url ): ?array {
		if ( null !== $this->fetcher ) {
			$result = ( $this->fetcher )( $url );
			return is_array( $result ) ? $result : null;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 5, 'headers' => $this->headers() ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : null;
	}
}
