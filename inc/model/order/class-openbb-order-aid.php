<?php
/**
 * Aid eligibility decisions only. Allocation and order amounts are unchanged.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_Order_Aid {

	public static function can_decide( OPENBB_Order $order, ?OPENBB_Cycle $cycle ): bool {
		return $cycle && OPENBB_Cycle::STATUS_COMPLETE !== $cycle->status
			&& OPENBB_Order::STATUS_SUBMITTED === $order->status
			&& ! $order->paid_at && ! $order->is_collected()
			&& 0 === $order->aid_approved
			&& in_array( $order->aid_status, array( OPENBB_Order::AID_PENDING, OPENBB_Order::AID_APPROVED, OPENBB_Order::AID_DECLINED ), true );
	}

	/** @return OPENBB_Order|WP_Error */
	public static function decide( int $cycle_id, int $order_id, string $decision, int $revision, int $actor_id ) {
		global $wpdb;

		if ( ! in_array( $decision, array( OPENBB_Order::AID_APPROVED, OPENBB_Order::AID_DECLINED ), true ) ) {
			return new WP_Error( 'openbb_invalid_aid_decision', __( 'Choose Approved or Declined.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}
		if ( ! OPENBB_Inventory::lock() ) {
			return new WP_Error( 'openbb_busy', __( 'Orders are being updated. Please try again.', 'open-bulk-buying' ), array( 'status' => 503 ) );
		}
		try {
			$order = OPENBB_Orders::get( $order_id );
			if ( ! $order || $order->cycle_id !== $cycle_id ) {
				return new WP_Error( 'openbb_order_not_found', __( 'Order not found in this cycle.', 'open-bulk-buying' ), array( 'status' => 404 ) );
			}
			if ( ! user_can( $actor_id, 'openbb_decide_order_aid', $order_id ) ) {
				return new WP_Error( 'openbb_forbidden', __( 'You cannot decide aid for this order.', 'open-bulk-buying' ), array( 'status' => 403 ) );
			}
			if ( $order->revision !== $revision ) {
				return new WP_Error( 'openbb_order_changed', __( 'This order changed. Reload the requests before deciding.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			if ( ! self::can_decide( $order, OPENBB_Cycles::get( $cycle_id ) ) ) {
				return new WP_Error( 'openbb_aid_locked', __( 'Aid decisions require an unpaid submitted order, an unallocated request, and a cycle that is not complete.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			if ( $order->aid_status === $decision ) {
				return $order;
			}
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return self::failed();
			}
			$previous = $order->aid_status;
			$order->aid_status     = $decision;
			$order->aid_decided_by = $actor_id;
			$order->aid_decided_at = current_time( 'mysql', true );
			$order->updated_at     = $order->aid_decided_at;
			++$order->revision;
			// Do not recalculate totals: eligibility is separate from future allocation.
			$saved = $wpdb->update(
				$wpdb->openbb_orders,
				array(
					'aid_status'     => $order->aid_status,
					'aid_decided_by' => $actor_id,
					'aid_decided_at' => $order->aid_decided_at,
					'updated_at'     => $order->updated_at,
					'revision'       => $order->revision,
				),
				array( 'id' => $order_id, 'revision' => $revision ),
				array( '%s', '%d', '%s', '%s', '%d' ),
				array( '%d', '%d' )
			);
			if ( 1 !== $saved || ! OPENBB_Orders::record_event(
				$order,
				array(
					'actor_id'    => $actor_id,
					'event_type'  => 'aid_' . $decision,
					'from_status' => $order->status,
					'reason'      => $previous . ' -> ' . $decision,
				)
			) || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::failed();
			}
			return $order;
		} finally {
			OPENBB_Inventory::unlock();
		}
	}

	private static function failed(): WP_Error {
		return new WP_Error( 'openbb_aid_save_failed', __( 'The aid decision could not be saved. No decision was recorded.', 'open-bulk-buying' ), array( 'status' => 500 ) );
	}
}
