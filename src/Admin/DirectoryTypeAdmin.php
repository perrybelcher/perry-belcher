<?php
/**
 * Admin screen for managing directory types and their fields.
 *
 * @package Lodestar
 */

declare(strict_types=1);

namespace Lodestar\Admin;

use Lodestar\DirectoryType\DirectoryTypeManager;
use Lodestar\DirectoryType\FieldDefinition;
use Lodestar\DirectoryType\FieldManager;
use Lodestar\Support\Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists directory types, lets an admin create/delete them, and manage the
 * fields on a selected type.
 *
 * Every write path is gated: capability check + nonce verification + sanitised
 * input (via {@see Request}). Reads escape on output. POST is handled on
 * `admin_init` (Post/Redirect/Get), keeping the render method read-only.
 */
final class DirectoryTypeAdmin {

	private const NONCE = 'lodestar_dir_admin';

	public function __construct(
		private DirectoryTypeManager $types,
		private FieldManager $fields,
	) {}

	/**
	 * Handle form submissions on `admin_init`, then redirect.
	 */
	public function handle_post(): void {
		$action = Request::postKey( 'lodestar_action' );
		if ( '' === $action ) {
			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'lodestar' ) );
		}
		check_admin_referer( self::NONCE );

		$redirect = array( 'page' => AdminMenu::TYPES_SLUG );

		switch ( $action ) {
			case 'create_type':
				$id = $this->types->create(
					array(
						'slug'           => Request::postKey( 'slug' ),
						'label'          => Request::postText( 'label' ),
						'singular_label' => Request::postText( 'singular_label' ),
					)
				);
				$redirect['lodestar_notice'] = $id ? 'type_created' : 'type_error';
				if ( $id ) {
					$redirect['lodestar_type'] = $id;
				}
				break;

			case 'delete_type':
				$this->types->delete( Request::postInt( 'type_id' ) );
				$redirect['lodestar_notice'] = 'type_deleted';
				break;

			case 'create_field':
				$type_id = Request::postInt( 'directory_type_id' );
				$ok      = $this->fields->create(
					array(
						'directory_type_id' => $type_id,
						'field_key'         => Request::postKey( 'field_key' ),
						'label'             => Request::postText( 'label' ),
						'input_type'        => Request::postKey( 'input_type' ),
						'is_facetable'      => Request::postBool( 'is_facetable' ),
						'is_required'       => Request::postBool( 'is_required' ),
						'options'           => $this->parse_options( Request::postText( 'options' ) ),
						'schema_property'   => Request::postText( 'schema_property' ),
					)
				);
				$redirect['lodestar_type']   = $type_id;
				$redirect['lodestar_notice'] = $ok ? 'field_created' : 'field_error';
				break;

			case 'delete_field':
				$field = $this->fields->find( Request::postInt( 'field_id' ) );
				if ( $field ) {
					$this->fields->delete( $field->id );
					$redirect['lodestar_type'] = $field->directoryTypeId;
				}
				$redirect['lodestar_notice'] = 'field_deleted';
				break;
		}

		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the admin screen (read-only).
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Directory Types', 'lodestar' ) . '</h1>';
		$this->notice();

		$type_id = Request::getInt( 'lodestar_type' );
		$type    = $type_id ? $this->types->find( $type_id ) : null;

		if ( $type ) {
			$this->render_type_detail( $type_id, $type->label );
		} else {
			$this->render_type_list();
		}

		echo '</div>';
	}

	/**
	 * The list of types plus a create form.
	 */
	private function render_type_list(): void {
		$types = $this->types->all();

		echo '<h2>' . esc_html__( 'All types', 'lodestar' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Label', 'lodestar' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'lodestar' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'lodestar' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $types ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No directory types yet.', 'lodestar' ) . '</td></tr>';
		}

		foreach ( $types as $type ) {
			$edit_url = add_query_arg(
				array( 'page' => AdminMenu::TYPES_SLUG, 'lodestar_type' => $type->id ),
				admin_url( 'admin.php' )
			);
			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html( $type->label ) . '</a></td>';
			echo '<td><code>' . esc_html( $type->slug ) . '</code></td>';
			echo '<td>';
			$this->action_form( 'delete_type', array( 'type_id' => $type->id ), __( 'Delete', 'lodestar' ), true );
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Add a directory type', 'lodestar' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="page" value="' . esc_attr( AdminMenu::TYPES_SLUG ) . '" />';
		echo '<input type="hidden" name="lodestar_action" value="create_type" />';
		echo '<table class="form-table"><tbody>';
		$this->text_row( 'label', __( 'Label (plural)', 'lodestar' ) );
		$this->text_row( 'singular_label', __( 'Label (singular)', 'lodestar' ) );
		$this->text_row( 'slug', __( 'Slug', 'lodestar' ) );
		echo '</tbody></table>';
		submit_button( __( 'Create type', 'lodestar' ) );
		echo '</form>';
	}

	/**
	 * The fields of one type plus an add-field form.
	 *
	 * @param int    $type_id Directory type ID.
	 * @param string $label   Type label (already from DB).
	 */
	private function render_type_detail( int $type_id, string $label ): void {
		$back = add_query_arg( array( 'page' => AdminMenu::TYPES_SLUG ), admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'All types', 'lodestar' ) . '</a></p>';
		/* translators: %s: directory type label. */
		echo '<h2>' . esc_html( sprintf( __( 'Fields for “%s”', 'lodestar' ), $label ) ) . '</h2>';

		$fields = $this->fields->forType( $type_id );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Label', 'lodestar' ) . '</th>';
		echo '<th>' . esc_html__( 'Key', 'lodestar' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'lodestar' ) . '</th>';
		echo '<th>' . esc_html__( 'Facetable', 'lodestar' ) . '</th>';
		echo '<th>' . esc_html__( 'Required', 'lodestar' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'lodestar' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $fields ) ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No fields yet.', 'lodestar' ) . '</td></tr>';
		}

		foreach ( $fields as $field ) {
			echo '<tr>';
			echo '<td>' . esc_html( $field->label ) . '</td>';
			echo '<td><code>' . esc_html( $field->fieldKey ) . '</code></td>';
			echo '<td>' . esc_html( $field->inputType ) . '</td>';
			echo '<td>' . ( $field->isFacetable ? esc_html__( 'Yes', 'lodestar' ) : '—' ) . '</td>';
			echo '<td>' . ( $field->isRequired ? esc_html__( 'Yes', 'lodestar' ) : '—' ) . '</td>';
			echo '<td>';
			$this->action_form( 'delete_field', array( 'field_id' => $field->id ), __( 'Delete', 'lodestar' ), true );
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Add a field', 'lodestar' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="page" value="' . esc_attr( AdminMenu::TYPES_SLUG ) . '" />';
		echo '<input type="hidden" name="lodestar_action" value="create_field" />';
		echo '<input type="hidden" name="directory_type_id" value="' . esc_attr( (string) $type_id ) . '" />';
		echo '<table class="form-table"><tbody>';
		$this->text_row( 'label', __( 'Label', 'lodestar' ) );
		$this->text_row( 'field_key', __( 'Key (a-z, 0-9, _)', 'lodestar' ) );

		echo '<tr><th scope="row"><label for="lodestar-input-type">' . esc_html__( 'Input type', 'lodestar' ) . '</label></th><td>';
		echo '<select name="input_type" id="lodestar-input-type">';
		foreach ( FieldDefinition::inputTypes() as $input_type ) {
			echo '<option value="' . esc_attr( $input_type ) . '">' . esc_html( $input_type ) . '</option>';
		}
		echo '</select></td></tr>';

		$this->text_row( 'options', __( 'Options (comma-separated, for select)', 'lodestar' ) );
		$this->text_row( 'schema_property', __( 'schema.org property', 'lodestar' ) );

		echo '<tr><th scope="row">' . esc_html__( 'Flags', 'lodestar' ) . '</th><td>';
		echo '<label><input type="checkbox" name="is_facetable" value="1" /> ' . esc_html__( 'Facetable (searchable)', 'lodestar' ) . '</label><br />';
		echo '<label><input type="checkbox" name="is_required" value="1" /> ' . esc_html__( 'Required', 'lodestar' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Add field', 'lodestar' ) );
		echo '</form>';
	}

	/**
	 * Render a labelled text input row inside a form-table.
	 *
	 * @param string $name  Input name.
	 * @param string $label Field label.
	 */
	private function text_row( string $name, string $label ): void {
		printf(
			'<tr><th scope="row"><label for="lodestar-%1$s">%2$s</label></th><td>' .
			'<input type="text" class="regular-text" id="lodestar-%1$s" name="%1$s" /></td></tr>',
			esc_attr( $name ),
			esc_html( $label )
		);
	}

	/**
	 * Render a tiny inline POST form for a single action (e.g. delete).
	 *
	 * @param string               $action  Action key.
	 * @param array<string,scalar> $hidden  Hidden field values.
	 * @param string               $label   Button label.
	 * @param bool                 $confirm Whether to confirm via JS.
	 */
	private function action_form( string $action, array $hidden, string $label, bool $confirm = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="display:inline">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="page" value="' . esc_attr( AdminMenu::TYPES_SLUG ) . '" />';
		echo '<input type="hidden" name="lodestar_action" value="' . esc_attr( $action ) . '" />';
		foreach ( $hidden as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		$onclick = $confirm ? ' onclick="return confirm(\'' . esc_js( __( 'Are you sure?', 'lodestar' ) ) . '\')"' : '';
		echo '<button type="submit" class="button-link delete"' . $onclick . '>' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Parse a comma-separated option string into a clean list.
	 *
	 * @param string $raw Comma-separated options.
	 * @return string[]
	 */
	private function parse_options( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * Render an admin notice based on the redirect query arg.
	 */
	private function notice(): void {
		$notice = Request::getKey( 'lodestar_notice' );
		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'type_created'  => array( 'success', __( 'Directory type created.', 'lodestar' ) ),
			'type_deleted'  => array( 'success', __( 'Directory type deleted.', 'lodestar' ) ),
			'type_error'    => array( 'error', __( 'Could not create the type (check the slug is unique).', 'lodestar' ) ),
			'field_created' => array( 'success', __( 'Field added.', 'lodestar' ) ),
			'field_deleted' => array( 'success', __( 'Field deleted.', 'lodestar' ) ),
			'field_error'   => array( 'error', __( 'Could not add the field (check the key is unique).', 'lodestar' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		[ $level, $text ] = $messages[ $notice ];
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $level ),
			esc_html( $text )
		);
	}
}
