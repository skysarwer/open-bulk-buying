<?php
/**
 * One product in the catalog: image, name, brand and supplier, price per selling unit,
 * an optional "More info" disclosure, stock note, quantity stepper and the line total
 * once it's in the basket. A product in several categories is rendered once per section
 * with the same context, so every copy shows the same quantity.
 *
 * @var array $view id, uid (unique per copy), level (heading level), name, meta, image,
 *                  price, unit, charge, description.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

$heading = 'h' . (int) $view['level'];
?>
<article class="openbb-product" aria-labelledby="<?php echo esc_attr( $view['uid'] ); ?>-name" <?php echo wp_interactivity_data_wp_context( array( 'id' => (int) $view['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core. ?> data-wp-class--is-in-basket="state.inBasket" data-wp-class--is-sold-out="state.isSoldOut">
	<div class="openbb-product__media">
		<?php if ( '' !== $view['image'] ) : ?>
			<?php echo $view['image']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- From wp_get_attachment_image(). ?>
		<?php else : ?>
			<span class="openbb-product__placeholder" aria-hidden="true"></span>
		<?php endif; ?>
	</div>

	<div class="openbb-product__body">
		<<?php echo esc_html( $heading ); ?> class="openbb-product__name" id="<?php echo esc_attr( $view['uid'] ); ?>-name"><?php echo esc_html( $view['name'] ); ?></<?php echo esc_html( $heading ); ?>>
		<?php if ( '' !== $view['meta'] ) : ?>
			<p class="openbb-product__meta"><?php echo esc_html( $view['meta'] ); ?></p>
		<?php endif; ?>
		<p class="openbb-product__price">
			<span class="openbb-product__amount"><?php echo esc_html( $view['price'] ); ?></span>
			<span class="openbb-product__unit">/ <?php echo esc_html( $view['unit'] ); ?></span>
		</p>
		<?php if ( '' !== $view['description'] || '' !== $view['charge'] ) : ?>
			<details class="openbb-product__details">
				<summary class="openbb-product__summary"><?php esc_html_e( 'More info', 'open-bulk-buying' ); ?><span class="openbb-sr-only">: <?php echo esc_html( $view['name'] ); ?></span></summary>
				<div class="openbb-product__description">
					<?php echo $view['description']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() in the view. ?>
					<?php if ( '' !== $view['charge'] ) : ?>
						<p class="openbb-product__charge"><?php echo esc_html( $view['charge'] ); ?></p>
					<?php endif; ?>
				</div>
			</details>
		<?php endif; ?>
	</div>

	<div class="openbb-product__actions">
		<p class="openbb-product__stock" data-wp-bind--hidden="!state.stockNote" data-wp-text="state.stockNote"></p>
		<?php echo OPENBB_Frontend::template( 'order-stepper', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>
		<p class="openbb-product__total" data-wp-bind--hidden="!state.inBasket">
			<span class="openbb-sr-only"><?php esc_html_e( 'In your basket:', 'open-bulk-buying' ); ?></span>
			<span data-wp-text="state.lineTotal"></span>
		</p>
	</div>
</article>
