<?php
/**
 * Single-listing details template.
 *
 * Override by copying to {your-theme}/lodestar/single-listing.php.
 *
 * @package Lodestar
 *
 * @var array<int,array{label:string,value:string}> $rows
 * @var array{average:float,count:int}               $rating
 * @var string                                       $faq_html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="lodestar lodestar-single">
	<?php if ( $rating['count'] > 0 ) : ?>
		<p class="lodestar-single__rating">
			★ <?php echo esc_html( number_format_i18n( $rating['average'], 1 ) ); ?>
			<span class="lodestar-single__rating-count">
				<?php
				printf(
					/* translators: %d: number of reviews. */
					esc_html( _n( '(%d review)', '(%d reviews)', $rating['count'], 'lodestar' ) ),
					(int) $rating['count']
				);
				?>
			</span>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $rows ) ) : ?>
		<dl class="lodestar-single__fields">
			<?php foreach ( $rows as $row ) : ?>
				<dt class="lodestar-single__label"><?php echo esc_html( $row['label'] ); ?></dt>
				<dd class="lodestar-single__value"><?php echo esc_html( $row['value'] ); ?></dd>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>

	<?php
	// FaqBlock::render returns escaped markup.
	echo $faq_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</div>
