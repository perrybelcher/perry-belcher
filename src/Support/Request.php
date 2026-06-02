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

	/**
	 * A GET value that may be a single string or an array of strings, each
	 * sanitised with sanitize_text_field. Returns null when absent.
	 *
	 * @return string|string[]|null
	 */
	public static function getTextOrArray( string $key ) {
		if ( ! isset( $_GET[ $key ] ) ) {
			return null;
		}

		$value = wp_unslash( $_GET[ $key ] );
		if ( is_array( $value ) ) {
			return array_values( array_map( 'sanitize_text_field', $value ) );
		}

		return sanitize_text_field( $value );
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

	/**
	 * Array of absolute integers from POST (e.g. taxonomy term IDs).
	 *
	 * @return int[]
	 */
	public static function postIntArray( string $key ): array {
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST[ $key ] ) ) ) );
	}

	/**
	 * Unslashed (but NOT type-sanitised) array from POST.
	 *
	 * The only escape hatch in this layer: the caller MUST sanitise each value
	 * itself, by field type, before use (see Frontend\FieldSanitizer). Used for
	 * dynamic custom-field bundles whose sanitisation rule depends on the field
	 * definition, which this generic layer cannot know.
	 *
	 * @return array<string,mixed>
	 */
	public static function rawPostArray( string $key ): array {
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- caller sanitises per field type.
		return (array) wp_unslash( $_POST[ $key ] );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}
