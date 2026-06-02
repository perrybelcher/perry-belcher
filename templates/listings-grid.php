<?php
/**
 * Listings grid template.
 *
 * Override by copying to {your-theme}/lodestar/listings-grid.php.
 *
 * @package Lodestar
 *
 * @var array<int,array{title:string,url:string,rating:float,featured:bool,thumbnail:string}> $cards
 * @var int $total
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="lodestar lodestar-grid">
	<?php if ( empty( $cards ) ) : ?>
		<p><?php esc_html_e( 'No listings yet.', 'lodestar' ); ?></p>
	<?php else : ?>
		<ul class="lodestar-grid__list">
			<?php foreach ( $cards as $card ) : ?>
				<li class="lodestar-card<?php echo $card['featured'] ? ' lodestar-card--featured' : ''; ?>">
					<a class="lodestar-card__link" href="<?php echo esc_url( $card['url'] ); ?>">
						<?php if ( '' !== $card['thumbnail'] ) : ?>
							<img class="lodestar-card__image" src="<?php echo esc_url( $card['thumbnail'] ); ?>" alt="" loading="lazy" />
						<?php endif; ?>
						<span class="lodestar-card__title"><?php echo esc_html( $card['title'] ); ?></span>
						<?php if ( $card['rating'] > 0 ) : ?>
							<span class="lodestar-card__rating">★ <?php echo esc_html( number_format_i18n( $card['rating'], 1 ) ); ?></span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
