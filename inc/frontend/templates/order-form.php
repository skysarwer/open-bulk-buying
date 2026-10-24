<?php
/**
 * The order form shell: top bar, category links, catalog and basket panel, and the
 * checkout bar on narrow screens. See OPENBB_Order_Form_View.
 *
 * @var array $view id, welcome, cycle_name, closes, cycles (links when several are open),
 *                  notice (type, text) or null, sections, basket (for order-basket).
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

$basket_id = $view['basket']['id'];
$top_level = array_values(
	array_filter(
		$view['sections'],
		static function ( array $section ): bool {
			return 0 === $section['depth'];
		}
	)
);
?>
<div class="openbb openbb-order alignwide" id="<?php echo esc_attr( $view['id'] ); ?>" data-wp-interactive="openbb/order" data-wp-init="callbacks.init" data-wp-class--has-sidebar="state.sidebarShown">
	<header class="openbb-order__topbar">
		<div class="openbb-order__welcome">
			<h2 class="openbb-order__title"><?php echo esc_html( $view['welcome'] ); ?></h2>
			<p class="openbb-order__cycle">
				<span><?php echo esc_html( $view['cycle_name'] ); ?></span>
				<span class="openbb-order__closes"><?php echo esc_html( $view['closes'] ); ?></span>
			</p>
		</div>
		<button type="button" class="openbb-order__basket-toggle" aria-controls="<?php echo esc_attr( $basket_id ); ?>" data-wp-bind--aria-expanded="state.panelOpen" data-wp-on--click="actions.togglePanel">
			<svg class="openbb-order__basket-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="24" height="24"><path d="M3 9h18l-2 11H5L3 9zm5 0l4-6 4 6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" fill="none"/></svg>
			<span class="openbb-order__basket-label"><?php esc_html_e( 'Basket', 'open-bulk-buying' ); ?></span>
			<span class="openbb-order__basket-count" data-wp-text="state.count"></span>
			<span class="openbb-sr-only"><?php esc_html_e( 'items,', 'open-bulk-buying' ); ?></span>
			<span class="openbb-order__basket-total" data-wp-text="state.totalText"></span>
		</button>
	</header>

	<?php if ( $view['cycles'] ) : ?>
		<nav class="openbb-order__cycles" aria-label="<?php esc_attr_e( 'Ordering periods', 'open-bulk-buying' ); ?>">
			<ul>
				<?php foreach ( $view['cycles'] as $cycle ) : ?>
					<li><a href="<?php echo esc_url( $cycle['url'] ); ?>"<?php echo $cycle['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $cycle['name'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>

	<div class="openbb-order__layout">
		<div class="openbb-order__main">
			<?php if ( $view['notice'] ) : ?>
				<p class="openbb-notice openbb-notice--<?php echo esc_attr( $view['notice']['type'] ); ?>"><?php echo esc_html( $view['notice']['text'] ); ?></p>
			<?php endif; ?>

			<?php if ( ! $view['sections'] ) : ?>
				<p class="openbb-notice"><?php esc_html_e( 'There are no products to order yet.', 'open-bulk-buying' ); ?></p>
			<?php endif; ?>

			<?php if ( count( $top_level ) > 1 ) : ?>
				<nav class="openbb-catalog-nav" aria-label="<?php esc_attr_e( 'Product categories', 'open-bulk-buying' ); ?>">
					<ul class="openbb-catalog-nav__list">
						<?php foreach ( $top_level as $section ) : ?>
							<li><a class="openbb-catalog-nav__link" href="#<?php echo esc_attr( $section['anchor'] ); ?>"><?php echo esc_html( $section['name'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>

			<div class="openbb-catalog">
				<?php foreach ( $view['sections'] as $section ) : ?>
					<?php $heading = 'h' . (int) $section['level']; ?>
					<section class="openbb-catalog__section openbb-catalog__section--depth-<?php echo (int) min( 2, $section['depth'] ); ?>" aria-labelledby="<?php echo esc_attr( $section['anchor'] ); ?>">
						<<?php echo esc_html( $heading ); ?> class="openbb-catalog__heading" id="<?php echo esc_attr( $section['anchor'] ); ?>" tabindex="-1"><?php echo esc_html( $section['name'] ); ?></<?php echo esc_html( $heading ); ?>>
						<?php if ( $section['products'] ) : ?>
							<div class="openbb-catalog__grid">
								<?php foreach ( $section['products'] as $product ) : ?>
									<?php echo OPENBB_Frontend::template( 'order-product', $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			</div>
		</div>

		<?php echo OPENBB_Frontend::template( 'order-basket', $view['basket'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>
	</div>

	<div class="openbb-checkout-bar" data-wp-bind--hidden="!state.hasItems">
		<p class="openbb-checkout-bar__summary">
			<span data-wp-text="state.countLabel"></span>
			<strong data-wp-text="state.totalText"></strong>
		</p>
		<button type="button" class="openbb-button openbb-button--primary openbb-checkout-bar__button" aria-controls="<?php echo esc_attr( $basket_id ); ?>" data-wp-on--click="actions.openPanel">
			<?php echo esc_html( $view['basket']['submitted'] ? __( 'Review order', 'open-bulk-buying' ) : __( 'Finalize order', 'open-bulk-buying' ) ); ?>
		</button>
	</div>

	<p class="openbb-sr-only" aria-live="polite" data-wp-text="state.announcement"></p>
</div>
