<?php
/**
 * Dynamic form renderer for a directory type's fields.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\DirectoryType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the custom-field portion of a listing form from its
 * {@see FieldDefinition}s. Pure presentation — all output is escaped and no
 * business logic lives here. The submission handler (Phase 3) consumes the
 * `lodestar_fields[<key>]` inputs this produces.
 *
 * Markup is intentionally scoped (every wrapper carries a `lodestar-` class)
 * and uses no inline styles, so it never fights the active theme.
 */
final class FormBuilder {

	/** Input name prefix for custom fields. */
	private const NAME = 'lodestar_fields';

	/**
	 * @param FieldDefinition[] $fields Fields to render (in display order).
	 */
	public function __construct( private array $fields ) {}

	/**
	 * Render all fields. Optionally seed with existing values (edit mode).
	 *
	 * @param array<string,mixed> $values Existing field_key => value(s).
	 */
	public function renderFields( array $values = array() ): string {
		$html = '';
		foreach ( $this->fields as $field ) {
			$html .= $this->renderField( $field, $values[ $field->fieldKey ] ?? null );
		}

		return $html;
	}

	/**
	 * Render a single field wrapped in its control group.
	 *
	 * @param FieldDefinition $field The field.
	 * @param mixed           $value Current value (string|array|null).
	 */
	public function renderField( FieldDefinition $field, $value = null ): string {
		$id       = 'lodestar-field-' . $field->fieldKey;
		$required = $field->isRequired ? ' required' : '';
		$req_mark = $field->isRequired ? ' <span class="lodestar-required" aria-hidden="true">*</span>' : '';

		$control = $this->control( $field, $id, $value, $required );

		return sprintf(
			'<div class="lodestar-field lodestar-field--%1$s">' .
			'<label class="lodestar-field__label" for="%2$s">%3$s%4$s</label>' .
			'<div class="lodestar-field__control">%5$s</div>' .
			'</div>',
			esc_attr( $field->inputType ),
			esc_attr( $id ),
			esc_html( $field->label ),
			$req_mark,
			$control
		);
	}

	/**
	 * Render the input control for a field.
	 *
	 * @param FieldDefinition $field    The field.
	 * @param string          $id       Element ID.
	 * @param mixed           $value    Current value.
	 * @param string          $required ' required' or ''.
	 */
	private function control( FieldDefinition $field, string $id, $value, string $required ): string {
		$key  = $field->fieldKey;
		$name = self::NAME . '[' . $key . ']';

		switch ( $field->inputType ) {
			case FieldDefinition::INPUT_NUMBER:
				return $this->input( 'number', $id, $name, (string) ( $value ?? '' ), $required );

			case FieldDefinition::INPUT_DATE:
				return $this->input( 'date', $id, $name, (string) ( $value ?? '' ), $required );

			case FieldDefinition::INPUT_URL:
				return $this->input( 'url', $id, $name, (string) ( $value ?? '' ), $required );

			case FieldDefinition::INPUT_TEL:
				return $this->input( 'tel', $id, $name, (string) ( $value ?? '' ), $required );

			case FieldDefinition::INPUT_CHECKBOX:
				return sprintf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					$required
				);

			case FieldDefinition::INPUT_RICHTEXT:
				return sprintf(
					'<textarea id="%1$s" name="%2$s" rows="6" class="lodestar-textarea"%3$s>%4$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					$required,
					esc_textarea( (string) ( $value ?? '' ) )
				);

			case FieldDefinition::INPUT_FILE:
				return sprintf(
					'<input type="file" id="%1$s" name="%2$s"%3$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					$required
				);

			case FieldDefinition::INPUT_GEO:
				return $this->geo( $key, $value );

			case FieldDefinition::INPUT_SELECT:
				return $this->select( $field, $id, $name, $value, false, $required );

			case FieldDefinition::INPUT_MULTISELECT:
				return $this->select( $field, $id, $name . '[]', $value, true, $required );

			case FieldDefinition::INPUT_TEXT:
			default:
				return $this->input( 'text', $id, $name, (string) ( $value ?? '' ), $required );
		}
	}

	/**
	 * Render a basic text-style input.
	 */
	private function input( string $type, string $id, string $name, string $value, string $required ): string {
		return sprintf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="lodestar-input"%5$s />',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value ),
			$required
		);
	}

	/**
	 * Render a <select> (single or multiple).
	 *
	 * @param FieldDefinition $field    The field.
	 * @param string          $id       Element ID.
	 * @param string          $name     Input name.
	 * @param mixed           $value    Current value(s).
	 * @param bool            $multiple Whether multiple selection is allowed.
	 * @param string          $required ' required' or ''.
	 */
	private function select( FieldDefinition $field, string $id, string $name, $value, bool $multiple, string $required ): string {
		$selected = array_map( 'strval', (array) $value );

		$options = '';
		foreach ( $field->options as $opt_key => $opt_label ) {
			// Support both ['a','b'] and ['a' => 'Label A'] shapes.
			$opt_value = is_int( $opt_key ) ? (string) $opt_label : (string) $opt_key;
			$options  .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $opt_value ),
				in_array( $opt_value, $selected, true ) ? ' selected' : '',
				esc_html( (string) $opt_label )
			);
		}

		return sprintf(
			'<select id="%1$s" name="%2$s" class="lodestar-select"%3$s%4$s>%5$s</select>',
			esc_attr( $id ),
			esc_attr( $name ),
			$multiple ? ' multiple' : '',
			$required,
			$options
		);
	}

	/**
	 * Render paired lat/lng inputs for a geo field.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value ['lat' => .., 'lng' => ..] or null.
	 */
	private function geo( string $key, $value ): string {
		$lat = is_array( $value ) ? (string) ( $value['lat'] ?? '' ) : '';
		$lng = is_array( $value ) ? (string) ( $value['lng'] ?? '' ) : '';

		return sprintf(
			'<input type="text" inputmode="decimal" name="%1$s[lat]" value="%2$s" class="lodestar-input lodestar-geo-lat" placeholder="%3$s" /> ' .
			'<input type="text" inputmode="decimal" name="%1$s[lng]" value="%4$s" class="lodestar-input lodestar-geo-lng" placeholder="%5$s" />',
			esc_attr( self::NAME . '[' . $key . ']' ),
			esc_attr( $lat ),
			esc_attr__( 'Latitude', 'lodestar' ),
			esc_attr( $lng ),
			esc_attr__( 'Longitude', 'lodestar' )
		);
	}
}
