<?php
/**
 * Explicit payment and handoff confirmations. Callers authorize the staff action.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_Order_Lifecycle {

	/**
	 * @return OPENBB_Order|WP_Error
	 */
	public static function confirm( int $order_id, string $action, int $actor_id, string $expected_updated_at = '', ?int $expected_revision = null ) {
		global $wpdb;

		$standalone = OPENBB_Orders::get( $order_id );
		if ( $standalone && ! $standalone->cycle_id ) {
			if ( 'fulfill' !== $action ) {
				return new WP_Error( 'openbb_invalid_action', __( 'Confirm this membership payment from the member screen.', 'open-bulk-buying' ), array( 'status' => 400 ) );
			}
			return OPENBB_Membership_Fees::review( $standalone->user_id, $standalone->id, $actor_id );
		}
		if ( ! isset( OPENBB_Order_Policy::CONFIRMATION_STATUSES[ $action ] ) ) {
			return new WP_Error( 'openbb_invalid_action', __( 'Choose a payment or fulfillment action.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}
		if ( ! OPENBB_Inventory::lock() ) {
			return new WP_Error( 'openbb_busy', __( 'Stock is being updated. Please try again.', 'open-bulk-buying' ), array( 'status' => 503 ) );
		}
		try {
			$order = OPENBB_Orders::get( $order_id );
			if ( ! $order ) {
				return new WP_Error( 'openbb_order_not_found', __( 'Order not found.', 'open-bulk-buying' ), array( 'status' => 404 ) );
			}
			$target = OPENBB_Order_Policy::CONFIRMATION_STATUSES[ $action ];
			// A retry acknowledges the saved result, including after the cycle closes.
			if ( $target === $order->status ) {
				return $order;
			}
			if ( ( '' !== $expected_updated_at && $expected_updated_at !== $order->updated_at )
				|| ( null !== $expected_revision && $expected_revision !== $order->revision ) ) {
				return new WP_Error( 'openbb_order_changed', __( 'This order changed. Reload it before confirming.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			$cycle = OPENBB_Cycles::get( $order->cycle_id );
			$valid = OPENBB_Order_Policy::validate_confirmation( $order, $cycle, $action );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$lines = $order->get_lines();
			if ( OPENBB_Order::STATUS_SUBMITTED === $order->status ) {
				list( $fee_changes ) = OPENBB_Membership_Fees::synchronize( $order, $lines, array(), array() );
				if ( $fee_changes ) {
					return new WP_Error( 'openbb_membership_fee_changed', __( 'Membership fee eligibility changed. Save and review the updated order before recording payment.', 'open-bulk-buying' ), array( 'status' => 409 ) );
				}
			}
			if ( ! array_filter( $lines, static function ( OPENBB_Order_Line $line ): bool {
				return ! $line->is_membership_fee() && $line->get_quantity() > 0;
			} ) ) {
				return new WP_Error( 'openbb_empty_order', __( 'An empty order cannot be paid or fulfilled.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			$order->recalculate_totals();
			if ( ! preg_match( '/^[A-Z]{3}$/', $order->currency ) ) {
				return new WP_Error( 'openbb_order_currency_missing', __( 'This order has no valid currency snapshot. Correct its currency before recording settlement.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			if ( OPENBB_Order::STATUS_PREPAID === $order->status && (
				null === $order->paid_at || $order->paid_amount !== $order->total || $order->paid_currency !== $order->currency
			) ) {
				return new WP_Error( 'openbb_payment_mismatch', __( 'The recorded payment does not settle this order. Resolve the payment before fulfillment.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return self::failed();
			}
			$before = clone $order;
			$from   = $order->status;
			$now    = current_time( 'mysql', true );
			if ( OPENBB_Order::STATUS_SUBMITTED === $from ) {
				$order->paid_at       = $now;
				$order->paid_by       = $actor_id;
				$order->paid_amount   = $order->total;
				$order->paid_currency = $order->currency;
			}
			if ( 'fulfill' === $action ) {
				$wanted = array();
				foreach ( $lines as $line ) {
					if ( $line->is_membership_fee() ) {
						continue;
					}
					$wanted[ $line->product_id ] = ( $wanted[ $line->product_id ] ?? 0.0 ) + $line->get_base_quantity();
				}
				$on_hand = OPENBB_Inventory::get_on_hand_for( array_keys( $wanted ) );
				foreach ( $wanted as $product_id => $quantity ) {
					if ( $quantity > ( $on_hand[ $product_id ] ?? 0.0 ) + OPENBB_Order_Editor::EPSILON ) {
						$wpdb->query( 'ROLLBACK' );
						return new WP_Error( 'openbb_insufficient_stock', __( 'Recorded stock is insufficient for this handoff. Reconcile inventory first.', 'open-bulk-buying' ), array( 'status' => 409 ) );
					}
				}
				foreach ( $lines as $line ) {
					if ( $line->is_membership_fee() ) {
						continue;
					}
					$line->qty_final = $line->get_quantity();
					if ( ! $line->write_row() ) {
						$wpdb->query( 'ROLLBACK' );
						return self::failed();
					}
					if ( $line->get_base_quantity() <= 0 ) {
						continue;
					}
					$move = new OPENBB_Stock_Move(
						array(
							'product_id'  => $line->product_id,
							'quantity'    => -$line->get_base_quantity(),
							'unit_amount' => $line->unit_amount,
							'unit_type'   => $line->unit_type,
							'reason'      => OPENBB_Stock_Move::REASON_DISTRIBUTION,
							'cycle_id'    => $order->cycle_id,
							'order_id'    => $order->id,
							'user_id'     => $actor_id,
							'request_key' => 'fulfill-line-' . $line->id,
						)
					);
					if ( ! $move->save() ) {
						$wpdb->query( 'ROLLBACK' );
						return self::failed();
					}
				}
				$order->collected_at = $now;
				$order->collected_by = $actor_id;
			}
			$order->status = $target;
			if ( ! $order->save() || ! OPENBB_Orders::record_event(
				$order,
				array(
					'actor_id'    => $actor_id,
					'event_type'  => $action,
					'from_status' => $from,
				)
			) || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::failed();
			}
		} finally {
			OPENBB_Inventory::unlock();
		}
		do_action( 'openbb_order_saved', $order, $before );
		return $order;
	}

	private static function failed(): WP_Error {
		return new WP_Error( 'openbb_confirmation_failed', __( 'The confirmation could not be saved. No payment or stock change was recorded.', 'open-bulk-buying' ), array( 'status' => 500 ) );
	}
}
