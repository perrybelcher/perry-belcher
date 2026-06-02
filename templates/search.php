<?php
/**
 * Faceted search template.
 *
 * Override by copying to {your-theme}/lodestar/search.php.
 *
 * @package Lodestar
 *
 * @var string $action_url        Form action (current URL).
 * @var string $facet_controls    Pre-built, escaped facet controls.
 * @var string $location_controls Pre-built location controls.
 * @var string $sort_control      Pre-built sort control.
 * @var bool   $has_geo           Whether a radius search is active.
 * @var string $map_html          Map container markup (may be empty).
 * @var array<int,array{title:string,url:string,distance:?float,rating:?float}> $rows
 * @var int    $total             Total matches.
 * @var string $pagination        Pagination markup.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="lodestar lodestar-search">
	<form class="lodestar-search__form" method="get" action="<?php echo esc_url( $action_url ); ?>">
		<div class="lodestar-search__filters">
			<?php echo $location_controls; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in controller. ?>
			<?php echo $facet_controls; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in controller. ?>
			<div class="lodestar-facet lodestar-facet--sort">
				<span class="lodestar-facet__label"><?php esc_html_e( 'Sort by', 'lodestar' ); ?></span>
				<?php echo $sort_control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in controller. ?>
			</div>
			<button type="submit" class="lodestar-button lodestar-button--primary"><?php esc_html_e( 'Search', 'lodestar' ); ?></button>
		</div>
	</form>

	<?php if ( '' !== $map_html ) : ?>
		<?php echo $map_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped JSON in MapRenderer. ?>
	<?php endif; ?>

	<div class="lodestar-search__results">
		<p class="lodestar-search__count">
			<?php
			printf(
				/* translators: %d: number of results. */
				esc_html( _n( '%d result', '%d results', (int) $total, 'lodestar' ) ),
				(int) $total
			);
			?>
		</p>

		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No listings match your search.', 'lodestar' ); ?></p>
		<?php else : ?>
			<ul class="lodestar-results">
				<?php foreach ( $rows as $row ) : ?>
					<li class="lodestar-result">
						<a class="lodestar-result__title" href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
						<?php if ( $has_geo && null !== $row['distance'] ) : ?>
							<span class="lodestar-result__distance">
								<?php
								printf(
									/* translators: %s: distance in km. */
									esc_html__( '%s km away', 'lodestar' ),
									esc_html( (string) $row['distance'] )
								);
								?>
							</span>
						<?php endif; ?>
						<?php if ( null !== $row['rating'] && $row['rating'] > 0 ) : ?>
							<span class="lodestar-result__rating">★ <?php echo esc_html( number_format_i18n( $row['rating'], 1 ) ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php echo $pagination; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in controller. ?>
		<?php endif; ?>
	</div>
</div>
