<?php
/**
 * One order slip. Rendered in the order's locale.
 *
 * @package Open_Bulk_Buying
 *
 * @var array $vars {data: array, site_name: string}
 */

defined( 'ABSPATH' ) || exit;

$openbb_slip = $vars['data'];
?>
<section class="openbb-slip">
	<table class="openbb-slip__header" role="presentation">
		<tr>
			<td>
				<h1 class="openbb-slip__member"><?php echo esc_html( $openbb_slip['member_name'] ); ?></h1>
				<?php
				echo esc_html(
					implode(
						' · ',
						array_filter( array( $openbb_slip['order_number'], $vars['site_name'], $openbb_slip['cycle_name'] ), 'strlen' )
					)
				);
				?>
				<?php if ( '' !== $openbb_slip['pickup_when'] || '' !== $openbb_slip['pickup_where'] ) : ?>
					<br><?php echo esc_html( implode( ' · ', array_filter( array( $openbb_slip['pickup_when'], $openbb_slip['pickup_where'] ), 'strlen' ) ) ); ?>
				<?php endif; ?>
			</td>
			<td class="openbb-slip__payment">
				<?php if ( $openbb_slip['paid'] ) : ?>
					<strong><?php esc_html_e( 'PAID', 'open-bulk-buying' ); ?></strong>
					<br><?php echo esc_html( $openbb_slip['paid_on'] ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Amount due', 'open-bulk-buying' ); ?>
					<br><strong><?php echo esc_html( $openbb_slip['amount_due'] ); ?></strong>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<?php if ( $openbb_slip['items'] ) : ?>
		<table class="openbb-slip__items">
			<thead>
				<tr>
					<th scope="col" class="openbb-slip__product"><?php esc_html_e( 'Product', 'open-bulk-buying' ); ?></th>
					<th scope="col" class="openbb-slip__qty"><?php esc_html_e( 'Quantity', 'open-bulk-buying' ); ?></th>
					<th scope="col" class="openbb-slip__check"><?php esc_html_e( 'Own container', 'open-bulk-buying' ); ?></th>
					<th scope="col" class="openbb-slip__check"><?php esc_html_e( 'Packed', 'open-bulk-buying' ); ?></th>
					<th scope="col" class="openbb-slip__check"><?php esc_html_e( 'Handed out', 'open-bulk-buying' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $openbb_slip['items'] as $openbb_item ) : ?>
					<tr>
						<td class="openbb-slip__product">
							<?php echo esc_html( $openbb_item['name'] ); ?>
							<?php if ( '' !== $openbb_item['brand'] ) : ?>
								<span class="openbb-slip__brand-name"><?php echo esc_html( $openbb_item['brand'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="openbb-slip__qty">
							<?php
							/* translators: 1: quantity, 2: unit, e.g. "500 g". */
							echo esc_html( sprintf( __( '%1$s × %2$s', 'open-bulk-buying' ), $openbb_item['quantity'], $openbb_item['unit'] ) );
							?>
						</td>
						<td class="openbb-slip__check"><?php echo $openbb_item['own_container'] ? esc_html__( 'Yes', 'open-bulk-buying' ) : ''; ?></td>
						<td class="openbb-slip__check">&#9744;</td>
						<td class="openbb-slip__check">&#9744;</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p><?php esc_html_e( 'No products on this order.', 'open-bulk-buying' ); ?></p>
	<?php endif; ?>

	<?php if ( $openbb_slip['extras'] ) : ?>
		<p class="openbb-slip__extras">
			<strong><?php esc_html_e( 'Also on this order:', 'open-bulk-buying' ); ?></strong>
			<?php echo esc_html( implode( ', ', $openbb_slip['extras'] ) ); ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $openbb_slip['member_note'] ) : ?>
		<div class="openbb-slip__note">
			<strong><?php esc_html_e( 'Note from the member:', 'open-bulk-buying' ); ?></strong>
			<?php echo nl2br( esc_html( $openbb_slip['member_note'] ) ); ?>
		</div>
	<?php endif; ?>
</section>
