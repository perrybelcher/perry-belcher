<?php
/**
 * Shown when a logged-out user hits a form that requires login.
 *
 * Override by copying to {your-theme}/lodestar/submit-login-required.php.
 *
 * @package Lodestar
 *
 * @var string $login_url Login URL that returns to the current page.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="lodestar lodestar-login-required">
	<p>
		<?php
		printf(
			/* translators: %s: login link. */
			esc_html__( 'Please %s to continue.', 'lodestar' ),
			'<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'log in', 'lodestar' ) . '</a>'
		);
		?>
	</p>
</div>
