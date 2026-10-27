<?php
/**
 * Order eligibility rules. Authorization and persistence belong to callers.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_Order_Policy {

	const EDITABLE_STATUSES = array( OPENBB_Order::STATUS_DRAFT, OPENBB_Order::STATUS_SUBMITTED, OPENBB_Order::STATUS_CANCELLED );
	const CONFIRMATION_STATUSES = array( 'prepay' => OPENBB_Order::STATUS_PREPAID, 'fulfill' => OPENBB_Order::STATUS_FULFILLED );

	public static function is_terminal( OPENBB_Order $order ): bool {
		return in_array( $order->status, array( OPENBB_Order::STATUS_FULFILLED, OPENBB_Order::STATUS_CANCELLED ), true ) || $order->is_collected();
	}

	public static function can_edit_contents( OPENBB_Order $order, ?OPENBB_Cycle $cycle ): bool {
		return true === self::validate_edit( $order, $cycle, array( 'lines' => array() ) )
			&& true === self::validate_content_change( $order, true, $order->donation );
	}

	public static function can_delete( OPENBB_Order $order, ?OPENBB_Cycle $cycle = null ): bool {
		return true === self::validate_delete( $order, $cycle );
	}

	/** @return true|WP_Error */
	public static function validate_delete( OPENBB_Order $order, ?OPENBB_Cycle $cycle = null ) {
		global $wpdb;

		$cycle = $cycle ?? ( $order->cycle_id ? OPENBB_Cycles::get( $order->cycle_id ) : null );
		$draft = OPENBB_Order::STATUS_DRAFT === $order->status && ! $order->submitted_at;
		$preparation = $cycle && $cycle->allows_preparation_cleanup()
			&& in_array( $order->status, self::EDITABLE_STATUSES, true );
		if ( ! $draft && ! $preparation ) {
			return new WP_Error( 'openbb_order_not_deletable', __( 'Only never-submitted drafts or preparation orders in an unlocked Setup cycle can be deleted.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		if ( ! $preparation && $order->id ) {
			$history = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->openbb_order_events} WHERE order_id = %d
					AND (from_status IN ('submitted', 'cancelled') OR to_status IN ('submitted', 'cancelled'))",
					$order->id
				)
			);
			if ( null === $history ) {
				return new WP_Error( 'openbb_order_history_failed', __( 'The order history could not be checked. Please try again.', 'open-bulk-buying' ), array( 'status' => 500 ) );
			}
			if ( (int) $history > 0 ) {
				return new WP_Error( 'openbb_order_not_deletable', __( 'This draft has submission or cancellation history and cannot be deleted outside preparation cleanup.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
		}
		return self::validate_no_operational_history( $order );
	}

	public static function can_reopen( OPENBB_Order $order, ?OPENBB_Cycle $cycle ): bool {
		return true === self::validate_reopen( $order, $cycle );
	}

	/** @return true|WP_Error */
	public static function validate_reopen( OPENBB_Order $order, ?OPENBB_Cycle $cycle ) {
		if ( OPENBB_Order::STATUS_CANCELLED !== $order->status || ! $cycle || ! $cycle->is_active() ) {
			return new WP_Error( 'openbb_order_not_reopenable', __( 'Only cancelled orders in an active cycle can be reopened as drafts.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		return self::validate_no_operational_history( $order );
	}

	/** @return true|WP_Error */
	private static function validate_no_operational_history( OPENBB_Order $order ) {
		global $wpdb;

		if ( null !== $order->paid_at || null !== $order->paid_by || null !== $order->paid_amount
			|| null !== $order->paid_currency || $order->is_collected() || null !== $order->collected_by
			|| $order->aid_approved > 0
			|| in_array( $order->status, array( OPENBB_Order::STATUS_PREPAID, OPENBB_Order::STATUS_FULFILLED ), true ) ) {
			return new WP_Error( 'openbb_order_has_operational_history', __( 'Orders with payment, collection, or allocated aid history cannot be deleted or reopened.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		if ( $order->id ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT (SELECT COUNT(*) FROM {$wpdb->openbb_stock_moves} WHERE order_id = %d)
						+ (SELECT COUNT(*) FROM {$wpdb->openbb_order_events} WHERE order_id = %d
							AND (from_status IN ('prepaid', 'fulfilled') OR to_status IN ('prepaid', 'fulfilled')))",
					$order->id,
					$order->id
				)
			);
			if ( null === $count ) {
				return new WP_Error( 'openbb_order_history_failed', __( 'The order history could not be checked. Please try again.', 'open-bulk-buying' ), array( 'status' => 500 ) );
			}
			if ( (int) $count > 0 ) {
				return new WP_Error( 'openbb_order_has_stock_history', __( 'An order with stock or settlement history cannot be deleted or reopened.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
		}
		return true;
	}

	public static function needs_reason( OPENBB_Order $order, string $new_status ): bool {
		return OPENBB_Order::STATUS_DRAFT !== $order->status || OPENBB_Order::STATUS_CANCELLED === $new_status;
	}

	public static function can_cancel( OPENBB_Order $order, ?OPENBB_Cycle $cycle ): bool {
		return OPENBB_Order::STATUS_CANCELLED !== $order->status
			&& true === self::validate_edit( $order, $cycle, array( 'status' => OPENBB_Order::STATUS_CANCELLED ) );
	}

	public static function can_confirm( OPENBB_Order $order, ?OPENBB_Cycle $cycle, string $action ): bool {
		return isset( self::CONFIRMATION_STATUSES[ $action ] ) && $order->status !== self::CONFIRMATION_STATUSES[ $action ]
			&& true === self::validate_confirmation( $order, $cycle, $action );
	}

	public static function editable_statuses( OPENBB_Order $order, ?OPENBB_Cycle $cycle ): array {
		return array_values(
			array_filter(
				self::EDITABLE_STATUSES,
				static function ( string $status ) use ( $order, $cycle ): bool {
					return true === self::validate_edit( $order, $cycle, array( 'status' => $status ) );
				}
			)
		);
	}

	/** @return true|WP_Error */
	public static function validate_edit( OPENBB_Order $order, ?OPENBB_Cycle $cycle, array $changes ) {
		$status = isset( $changes['status'] ) ? (string) $changes['status'] : $order->status;
		$writes = isset( $changes['lines'] ) || $status !== $order->status || isset( $changes['donation'] );
		if ( ! empty( $changes['reopen'] ) && OPENBB_Order::STATUS_DRAFT === $status ) {
			return self::validate_reopen( $order, $cycle );
		}
		if ( self::is_terminal( $order ) && $writes ) {
			return new WP_Error( 'openbb_order_locked', __( 'This order is locked. Only the staff note can be changed.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		if ( ! in_array( $status, self::EDITABLE_STATUSES, true ) && $status !== $order->status ) {
			return new WP_Error( 'openbb_invalid_order', __( 'That status cannot be set here.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}
		if ( ! $order->cycle_id && ! $cycle ) {
			return $writes
				? new WP_Error( 'openbb_membership_order_locked', __( 'Review membership payments from the member screen.', 'open-bulk-buying' ), array( 'status' => 409 ) )
				: true;
		}
		if ( $status !== $order->status && (
			OPENBB_Order::STATUS_DRAFT === $status
			|| OPENBB_Order::STATUS_CANCELLED === $order->status
			|| OPENBB_Order::STATUS_PREPAID === $order->status
		) ) {
			return new WP_Error( 'openbb_order_transition', __( 'This order cannot move to that status.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		if ( ! $cycle || ( OPENBB_Cycle::STATUS_COMPLETE === $cycle->status && $writes ) ) {
			return new WP_Error( 'openbb_cycle_complete', __( 'Orders in a completed cycle cannot be changed.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/** @return true|WP_Error */
	public static function validate_content_change( OPENBB_Order $order, bool $lines_changed, int $donation ) {
		if ( OPENBB_Order::STATUS_PREPAID === $order->status && ( $lines_changed || $donation !== $order->donation ) ) {
			return new WP_Error( 'openbb_order_prepaid', __( 'Paid order quantities and amounts cannot be changed until payment adjustments are supported.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/** @return true|WP_Error */
	public static function validate_confirmation( OPENBB_Order $order, ?OPENBB_Cycle $cycle, string $action ) {
		if ( ! isset( self::CONFIRMATION_STATUSES[ $action ] ) ) {
			return new WP_Error( 'openbb_invalid_action', __( 'Choose a payment or fulfillment action.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}
		if ( ! $cycle || ! OPENBB_Inventory::order_reserves( $order, $cycle ) ) {
			return new WP_Error( 'openbb_order_transition', __( 'Only committed orders with an active stock hold can be paid or fulfilled.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		if ( 'fulfill' === $action && OPENBB_Cycle::STATUS_DISTRIBUTION !== $cycle->status ) {
			return new WP_Error( 'openbb_not_distributing', __( 'Start distribution before fulfilling orders.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		return true;
	}
}
