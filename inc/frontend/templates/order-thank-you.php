<?php
/**
 * Member order confirmation, with a receipt from the saved lines and optional polls.
 *
 * @var array $view id, back, notice, order, cycle_name, totals, polls.
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

$receipt = $view['order'];
?>
<section class="openbb openbb-thank-you" id="<?php echo esc_attr( $view['id'] ); ?>">
	<?php echo OPENBB_Frontend::template( 'view-back', $view['back'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>
	<h2><?php esc_html_e( 'Thank you!', 'open-bulk-buying' ); ?></h2>
	<p class="openbb-notice openbb-notice--success"><?php echo esc_html( $view['notice'] ); ?></p>

	<details class="openbb-receipt">
		<summary><?php esc_html_e( 'View order receipt', 'open-bulk-buying' ); ?></summary>
		<table>
			<caption>
				<?php
				/* translators: %d: order number. */
				printf( esc_html__( 'Order #%d', 'open-bulk-buying' ), (int) $receipt->id );
				?>
				&middot; <?php echo esc_html( $view['cycle_name'] ); ?>
				&middot; <?php echo esc_html( OPENBB_Order::status_labels()[ $receipt->status ] ); ?>
			</caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Product', 'open-bulk-buying' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Quantity', 'open-bulk-buying' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Unit price', 'open-bulk-buying' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Line total', 'open-bulk-buying' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $receipt->get_lines() as $line ) : ?>
					<?php if ( $line->get_quantity() > 0 ) : ?>
						<tr>
							<th scope="row">
								<?php echo esc_html( trim( $line->product_name . ' ' . $line->brand ) ); ?>
								<?php if ( ! $line->is_membership_fee() ) : ?>
									<small><?php echo esc_html( $line->get_unit_label() ); ?></small>
								<?php endif; ?>
							</th>
							<td><?php echo esc_html( openbb_format_quantity( $line->get_quantity() ) ); ?></td>
							<td><?php echo esc_html( openbb_format_money( $line->unit_price + $line->container_charge, $receipt->currency ) ); ?></td>
							<td><?php echo esc_html( openbb_format_money( $line->calculate_total(), $receipt->currency ) ); ?></td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<?php foreach ( $view['totals'] as $label => $amount ) : ?>
					<tr><th scope="row" colspan="3"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $amount ); ?></td></tr>
				<?php endforeach; ?>
			</tfoot>
		</table>
		<?php if ( OPENBB_Order::AID_PENDING === $receipt->aid_status ) : ?>
			<p><?php esc_html_e( 'You asked for financial aid. If it is approved, the co-op will tell you how much comes off your total.', 'open-bulk-buying' ); ?></p>
		<?php endif; ?>
	</details>

	<?php if ( $view['polls'] ) : ?>
		<section class="openbb-polls" aria-label="<?php esc_attr_e( 'Co-op polls', 'open-bulk-buying' ); ?>" data-wp-interactive="openbb/polls">
			<p><?php esc_html_e( 'Answering is optional.', 'open-bulk-buying' ); ?></p>
			<?php foreach ( $view['polls'] as $poll ) : ?>
				<?php echo OPENBB_Frontend::template( 'poll', $poll ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template. ?>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>
</section>
