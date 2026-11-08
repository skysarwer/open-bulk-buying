<?php
/**
 * Photo-free order receipt with an optional change summary. All stored content is escaped.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

$order   = $data['order'];
$event   = $data['event'];
$changes = $data['changes'];
if ( ! $order->cycle_id ) {
	return require __DIR__ . '/membership-event.php';
}
$titles  = array(
	'created'   => __( 'Order created', 'open-bulk-buying' ),
	'updated'   => __( 'Order updated', 'open-bulk-buying' ),
	'prepaid'   => __( 'Order marked as prepaid', 'open-bulk-buying' ),
	'cancelled' => __( 'Order cancelled', 'open-bulk-buying' ),
	'fulfilled' => __( 'Order fulfilled', 'open-bulk-buying' ),
);
/* translators: 1: site name, 2: order event, 3: order ID. */
$subject = sprintf( __( '[%1$s] %2$s (#%3$d)', 'open-bulk-buying' ), $site, $titles[ $event ], $order->id );
$cycle   = OPENBB_Cycles::get( $order->cycle_id );
$cell    = 'border:1px solid #cccccc;padding:8px;text-align:left;vertical-align:top;';

ob_start();
?>
<!DOCTYPE html>
<html><body>
<h1><?php echo esc_html( $titles[ $event ] ); ?></h1>
<p><?php echo esc_html( $site . ' - #' . $order->id ); ?><br>
<?php echo esc_html( $cycle ? $cycle->name : '' ); ?><br>
<?php echo esc_html( get_date_from_gmt( $order->updated_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?><br>
<?php echo esc_html( $order->currency ); ?></p>
<?php if ( 'updated' === $event && $changes ) : ?>
	<?php foreach ( $changes as list( $label, $from, $to ) ) : ?>
		<?php
		$added   = '' === $from;
		$removed = '' === $to;
		$label   = '<b>' . nl2br( esc_html( $label ) ) . '</b>';
		$from    = '<b>' . nl2br( esc_html( $from ) ) . '</b>';
		$to      = '<b>' . nl2br( esc_html( $to ) ) . '</b>';
		if ( $added ) {
			/* translators: 1: change name, 2: added value. Values are bold. */
			$summary = sprintf( esc_html__( '%1$s added: %2$s.', 'open-bulk-buying' ), $label, $to );
		} elseif ( $removed ) {
			/* translators: 1: change name, 2: removed value. Values are bold. */
			$summary = sprintf( esc_html__( '%1$s removed: %2$s.', 'open-bulk-buying' ), $label, $from );
		} else {
			/* translators: 1: change name, 2: old value, 3: new value. Values are bold. */
			$summary = sprintf( esc_html__( '%1$s changed from %2$s to %3$s.', 'open-bulk-buying' ), $label, $from, $to );
		}
		?>
		<p><?php echo $summary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Values are escaped above. ?></p>
	<?php endforeach; ?>
<?php endif; ?>
<table style="border-collapse:collapse;width:100%;">
	<thead><tr>
	<?php foreach ( array( __( 'Product', 'open-bulk-buying' ), __( 'Package', 'open-bulk-buying' ), __( 'Quantity', 'open-bulk-buying' ), __( 'Unit price', 'open-bulk-buying' ), __( 'Own container', 'open-bulk-buying' ), __( 'Container credit', 'open-bulk-buying' ), __( 'Line total', 'open-bulk-buying' ) ) as $label ) : ?>
		<th scope="col" style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $label ); ?></th>
	<?php endforeach; ?>
	</tr></thead>
	<tbody>
	<?php foreach ( $order->get_lines() as $line ) : ?>
		<?php if ( $line->get_quantity() <= 0 ) { continue; } ?>
		<tr>
		<?php
		$row = array(
			trim( $line->product_name . ' ' . $line->brand ),
			$line->get_unit_label(),
			number_format_i18n( $line->get_quantity(), floor( $line->get_quantity() ) === $line->get_quantity() ? 0 : 3 ),
			openbb_format_money( $line->unit_price + $line->container_charge, $order->currency ),
			$line->own_container ? __( 'Yes', 'open-bulk-buying' ) : __( 'No', 'open-bulk-buying' ),
			openbb_format_money( -$line->calculate_container_credit(), $order->currency ),
			openbb_format_money( $line->calculate_total() - $line->calculate_container_credit(), $order->currency ),
		);
		?>
		<?php foreach ( $row as $value ) : ?><td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $value ); ?></td><?php endforeach; ?>
		</tr>
	<?php endforeach; ?>
	</tbody>
	<tfoot>
	<?php foreach ( OPENBB_Order_Emails::totals( $order ) as $label => $value ) : ?>
		<tr><th colspan="6" scope="row" style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $label ); ?></th><td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $value ); ?></td></tr>
	<?php endforeach; ?>
	</tfoot>
</table>
<?php $staff_note = OPENBB_Order_Emails::staff_note( $order ); ?>
<?php if ( '' !== $staff_note ) : ?>
<h2><?php echo esc_html( __( 'Staff note', 'open-bulk-buying' ) ); ?></h2>
<p><?php echo nl2br( esc_html( $staff_note ) ); ?></p>
<?php endif; ?>
<?php if ( '' !== $signature ) : ?>
<p><?php echo nl2br( esc_html( $signature ) ); ?></p>
<?php endif; ?>
</body></html>
<?php
return array( 'subject' => $subject, 'message' => ob_get_clean() );
