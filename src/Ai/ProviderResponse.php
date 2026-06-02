<?php
/**
 * Normalised AI provider response.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable, provider-neutral completion result. Adapters normalise their raw
 * responses to this shape so the rest of the system never sees provider details.
 */
final class ProviderResponse {

	/**
	 * @param bool   $ok        Whether the completion succeeded.
	 * @param string $text      The generated text.
	 * @param int    $tokensIn  Prompt tokens.
	 * @param int    $tokensOut Completion tokens.
	 * @param string $error     Error detail when not ok.
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly string $text = '',
		public readonly int $tokensIn = 0,
		public readonly int $tokensOut = 0,
		public readonly string $error = '',
	) {}

	/**
	 * A failed response.
	 *
	 * @param string $error Reason.
	 */
	public static function fail( string $error ): self {
		return new self( false, '', 0, 0, $error );
	}
}
