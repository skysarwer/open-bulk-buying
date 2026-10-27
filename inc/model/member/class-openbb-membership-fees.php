<?php
/**
 * Membership charges use typed order lines; only confirmed settlement counts as paid.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Membership_Fees {

	public static function summary( int $user_id ): array {
		$settings = OPENBB_Settings::get_general();
		$history  = array();
		$pending  = 0;
		$pending_order = null;
		$outstanding_order = null;
		foreach ( self::orders( $user_id ) as $order ) {
			$history[] = array(
				'id'        => $order->id,
				'status'    => $order->status,
				'total'     => $order->membership_fee(),
				'currency'  => $order->currency,
				'paid_at'   => $order->paid_at,
				'reference' => $order->cycle_id ? '' : $order->member_note,
			);
			if ( ! $order->cycle_id && OPENBB_Order::STATUS_SUBMITTED === $order->status ) {
				$pending = $order->id;
				$pending_order = $order;
			} elseif ( ! $outstanding_order && OPENBB_Order::STATUS_SUBMITTED === $order->status && OPENBB_Inventory::order_reserves( $order ) ) {
				$outstanding_order = $order;
			}
		}
		$charge = $pending_order ?? $outstanding_order;
		return array(
			'status'           => $pending ? 'pending' : ( $settings['membership_fee_amount'] <= 0 ? 'not_applicable' : ( self::due_amount( $user_id, 0, true ) > 0 ? 'due' : 'paid' ) ),
			'amount'           => $charge ? $charge->membership_fee() : (int) $settings['membership_fee_amount'],
			'currency'         => $charge ? $charge->currency : openbb_currency(),
			'pending_order_id' => $pending,
			'history'          => $history,
		);
	}

	/** @return OPENBB_Order[] */
	private static function orders( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT o.* FROM {$wpdb->openbb_orders} o
			INNER JOIN {$wpdb->openbb_order_lines} l ON l.order_id = o.id
			WHERE o.user_id = %d AND l.line_type = %s AND l.qty_requested > 0
			ORDER BY o.id DESC",
			$user_id, OPENBB_Order_Line::TYPE_MEMBERSHIP_FEE
		), ARRAY_A );
		return array_map( static function ( array $row ): OPENBB_Order {
			return new OPENBB_Order( $row );
		}, (array) $rows );
	}

	private static function settled( OPENBB_Order $order ): bool {
		return in_array( $order->status, array( OPENBB_Order::STATUS_PREPAID, OPENBB_Order::STATUS_FULFILLED ), true )
			&& null !== $order->paid_at && $order->paid_amount === $order->total && $order->paid_currency === $order->currency;
	}

	/**
	 * Paid cycle orders (including prepaid) advance recurrence. A cycle fee covers its
	 * own order; a standalone payment covers the next N cycle orders.
	 */
	public static function due_amount( int $user_id, int $exclude_order_id = 0, bool $ignore_pending = false ): int {
		global $wpdb;
		$settings = OPENBB_Settings::get_general();
		$amount   = (int) $settings['membership_fee_amount'];
		if ( $amount <= 0 ) {
			return 0;
		}
		$paid = null;
		foreach ( self::orders( $user_id ) as $order ) {
			if ( self::settled( $order ) && ( ! $paid || $order->paid_at > $paid->paid_at || ( $order->paid_at === $paid->paid_at && self::payment_event( $order->id ) > self::payment_event( $paid->id ) ) ) ) {
				$paid = $order;
			}
			if ( ! $ignore_pending && $order->id !== $exclude_order_id && OPENBB_Order::STATUS_SUBMITTED === $order->status
				&& ( ! $order->cycle_id || OPENBB_Inventory::order_reserves( $order ) ) ) {
				return 0;
			}
		}
		if ( ! $paid ) {
			return $amount;
		}
		if ( 'recurring' !== $settings['membership_fee_type'] ) {
			return 0;
		}
		$event = self::payment_event( $paid->id );
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->openbb_orders} o WHERE o.user_id = %d AND o.cycle_id IS NOT NULL
			AND o.id != %d AND o.status IN ('prepaid','fulfilled') AND o.paid_at IS NOT NULL
			AND o.paid_amount = o.total AND o.paid_currency = o.currency
			AND (o.paid_at > %s OR (o.paid_at = %s AND
				(SELECT MIN(e.id) FROM {$wpdb->openbb_order_events} e WHERE e.order_id = o.id
				AND e.event_type IN ('prepay','fulfill','membership_paid')) > %d))",
			$user_id, $paid->id, $paid->paid_at, $paid->paid_at, $event
		) );
		return $count + ( $paid->cycle_id ? 1 : 0 ) >= (int) $settings['membership_fee_order_interval'] ? $amount : 0;
	}

	private static function payment_event( int $order_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MIN(id) FROM {$wpdb->openbb_order_events} WHERE order_id = %d AND event_type IN ('prepay','fulfill','membership_paid')",
			$order_id
		) );
	}

	public static function line( int $amount ): OPENBB_Order_Line {
		return new OPENBB_Order_Line( array(
			'line_type'     => OPENBB_Order_Line::TYPE_MEMBERSHIP_FEE,
			'product_name'  => __( 'Membership fee', 'open-bulk-buying' ),
			'unit_type'     => 'unit',
			'base_per_unit' => 0,
			'unit_price'    => $amount,
			'qty_requested'=> 1,
		) );
	}

	/**
	 * Called under the shared lock. Fees are system-managed, not editable basket items.
	 *
	 * @return array{0: OPENBB_Order_Line[], 1: int[]}
	 */
	public static function synchronize( OPENBB_Order $order, array $final, array $writes, array $deletes ): array {
		$goods = array_filter( $final, static function ( OPENBB_Order_Line $line ): bool {
			return ! $line->is_membership_fee() && $line->get_quantity() > 0;
		} );
		$fee = null;
		foreach ( $order->get_lines() as $line ) {
			if ( $line->is_membership_fee() ) {
				$fee = $line;
				if ( $line->qty_requested > 0 ) {
					break;
				}
			}
		}
		$amount = $goods ? self::due_amount( $order->user_id, $order->id ) : 0;
		if ( $fee && $fee->qty_requested > 0 && $amount > 0 ) {
			return array( $writes, $deletes );
		}
		if ( $fee && $fee->qty_requested > 0 ) {
			$fee = clone $fee;
			$fee->qty_requested = 0.0;
			$writes[] = $fee;
		} elseif ( $amount > 0 ) {
			$new = self::line( $amount );
			if ( $fee ) {
				$new->id = $fee->id;
			}
			$writes[] = $new;
		}
		return array( $writes, $deletes );
	}

	/** @return OPENBB_Order|WP_Error */
	public static function claim( int $user_id, string $reference = '' ) {
		return self::transaction( $user_id, $user_id, 'claim', 0, false, $reference );
	}

	/** @return OPENBB_Order|WP_Error */
	public static function record( int $user_id, int $actor_id, string $reference = '' ) {
		return self::transaction( $user_id, $actor_id, 'record', 0, false, $reference );
	}

	/** @return OPENBB_Order|WP_Error */
	public static function review( int $user_id, int $order_id, int $actor_id, bool $approve_member = false, bool $reject = false ) {
		return self::transaction( $user_id, $actor_id, $reject ? 'reject' : 'confirm', $order_id, $approve_member );
	}

	private static function failure(): WP_Error {
		return new WP_Error( 'openbb_membership_payment_failed', __( 'The membership payment could not be saved. No changes were recorded.', 'open-bulk-buying' ), array( 'status' => 500 ) );
	}

	private static function transaction( int $user_id, int $actor_id, string $action, int $order_id, bool $approve_member, string $reference = '' ) {
		global $wpdb;
		if ( 'claim' !== $action && ( ! user_can( $actor_id, 'openbb_record_payments' ) || ! user_can( $actor_id, 'openbb_edit_member', $user_id ) ) ) {
			return new WP_Error( 'openbb_forbidden', __( 'You cannot record this membership payment.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}
		if ( ! OPENBB_Inventory::lock() ) {
			return new WP_Error( 'openbb_busy', __( 'Orders are being updated. Please try again.', 'open-bulk-buying' ), array( 'status' => 503 ) );
		}
		$notifications = array();
		$old_status = '';
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return self::failure();
			}
			$result = self::write( $user_id, $actor_id, $action, $order_id, $approve_member, $reference, $notifications, $old_status );
			if ( is_wp_error( $result ) || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				clean_user_cache( $user_id );
				wp_cache_delete( $user_id, 'user_meta' );
				OPENBB_Access::flush_cache();
				return is_wp_error( $result ) ? $result : self::failure();
			}
		} finally {
			OPENBB_Inventory::unlock();
		}
		if ( $old_status ) {
			do_action( 'openbb_member_status_changed', $user_id, OPENBB_Access::STATUS_ACTIVE, $old_status, $actor_id );
		}
		foreach ( $notifications as $pair ) {
			do_action( 'openbb_order_saved', $pair[0], $pair[1] );
		}
		return $result;
	}

	private static function write( int $user_id, int $actor_id, string $action, int $order_id, bool $approve_member, string $reference, array &$notifications, string &$old_status ) {
		global $wpdb;
		$member = OPENBB_Member::get( $user_id );
		if ( ! $member ) {
			return new WP_Error( 'openbb_member_not_found', __( 'Member not found.', 'open-bulk-buying' ), array( 'status' => 404 ) );
		}
		if ( $approve_member && ( 'reject' === $action || ! in_array( $member->status, array( 'applicant', 'active' ), true ) || ( ! $member->is_active() && ! user_can( $actor_id, 'openbb_set_member_status', $user_id, OPENBB_Access::STATUS_ACTIVE ) ) ) ) {
			return new WP_Error( 'openbb_forbidden', __( 'You cannot approve this membership.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}
		$summary = self::summary( $user_id );
		if ( ! $order_id && $summary['pending_order_id'] ) {
			if ( 'claim' === $action ) {
				return OPENBB_Orders::get( $summary['pending_order_id'] );
			}
			$order_id = $summary['pending_order_id'];
		}
		$order = $order_id ? OPENBB_Orders::get( $order_id ) : null;
		if ( $order_id && ( ! $order || $order->user_id !== $user_id || $order->cycle_id || $order->membership_fee() <= 0 ) ) {
			return new WP_Error( 'openbb_invalid_membership_order', __( 'Choose this member\'s membership payment claim.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}
		if ( $order && ( ( 'reject' === $action && OPENBB_Order::STATUS_CANCELLED === $order->status ) || ( 'reject' !== $action && self::settled( $order ) ) ) ) {
			if ( $approve_member && ! $member->is_active() ) {
				return new WP_Error( 'openbb_claim_changed', __( 'This claim has already been reviewed. Reload the member.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			return $order;
		}
		if ( $order && OPENBB_Order::STATUS_SUBMITTED !== $order->status ) {
			return new WP_Error( 'openbb_claim_changed', __( 'This claim has already been reviewed. Reload the member.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		$before = $order ? clone $order : null;
		if ( $before ) {
			$before->get_lines();
		}
		if ( ! $order ) {
			if ( in_array( $action, array( 'confirm', 'reject' ), true ) ) {
				return new WP_Error( 'openbb_claim_missing', __( 'Choose a pending payment claim.', 'open-bulk-buying' ), array( 'status' => 400 ) );
			}
			$amount = self::due_amount( $user_id, 0, true );
			if ( $amount <= 0 ) {
				return new WP_Error( 'openbb_membership_fee_not_due', __( 'No membership fee is due.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			$currency = openbb_currency();
			foreach ( self::orders( $user_id ) as $outstanding ) {
				if ( OPENBB_Order::STATUS_SUBMITTED === $outstanding->status && $outstanding->cycle_id && OPENBB_Inventory::order_reserves( $outstanding ) ) {
					$amount = $outstanding->membership_fee();
					$currency = $outstanding->currency;
					break;
				}
			}
			$order = new OPENBB_Order( array(
				'user_id' => $user_id, 'currency' => $currency, 'language' => $member->language,
				'status' => OPENBB_Order::STATUS_SUBMITTED, 'submitted_at' => current_time( 'mysql', true ),
				'member_note' => sanitize_textarea_field( $reference ),
			) );
			if ( ! $order->save() ) {
				return self::failure();
			}
			$line = self::line( $amount );
			$line->order_id = $order->id;
			if ( ! $line->write_row() ) {
				return self::failure();
			}
			$order = OPENBB_Orders::get( $order->id );
		}
		if ( ! preg_match( '/^[A-Z]{3}$/', $order->currency ) || $order->donation || $order->aid_approved ) {
			return new WP_Error( 'openbb_invalid_membership_order', __( 'The membership order has invalid settlement details.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		foreach ( $order->get_lines() as $line ) {
			if ( ! $line->is_membership_fee() || 1.0 !== $line->get_quantity() || $line->container_charge || $line->product_id ) {
				return new WP_Error( 'openbb_invalid_membership_order', __( 'A standalone membership order must contain only a membership fee.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
		}
		if ( 'reject' !== $action ) {
			$reconciled = self::remove_unpaid_cycle_fees( $user_id, $actor_id, $notifications );
			if ( is_wp_error( $reconciled ) ) {
				return $reconciled;
			}
		}
		if ( 'claim' !== $action && 'reject' !== $action ) {
			$order->status = OPENBB_Order::STATUS_FULFILLED;
			$order->recalculate_totals();
			$order->paid_at = current_time( 'mysql', true );
			$order->paid_by = $actor_id;
			$order->paid_amount = $order->total;
			$order->paid_currency = $order->currency;
		} elseif ( 'reject' === $action ) {
			$order->status = OPENBB_Order::STATUS_CANCELLED;
		}
		if ( ! $order->save() || ! OPENBB_Orders::record_event( $order, array(
			'actor_id' => $actor_id, 'event_type' => 'claim' === $action ? 'membership_claimed' : ( 'reject' === $action ? 'membership_claim_rejected' : 'membership_paid' ),
			'from_status' => $before ? $before->status : '', 'reason' => $reference,
		) ) ) {
			return self::failure();
		}
		if ( $approve_member && ! $member->is_active() ) {
			$saved = OPENBB_Access::set_status( $user_id, OPENBB_Access::STATUS_ACTIVE, false );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$old_status = $member->status;
			if ( ! OPENBB_Orders::record_event( $order, array( 'actor_id' => $actor_id, 'event_type' => 'membership_approved', 'from_status' => $order->status ) ) ) {
				return self::failure();
			}
		}
		$notifications[] = array( $order, $before );
		return $order;
	}

	private static function remove_unpaid_cycle_fees( int $user_id, int $actor_id, array &$notifications ) {
		foreach ( self::orders( $user_id ) as $order ) {
			if ( ! $order->cycle_id || ! in_array( $order->status, array( 'draft', 'submitted' ), true ) || $order->paid_at ) {
				continue;
			}
			$order->get_lines();
			$before = clone $order;
			foreach ( $order->get_lines() as $line ) {
				if ( $line->is_membership_fee() ) {
					$line = clone $line;
					$line->qty_requested = 0.0;
					if ( ! $line->write_row() ) {
						return self::failure();
					}
				}
			}
			$order = OPENBB_Orders::get( $order->id );
			if ( ! $order->save() || ! OPENBB_Orders::record_event( $order, array(
				'actor_id' => $actor_id, 'event_type' => 'membership_fee_removed', 'from_status' => $order->status,
				'reason' => __( 'Membership fee recorded separately.', 'open-bulk-buying' ),
			) ) ) {
				return self::failure();
			}
			$notifications[] = array( $order, $before );
		}
		return true;
	}
}
