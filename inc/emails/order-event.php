<?php
/**
 * Photo-free order receipt or change-only summary. All stored content is escaped.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

$order   = $data['order'];
$event   = $data['event'];
$changes = $data['changes'];
$titles  = array(
	'created'   => __( 'Order created', 'open-bulk-buying' ),
	'updated'   => __( 'Order updated', 'open-bulk-buying' ),
	'prepaid'   => __( 'Order marked as prepaid', 'open-bulk-buying' ),
	'cancelled' => __( 'Order cancelled', 'open-bulk-buying' ),
	'fulfilled' => __( 'Order fulfilled', 'open-bulk-buying' ),
);
if ( ! $order->cycle_id ) {
	$titles['created'] = __( 'Membership payment awaiting verification', 'open-bulk-buying' );
	$titles['fulfilled'] = __( 'Membership payment confirmed', 'open-bulk-buying' );
	$titles['cancelled'] = __( 'Membership payment claim rejected', 'open-bulk-buying' );
}
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
<table style="border-collapse:collapse;width:100%;">
<?php if ( 'updated' === $event ) : ?>
	<thead><tr>
	<?php foreach ( array( __( 'Change', 'open-bulk-buying' ), __( 'Before', 'open-bulk-buying' ), __( 'After', 'open-bulk-buying' ) ) as $label ) : ?>
		<th scope="col" style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $label ); ?></th>
	<?php endforeach; ?>
	</tr></thead>
	<tbody>
	<?php foreach ( $changes as $row ) : ?>
		<tr><?php foreach ( $row as $value ) : ?><td style="<?php echo esc_attr( $cell ); ?>"><?php echo nl2br( esc_html( $value ) ); ?></td><?php endforeach; ?></tr>
	<?php endforeach; ?>
	</tbody>
<?php else : ?>
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
<?php endif; ?>
</table>
</body></html>
<?php
return array( 'subject' => $subject, 'message' => ob_get_clean() );
