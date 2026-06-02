<?php
/**
 * Per-field-type input sanitisation.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Frontend;

use Lodestar\DirectoryType\FieldDefinition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitises a raw custom-field value according to its {@see FieldDefinition}.
 *
 * This is the front line for public submissions: every value is coerced to a
 * safe shape for its declared type. select/multiselect values are whitelisted
 * against the field's own options, so a forged option cannot slip through.
 * Output is never trusted later — escaping still happens at render — but storing
 * clean data is the first line of defence (defence in depth).
 */
final class FieldSanitizer {

	/**
	 * Sanitise a raw value for a field.
	 *
	 * @param FieldDefinition $field The field definition.
	 * @param mixed           $raw   Raw (unslashed) submitted value.
	 * @return mixed Clean value (string|float|array|null) suitable for storage.
	 */
	public static function sanitize( FieldDefinition $field, $raw ) {
		switch ( $field->inputType ) {
			case FieldDefinition::INPUT_NUMBER:
				return ( null === $raw || '' === trim( (string) $raw ) ) ? null : (float) $raw;

			case FieldDefinition::INPUT_URL:
				return esc_url_raw( trim( (string) $raw ) );

			case FieldDefinition::INPUT_TEL:
				return sanitize_text_field( (string) $raw );

			case FieldDefinition::INPUT_DATE:
				return self::date( (string) $raw );

			case FieldDefinition::INPUT_CHECKBOX:
				return empty( $raw ) ? '0' : '1';

			case FieldDefinition::INPUT_RICHTEXT:
				return wp_kses_post( (string) $raw );

			case FieldDefinition::INPUT_SELECT:
				return self::whitelist( $field, (string) sanitize_text_field( (string) $raw ) );

			case FieldDefinition::INPUT_MULTISELECT:
				$values = array();
				foreach ( (array) $raw as $candidate ) {
					$clean = self::whitelist( $field, sanitize_text_field( (string) $candidate ) );
					if ( '' !== $clean ) {
						$values[] = $clean;
					}
				}
				return array_values( array_unique( $values ) );

			case FieldDefinition::INPUT_GEO:
				$lat = is_array( $raw ) && isset( $raw['lat'] ) && '' !== $raw['lat'] ? (float) $raw['lat'] : null;
				$lng = is_array( $raw ) && isset( $raw['lng'] ) && '' !== $raw['lng'] ? (float) $raw['lng'] : null;
				if ( null === $lat || null === $lng || abs( $lat ) > 90 || abs( $lng ) > 180 ) {
					return null;
				}
				return array( 'lat' => $lat, 'lng' => $lng );

			case FieldDefinition::INPUT_FILE:
				// Uploads are handled separately (Phase 3+); ignore raw text here.
				return null;

			case FieldDefinition::INPUT_TEXT:
			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * Whether a sanitised value counts as "empty" for required validation.
	 *
	 * @param mixed $value Sanitised value.
	 */
	public static function isEmpty( $value ): bool {
		if ( is_array( $value ) ) {
			return empty( $value );
		}

		return null === $value || '' === (string) $value;
	}

	/**
	 * Constrain a value to the field's declared options.
	 *
	 * @param FieldDefinition $field The field.
	 * @param string          $value Candidate value.
	 * @return string The value if allowed, otherwise ''.
	 */
	private static function whitelist( FieldDefinition $field, string $value ): string {
		if ( empty( $field->options ) ) {
			return $value;
		}

		$allowed = array();
		foreach ( $field->options as $key => $label ) {
			$allowed[] = is_int( $key ) ? (string) $label : (string) $key;
		}

		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Validate/normalise a YYYY-MM-DD date string.
	 *
	 * @param string $value Candidate date.
	 */
	private static function date( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		$ts = strtotime( $value );

		return false === $ts ? null : gmdate( 'Y-m-d', $ts );
	}
}
