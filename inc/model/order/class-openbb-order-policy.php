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

	public static function can_delete( OPENBB_Order $order ): bool {
		return OPENBB_Order::STATUS_DRAFT === $order->status && ! $order->submitted_at && ! $order->paid_at && ! $order->is_collected();
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
		if ( self::is_terminal( $order ) && $writes ) {
			return new WP_Error( 'openbb_order_locked', __( 'This order is locked. Only the staff note can be changed.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		if ( ! in_array( $status, self::EDITABLE_STATUSES, true ) && $status !== $order->status ) {
			return new WP_Error( 'openbb_invalid_order', __( 'That status cannot be set here.', 'open-bulk-buying' ), array( 'status' => 400 ) );
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
