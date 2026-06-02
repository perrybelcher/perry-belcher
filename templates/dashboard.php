<?php
/**
 * User dashboard template — manage your own listings.
 *
 * Override by copying to {your-theme}/lodestar/dashboard.php.
 *
 * @package Lodestar
 *
 * @var array<int,array{title:string,status:string,view_url:string,edit_url:string,delete_form:string}> $rows
 * @var string $notice Notice slug from the redirect.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$lodestar_notices = array(
	'submitted'     => __( 'Your listing was submitted.', 'lodestar' ),
	'saved'         => __( 'Your listing was saved.', 'lodestar' ),
	'deleted'       => __( 'Listing deleted.', 'lodestar' ),
	'delete_failed' => __( 'That listing could not be deleted.', 'lodestar' ),
);
?>
<div class="lodestar lodestar-dashboard">
	<?php if ( isset( $lodestar_notices[ $notice ] ) ) : ?>
		<div class="lodestar-notice"><?php echo esc_html( $lodestar_notices[ $notice ] ); ?></div>
	<?php endif; ?>

	<h2 class="lodestar-dashboard__title"><?php esc_html_e( 'My listings', 'lodestar' ); ?></h2>

	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'You have no listings yet.', 'lodestar' ); ?></p>
	<?php else : ?>
		<table class="lodestar-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'lodestar' ); ?></th>
					<th><?php esc_html_e( 'Status', 'lodestar' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'lodestar' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<?php if ( '' !== $row['view_url'] ) : ?>
								<a href="<?php echo esc_url( $row['view_url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $row['title'] ); ?>
							<?php endif; ?>
						</td>
						<td><span class="lodestar-status lodestar-status--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td>
						<td class="lodestar-actions">
							<a class="lodestar-button" href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'lodestar' ); ?></a>
							<?php echo $row['delete_form']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_*/nonce in the controller. ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
