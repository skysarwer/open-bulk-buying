<?php
/**
 * Aid eligibility decisions and equal-percentage distribution of the cycle's donations.
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

	/**
	 * Aid funds for one cycle, in minor units. The pool is currently only the
	 * donations on the cycle's own orders; a carried-over balance can be added
	 * as another source without changing the shape of the result.
	 *
	 * @return array|WP_Error
	 */
	public static function cycle_summary( int $cycle_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(donation), 0) AS donated,
					COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN donation ELSE 0 END), 0) AS received,
					COALESCE(SUM(aid_approved), 0) AS allocated,
					SUM(donation > 0) AS donors,
					SUM(aid_status = %s) AS pending,
					SUM(aid_status = %s) AS approved,
					SUM(aid_status = %s) AS declined
				FROM {$wpdb->openbb_orders}
				WHERE cycle_id = %d AND status NOT IN (%s, %s)",
				OPENBB_Order::AID_PENDING,
				OPENBB_Order::AID_APPROVED,
				OPENBB_Order::AID_DECLINED,
				$cycle_id,
				OPENBB_Order::STATUS_DRAFT,
				OPENBB_Order::STATUS_CANCELLED
			),
			ARRAY_A
		);
		if ( $wpdb->last_error || ! $row ) {
			return new WP_Error( 'openbb_aid_load_failed', __( 'Aid funds could not be loaded.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}
		$donated   = (int) $row['donated'];
		$allocated = (int) $row['allocated'];

		return array(
			'donated'   => $donated,
			'received'  => (int) $row['received'],
			'donors'    => (int) $row['donors'],
			'allocated' => $allocated,
			'available' => $donated - $allocated,
			'requests'  => array(
				OPENBB_Order::AID_PENDING  => (int) $row['pending'],
				OPENBB_Order::AID_APPROVED => (int) $row['approved'],
				OPENBB_Order::AID_DECLINED => (int) $row['declined'],
			),
		);
	}

	/**
	 * Splits $pool so every amount gets the same percentage discount, the largest
	 * that the pool can fund, capped at 100%: p = min(1, pool / sum(amounts)).
	 * Shares are floored to minor units and the leftover units (fewer than the
	 * number of amounts) go to the largest remainders, so the pool is used exactly
	 * when it is smaller than the total and no share ever exceeds its amount.
	 *
	 * @param array<int, int> $amounts Aid-eligible amount per key, in minor units.
	 * @return array<int, int> Share per key, in minor units.
	 */
	public static function equal_share( int $pool, array $amounts ): array {
		$amounts = array_map(
			static function ( $amount ): int {
				return max( 0, (int) $amount );
			},
			$amounts
		);
		$eligible = array_sum( $amounts );
		if ( $pool <= 0 || $eligible <= 0 ) {
			return array_map( '__return_zero', $amounts );
		}
		if ( $pool >= $eligible ) {
			return $amounts;
		}
		$shares     = array();
		$remainders = array();
		foreach ( $amounts as $key => $amount ) {
			$shares[ $key ]     = intdiv( $amount * $pool, $eligible );
			$remainders[ $key ] = ( $amount * $pool ) % $eligible;
		}
		$leftover = $pool - array_sum( $shares );
		uksort(
			$remainders,
			static function ( $a, $b ) use ( $remainders ): int {
				return $remainders[ $b ] <=> $remainders[ $a ] ?: $a <=> $b;
			}
		);
		foreach ( array_keys( $remainders ) as $key ) {
			if ( $leftover <= 0 ) {
				break;
			}
			if ( $remainders[ $key ] > 0 ) {
				++$shares[ $key ];
				--$leftover;
			}
		}
		return $shares;
	}

	/**
	 * The part of an order that aid may reduce: goods after container credit.
	 * Membership fees and donations are never covered.
	 */
	public static function eligible_amount( OPENBB_Order $order ): int {
		$eligible = 0;
		foreach ( $order->get_lines() as $line ) {
			if ( ! $line->is_membership_fee() ) {
				$eligible += $line->calculate_total() - $line->calculate_container_credit();
			}
		}
		return max( 0, $eligible );
	}

	/**
	 * Orders whose aid can still be (re)allocated: approved requests, plus any
	 * stale allocation to reset, on unpaid, uncollected submitted orders.
	 *
	 * @return OPENBB_Order[]|WP_Error
	 */
	private static function open_allocations( int $cycle_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->openbb_orders}
				WHERE cycle_id = %d AND status = %s AND paid_at IS NULL AND collected_at IS NULL
					AND (aid_status = %s OR aid_approved <> 0)
				ORDER BY id",
				$cycle_id,
				OPENBB_Order::STATUS_SUBMITTED,
				OPENBB_Order::AID_APPROVED
			)
		);
		if ( $wpdb->last_error ) {
			return new WP_Error( 'openbb_aid_load_failed', __( 'Aid funds could not be loaded.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}
		return array_values( array_filter( array_map( array( 'OPENBB_Orders', 'get' ), array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * The equal-percentage distribution that distribute() would apply now. The pool
	 * is the cycle's donations minus aid already fixed on paid or collected orders.
	 *
	 * @param array|null $summary A cycle_summary() result to reuse.
	 * @return array|WP_Error
	 */
	public static function plan( int $cycle_id, ?array $summary = null ) {
		$summary = $summary ?? self::cycle_summary( $cycle_id );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}
		$orders = self::open_allocations( $cycle_id );
		if ( is_wp_error( $orders ) ) {
			return $orders;
		}
		$amounts   = array();
		$reclaimed = 0;
		foreach ( $orders as $order ) {
			$reclaimed += $order->aid_approved;
			if ( OPENBB_Order::AID_APPROVED === $order->aid_status ) {
				$amounts[ $order->id ] = self::eligible_amount( $order );
			}
		}
		$pool     = max( 0, $summary['available'] + $reclaimed );
		$eligible = array_sum( $amounts );
		$shares   = self::equal_share( $pool, $amounts );

		return array(
			'pool'       => $pool,
			'eligible'   => $eligible,
			'recipients' => count( $amounts ),
			// Percentage of each recipient's eligible amount that is covered.
			'percent'    => $eligible > 0 ? round( min( 1, $pool / $eligible ) * 100, 2 ) : 0.0,
			'allocated'  => array_sum( $shares ),
			'orders'     => $orders,
			'shares'     => $shares,
		);
	}

	/**
	 * Applies plan(): sets aid_approved on every open allocation (zero for
	 * non-approved ones) and recalculates their totals. Safe to rerun as
	 * donations or approvals change; paid and collected orders keep their aid.
	 *
	 * @return array|WP_Error The applied plan, without order objects.
	 */
	public static function distribute( int $cycle_id, int $actor_id ) {
		global $wpdb;

		$cycle = OPENBB_Cycles::get( $cycle_id );
		if ( ! $cycle ) {
			return new WP_Error( 'openbb_cycle_not_found', __( 'Cycle not found.', 'open-bulk-buying' ), array( 'status' => 404 ) );
		}
		if ( OPENBB_Cycle::STATUS_COMPLETE === $cycle->status ) {
			return new WP_Error( 'openbb_aid_locked', __( 'Aid cannot be distributed in a completed cycle.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}
		if ( ! OPENBB_Inventory::lock() ) {
			return new WP_Error( 'openbb_busy', __( 'Orders are being updated. Please try again.', 'open-bulk-buying' ), array( 'status' => 503 ) );
		}
		try {
			$plan = self::plan( $cycle_id );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return self::distribution_failed();
			}
			$changed = array();
			foreach ( $plan['orders'] as $order ) {
				$share = $plan['shares'][ $order->id ] ?? 0;
				if ( $share === $order->aid_approved ) {
					continue;
				}
				$before              = $order->aid_approved;
				$order->aid_approved = $share;
				if ( ! $order->save() || ! OPENBB_Orders::record_event(
					$order,
					array(
						'actor_id'    => $actor_id,
						'event_type'  => 'aid_allocated',
						'from_status' => $order->status,
						'reason'      => sprintf( '%1$d -> %2$d (%3$s%%)', $before, $share, $plan['percent'] ),
					)
				) ) {
					$wpdb->query( 'ROLLBACK' );
					return self::distribution_failed();
				}
				$changed[] = $order->id;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::distribution_failed();
			}
		} finally {
			OPENBB_Inventory::unlock();
		}
		unset( $plan['orders'] );
		$plan['changed'] = $changed;
		return $plan;
	}

	private static function distribution_failed(): WP_Error {
		return new WP_Error( 'openbb_aid_distribution_failed', __( 'Aid could not be distributed. No amounts were changed.', 'open-bulk-buying' ), array( 'status' => 500 ) );
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
