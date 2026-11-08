<?php
/**
 * Standalone membership payment summaries, separate from grocery receipts.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

$titles = array(
	'created'   => __( 'Membership payment awaiting verification', 'open-bulk-buying' ),
	'updated'   => __( 'Membership payment updated', 'open-bulk-buying' ),
	'prepaid'   => __( 'Membership payment confirmed', 'open-bulk-buying' ),
	'fulfilled' => __( 'Membership payment confirmed', 'open-bulk-buying' ),
	'cancelled' => __( 'Membership payment claim rejected', 'open-bulk-buying' ),
);
$confirmed = in_array( $event, array( 'prepaid', 'fulfilled' ), true );
$rejected  = 'cancelled' === $event;
/* translators: 1: site name, 2: payment event, 3: payment ID. */
$subject = sprintf( __( '[%1$s] %2$s (#%3$d)', 'open-bulk-buying' ), $site, $titles[ $event ], $order->id );
$user    = get_userdata( $order->user_id );
$details = array(
	__( 'Member', 'open-bulk-buying' ) => $user ? $user->display_name : '',
);
$descriptions = array();
foreach ( $order->get_lines() as $line ) {
	if ( $line->is_membership_fee() && $line->get_quantity() > 0 ) {
		$descriptions[] = $line->product_name;
	}
}
$details[ __( 'Description', 'open-bulk-buying' ) ] = implode( ', ', $descriptions );
if ( $confirmed ) {
	$intro = __( 'Thank you! Your membership payment has been confirmed. Keep this email as your payment receipt.', 'open-bulk-buying' );
	$details[ __( 'Amount paid', 'open-bulk-buying' ) ] = openbb_format_money( $order->paid_amount, $order->paid_currency );
	$details[ __( 'Paid on', 'open-bulk-buying' ) ] = get_date_from_gmt( $order->paid_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
} else {
	$intro = $rejected
		? __( 'Your membership payment claim was not accepted. This email is not a payment receipt. Please contact the co-op to clarify your payment or arrange payment of the membership fee.', 'open-bulk-buying' )
		: __( 'We have recorded your membership payment claim. Staff still need to verify it. This email is not a payment receipt; we will email you when the payment is confirmed.', 'open-bulk-buying' );
	$details[ __( 'Claimed amount', 'open-bulk-buying' ) ] = openbb_format_money( $order->total, $order->currency );
	if ( $order->submitted_at ) {
		$details[ __( 'Submitted', 'open-bulk-buying' ) ] = get_date_from_gmt( $order->submitted_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}
}
if ( '' !== trim( $order->member_note ) ) {
	$details[ __( 'Reference', 'open-bulk-buying' ) ] = $order->member_note;
}
$staff_note = OPENBB_Order_Emails::staff_note( $order );
if ( '' !== $staff_note ) {
	$details[ __( 'Staff note', 'open-bulk-buying' ) ] = $staff_note;
}
$cell = 'border-bottom:1px solid #dddddd;padding:10px;text-align:left;vertical-align:top;';

ob_start();
?>
<!DOCTYPE html>
<html><body style="margin:0;padding:20px;font-family:Arial,sans-serif;color:#222222;">
<div style="max-width:600px;margin:0 auto;">
<h1 style="font-size:24px;"><?php echo esc_html( $titles[ $event ] ); ?></h1>
<p><?php echo esc_html( $site . ' - #' . $order->id ); ?></p>
<p style="line-height:1.6;"><?php echo esc_html( $intro ); ?></p>
<table style="border-collapse:collapse;width:100%;">
	<tbody>
	<?php foreach ( $details as $label => $value ) : ?>
		<tr>
			<th scope="row" style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $label ); ?></th>
			<td style="<?php echo esc_attr( $cell ); ?>"><?php echo nl2br( esc_html( $value ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<p style="line-height:1.6;"><?php echo esc_html( __( 'This email concerns your membership fee only, not a grocery order or confirmation of membership approval.', 'open-bulk-buying' ) ); ?></p>
<?php if ( '' !== $signature ) : ?>
<p style="line-height:1.6;"><?php echo nl2br( esc_html( $signature ) ); ?></p>
<?php endif; ?>
</div>
</body></html>
<?php
return array( 'subject' => $subject, 'message' => ob_get_clean() );
