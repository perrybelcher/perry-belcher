<?php
/**
 * Listing submission form template.
 *
 * Override by copying to {your-theme}/lodestar/submit-form.php.
 *
 * @package Lodestar
 *
 * @var string   $action_url    Form action (admin-post.php).
 * @var string   $hidden_fields Pre-built, safe hidden inputs (nonce, action, ids).
 * @var string   $title_value   Current title value.
 * @var string   $content_value Current description value.
 * @var string   $fields_html   Pre-built, escaped custom-field controls.
 * @var string   $tags_value    Current tags value.
 * @var string[] $errors        Validation error messages.
 * @var bool     $is_edit       Whether this is an edit.
 * @var string   $submit_label  Submit button label.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="lodestar lodestar-submit">
	<?php if ( ! empty( $errors ) ) : ?>
		<div class="lodestar-errors" role="alert">
			<ul>
				<?php foreach ( $errors as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form class="lodestar-form" method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data">
		<?php echo $hidden_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* in the controller. ?>

		<div class="lodestar-field lodestar-field--text">
			<label class="lodestar-field__label" for="lodestar-listing-title">
				<?php esc_html_e( 'Title', 'lodestar' ); ?>
				<span class="lodestar-required" aria-hidden="true">*</span>
			</label>
			<div class="lodestar-field__control">
				<input type="text" id="lodestar-listing-title" name="listing_title" class="lodestar-input"
					value="<?php echo esc_attr( $title_value ); ?>" required />
			</div>
		</div>

		<div class="lodestar-field lodestar-field--richtext">
			<label class="lodestar-field__label" for="lodestar-listing-content">
				<?php esc_html_e( 'Description', 'lodestar' ); ?>
			</label>
			<div class="lodestar-field__control">
				<textarea id="lodestar-listing-content" name="listing_content" rows="6" class="lodestar-textarea"><?php
					echo esc_textarea( $content_value );
				?></textarea>
			</div>
		</div>

		<?php echo $fields_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by FormBuilder. ?>

		<div class="lodestar-field lodestar-field--submit">
			<button type="submit" class="lodestar-button lodestar-button--primary">
				<?php echo esc_html( $submit_label ); ?>
			</button>
		</div>
	</form>
</div>
