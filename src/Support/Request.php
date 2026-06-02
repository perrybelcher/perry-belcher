<?php
/**
 * The single request-sanitising layer.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralises all access to superglobals.
 *
 * Per the project convention, no other class touches `$_POST`/`$_GET`/`$_REQUEST`
 * directly — everything is unslashed and sanitised here, returning typed values.
 * Nonce/capability checks remain the caller's responsibility (they belong with
 * the action being authorised), but raw input never escapes this file.
 *
 * phpcs:disable WordPress.Security.NonceVerification.Recommended -- this layer
 * only sanitises; callers verify nonces before acting on the values.
 */
final class Request {

	public static function has( string $key ): bool {
		return isset( $_REQUEST[ $key ] );
	}

	public static function postText( string $key, string $default = '' ): string {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	public static function getText( string $key, string $default = '' ): string {
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	public static function postInt( string $key, int $default = 0 ): int {
		return isset( $_POST[ $key ] ) ? (int) wp_unslash( $_POST[ $key ] ) : $default;
	}

	public static function getInt( string $key, int $default = 0 ): int {
		return isset( $_GET[ $key ] ) ? (int) wp_unslash( $_GET[ $key ] ) : $default;
	}

	public static function postFloat( string $key, ?float $default = null ): ?float {
		return isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ? (float) wp_unslash( $_POST[ $key ] ) : $default;
	}

	public static function postKey( string $key, string $default = '' ): string {
		return isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	public static function getKey( string $key, string $default = '' ): string {
		return isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : $default;
	}

	public static function postBool( string $key ): bool {
		return ! empty( $_POST[ $key ] );
	}

	/**
	 * Post body allowed through wp_kses_post (rich text).
	 */
	public static function postHtml( string $key, string $default = '' ): string {
		return isset( $_POST[ $key ] ) ? wp_kses_post( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	/**
	 * A flat array of values from POST, each run through $sanitizer.
	 *
	 * @param callable(mixed):mixed $sanitizer Per-value sanitiser.
	 * @return array<int|string,mixed>
	 */
	public static function postArray( string $key, callable $sanitizer ): array {
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		$raw = wp_unslash( $_POST[ $key ] );
		$out = array();
		foreach ( (array) $raw as $k => $value ) {
			$out[ $k ] = is_array( $value ) ? array_map( $sanitizer, $value ) : $sanitizer( $value );
		}

		return $out;
	}

	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}
