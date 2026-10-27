<?php
/**
 * Read-only cancellation summary, without the shopping/checkout interaction.
 *
 * @var array $view id, order, cycle_name, cycles.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

$order = $view['order'];
$valid_currency = (bool) preg_match( '/^[A-Z]{3}$/', $order->currency );
?>
<div class="openbb openbb-order-message" id="<?php echo esc_attr( $view['id'] ); ?>">
	<h2 class="openbb-order-message__title"><?php esc_html_e( 'Your order was cancelled', 'open-bulk-buying' ); ?></h2>
	<p><?php echo esc_html( $view['cycle_name'] ); ?></p>
	<p class="openbb-notice openbb-notice--info">
		<?php esc_html_e( 'This order is not awaiting pickup or payment, and its items are no longer reserved. Contact the co-op if you would like it reopened as a draft.', 'open-bulk-buying' ); ?>
	</p>
	<h3>
		<?php
		/* translators: %d: order number. */
		echo esc_html( sprintf( __( 'Cancelled order #%d', 'open-bulk-buying' ), $order->id ) );
		?>
	</h3>
	<ul>
		<?php foreach ( $order->get_lines() as $line ) : ?>
			<?php
			if ( $line->get_quantity() <= 0 ) {
				continue;
			}
			?>
			<li>
				<?php echo esc_html( $line->product_name ); ?>:
				<?php echo esc_html( openbb_format_quantity( $line->get_quantity() ) . ' x ' . $line->get_unit_label() ); ?>
				<?php if ( $valid_currency ) : ?>
					&mdash; <?php echo esc_html( openbb_format_money( $line->calculate_total() - $line->calculate_container_credit(), $order->currency ) ); ?>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<p>
		<strong><?php esc_html_e( 'Cancelled order total (not due):', 'open-bulk-buying' ); ?></strong>
		<?php echo esc_html( $valid_currency ? openbb_format_money( $order->total, $order->currency ) : __( 'Unknown currency', 'open-bulk-buying' ) ); ?>
	</p>
	<?php if ( $view['cycles'] ) : ?>
		<nav aria-label="<?php esc_attr_e( 'Ordering cycles', 'open-bulk-buying' ); ?>">
			<?php foreach ( $view['cycles'] as $cycle ) : ?>
				<a href="<?php echo esc_url( $cycle['url'] ); ?>" <?php echo $cycle['current'] ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $cycle['name'] ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
</div>
