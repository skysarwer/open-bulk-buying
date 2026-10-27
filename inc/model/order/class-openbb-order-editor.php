<?php
/**
 * Order editing: the rules for starting an order for a member and for changing an
 * existing one, independent of how the request arrived (staff REST, CLI, or the
 * member order form through OPENBB_Member_Orders).
 *
 * Line quantities are in each line's selling unit. Increasing what an order holds
 * is capped by available stock (on hand minus other orders' reservations); what an
 * order already reserves stays available to it, so reductions are always allowed.
 * The check and the write run under OPENBB_Inventory::lock().
 *
 * Callers authorize first (openbb_edit_order, or openbb_edit_own_order for members).
 * Errors are WP_Errors whose data includes an HTTP-style 'status', like OPENBB_Inventory's.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Order_Editor {

	/** Tolerance for decimal quantity comparisons (the columns hold 3 decimals). */
	const EPSILON = 0.0005;

	/**
	 * An unsaved draft order for $user_id in $cycle. Pass it to apply() to save it.
	 *
	 * @return OPENBB_Order|WP_Error
	 */
	public static function start( ?OPENBB_Cycle $cycle, int $user_id ) {
		if ( ! $cycle ) {
			return self::invalid( __( 'Choose an existing cycle.', 'open-bulk-buying' ) );
		}
		if ( OPENBB_Cycle::STATUS_COMPLETE === $cycle->status ) {
			return new WP_Error( 'openbb_cycle_complete', __( 'Orders cannot be added to a completed cycle.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		$member = OPENBB_Member::get( $user_id );
		if ( ! $member || ! $member->is_active() ) {
			return self::invalid( __( 'Choose an active co-op member.', 'open-bulk-buying' ) );
		}

		$existing = OPENBB_Orders::get_for_member( $cycle->id, $member->user_id );
		if ( $existing ) {
			return new WP_Error(
				'openbb_order_exists',
				__( 'This member already has an order in this cycle. Edit that order instead.', 'open-bulk-buying' ),
				array(
					'status'   => 409,
					'order_id' => $existing->id,
				)
			);
		}

		$order           = new OPENBB_Order();
		$order->cycle_id = $cycle->id;
		$order->user_id  = $member->user_id;
		$order->status   = OPENBB_Order::STATUS_DRAFT;
		$order->language = '' !== (string) $member->language ? (string) $member->language : openbb_current_language();
		$order->currency = openbb_currency();

		return $order;
	}

	/**
	 * Applies changes to $order (saved or from start()) and saves it.
	 *
	 * Every key of $changes is optional:
	 * - status              One of OPENBB_Order_Policy::EDITABLE_STATUSES.
	 * - lines               The full list of lines: { id?, product_id?, qty, own_container? }.
	 *                       Existing lines missing from the list are removed.
	 * - donation            Minor units.
	 * - staff_note          Replaces the note.
	 * - member_note         Replaces the member's note.
	 * - aid_request         Whether the member asks for financial aid; false withdraws it.
	 *                       The amount is set by staff when deciding, from the donations
	 *                       available. Can't change once aid is decided.
	 * - aid_reason          Why aid is requested.
	 * - reason              Required when a non-draft order (or a cancellation) changes;
	 *                       appended to the staff note with the actor's name.
	 * - expected_updated_at UTC "Y-m-d H:i:s" the caller last saw; rejects the save if
	 *                       someone else changed the order since.
	 * - expected_revision   Same, by the order's revision counter.
	 *
	 * With $by_member the member who owns the order is saving it from the order form:
	 * no reason is asked for and the events say so. Callers check the cycle is taking orders.
	 *
	 * @return OPENBB_Order|WP_Error The saved order, re-read.
	 */
	public static function apply( OPENBB_Order $order, array $changes, int $actor_id, bool $by_member = false ) {
		if ( ! empty( $changes['reopen'] ) && ( $by_member || array_diff( array_keys( $changes ), array( 'reopen', 'status', 'reason', 'expected_updated_at', 'expected_revision' ) ) ) ) {
			return self::invalid( __( 'Reopen the order separately before editing its contents.', 'open-bulk-buying' ) );
		}
		if ( ! OPENBB_Inventory::lock() ) {
			return new WP_Error( 'openbb_busy', __( 'Stock is being updated by someone else. Please try again.', 'open-bulk-buying' ), array( 'status' => 503 ) );
		}

		$before = null;
		try {
			$saved = self::apply_locked( $order, $changes, $actor_id, $by_member, $before );
			if ( $saved instanceof OPENBB_Order ) {
				$saved->get_lines();
			}
		} finally {
			OPENBB_Inventory::unlock();
		}
		if ( $saved instanceof OPENBB_Order ) {
			/**
			 * Fires after an editor save commits and releases the inventory lock.
			 *
			 * @param OPENBB_Order      $order  Saved order.
			 * @param OPENBB_Order|null $before Previous snapshot; null for a new order.
			 */
			do_action( 'openbb_order_saved', $saved, $before && $before->id ? $before : null );
		}
		return $saved;
	}

	/**
	 * Base units of each product $order may hold: available stock, counting its own
	 * reservation, and never less than what it already reserves.
	 *
	 * @param int[] $product_ids
	 * @return array<int, float>
	 */
	public static function limits_for( array $product_ids, ?OPENBB_Order $order ): array {
		if ( ! $product_ids ) {
			return array();
		}

		$available = OPENBB_Inventory::get_available_for( $product_ids, $order );
		$own       = $order ? OPENBB_Inventory::get_order_reservations( $order ) : array();
		$limits    = array();
		foreach ( $available as $product_id => $quantity ) {
			$limits[ $product_id ] = max( $quantity, $own[ $product_id ] ?? 0.0, 0.0 );
		}

		return $limits;
	}

	/**
	 * Whole selling units (to 3 decimals, rounded down) in $base base units.
	 */
	public static function to_units( float $base, float $base_per_unit ): float {
		return $base_per_unit > 0 ? floor( round( $base / $base_per_unit, 6 ) * 1000 ) / 1000 : 0.0;
	}

	/**
	 * @return OPENBB_Order|WP_Error
	 */
	private static function apply_locked( OPENBB_Order $order, array $changes, int $actor_id, bool $by_member, ?OPENBB_Order &$before ) {
		$is_new = ! $order->id;

		// Re-read under the lock so the checks below see the latest saved state.
		if ( ! $is_new ) {
			$order = OPENBB_Orders::get( $order->id );
			if ( ! $order ) {
				return new WP_Error( 'openbb_order_not_found', __( 'Order not found.', 'open-bulk-buying' ), array( 'status' => 404 ) );
			}
			$expected = (string) ( $changes['expected_updated_at'] ?? '' );
			$revision = isset( $changes['expected_revision'] ) ? (int) $changes['expected_revision'] : null;
			if ( ( '' !== $expected && (string) $order->updated_at !== $expected )
				|| ( null !== $revision && $revision !== $order->revision ) ) {
				return new WP_Error( 'openbb_order_changed', __( 'This order was changed by someone else. Reload it and try again.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
		}

		// Freeze the line snapshots before save_with_lines() replaces database rows.
		$order->get_lines();
		$before     = clone $order;
		$old_status = $order->status;
		$new_status = isset( $changes['status'] ) ? (string) $changes['status'] : $old_status;
		$has_lines  = isset( $changes['lines'] );

		$valid = OPENBB_Order_Policy::validate_edit( $order, OPENBB_Cycles::get( $order->cycle_id ), $changes );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$lines      = array();
		$delete_ids = array();
		if ( $has_lines ) {
			$planned = self::plan_lines( $order, (array) $changes['lines'], OPENBB_Order::STATUS_DRAFT === $new_status && ! $order->submitted_at );
			if ( is_wp_error( $planned ) ) {
				return $planned;
			}
			list( $lines, $delete_ids ) = $planned;
		}

		if ( empty( $changes['reopen'] ) && $order->cycle_id && in_array( $new_status, array( OPENBB_Order::STATUS_DRAFT, OPENBB_Order::STATUS_SUBMITTED ), true ) ) {
			list( $lines, $delete_ids ) = OPENBB_Membership_Fees::synchronize( $order, self::final_lines( $order, $lines, $delete_ids ), $lines, $delete_ids );
		}
		$lines_changed = (bool) ( $lines || $delete_ids );
		if ( OPENBB_Order::STATUS_DRAFT === $old_status && OPENBB_Order::STATUS_SUBMITTED === $new_status && $order->submitted_at ) {
			foreach ( self::final_lines( $order, $lines, $delete_ids ) as $line ) {
				if ( $line->is_membership_fee() || $line->get_quantity() <= 0 ) {
					continue;
				}
				$product = OPENBB_Product::get( $line->product_id );
				if ( ! $product || ! $product->is_active() ) {
					return self::invalid( __( 'Remove unavailable products before resubmitting this order.', 'open-bulk-buying' ) );
				}
			}
		}
		if ( empty( $changes['reopen'] ) && OPENBB_Order::STATUS_CANCELLED !== $new_status && ( $lines_changed || $new_status !== $old_status ) ) {
			$check = self::check_stock( $order, $lines, $delete_ids );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		if ( isset( $changes['donation'] ) ) {
			$order->donation = max( 0, (int) $changes['donation'] );
		}
		$valid = OPENBB_Order_Policy::validate_content_change( $before, (bool) $lines_changed, $order->donation );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( isset( $changes['staff_note'] ) ) {
			$order->staff_note = (string) $changes['staff_note'];
		}
		if ( isset( $changes['member_note'] ) ) {
			$order->member_note = (string) $changes['member_note'];
		}
		$aid = self::apply_aid_request( $order, $changes );
		if ( is_wp_error( $aid ) ) {
			return $aid;
		}
		$order->status = $new_status;
		if ( OPENBB_Order::STATUS_SUBMITTED === $new_status && ! $order->submitted_at ) {
			$order->submitted_at = current_time( 'mysql', true );
		}

		$changed = $lines_changed
			|| $new_status !== $old_status
			|| $order->donation !== $before->donation;
		$reason  = trim( (string) ( $changes['reason'] ?? '' ) );
		if ( ! $by_member && ! $is_new && $changed && OPENBB_Order_Policy::needs_reason( $before, $new_status ) ) {
			if ( '' === $reason ) {
				return self::invalid( __( 'Explain why this order is being changed.', 'open-bulk-buying' ), 'openbb_reason_required' );
			}
			$actor             = get_userdata( $actor_id );
			$order->staff_note = trim(
				$order->staff_note . "\n" . sprintf(
					/* translators: 1: date, 2: staff name, 3: reason. */
					__( '[%1$s, %2$s] %3$s', 'open-bulk-buying' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
					$actor ? $actor->display_name : __( '(deleted user)', 'open-bulk-buying' ),
					$reason
				)
			);
		}

		$event_type = $is_new ? 'created' : 'edited';
		if ( ! empty( $changes['reopen'] ) ) {
			$event_type = 'reopened';
		}
		if ( $by_member ) {
			$event_type = OPENBB_Order::STATUS_SUBMITTED === $new_status && $new_status !== $old_status ? 'submitted' : 'member_' . $event_type;
		}
		$event = ( $is_new || $changed ) ? array(
			'actor_id'    => $actor_id,
			'event_type'  => $event_type,
			'from_status' => $is_new ? '' : $old_status,
			'reason'      => $reason,
		) : null;
		if ( ! $order->save_with_lines( $lines, $delete_ids, $event ) ) {
			return new WP_Error( 'openbb_order_save_failed', __( 'The order could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}

		if ( $by_member ) {
			$saved = OPENBB_Orders::get( $order->id ) ?? $order;
			if ( OPENBB_Order::STATUS_SUBMITTED === $new_status && $new_status !== $old_status ) {
				/**
				 * Fires after a member submits their order from the order form.
				 *
				 * @param OPENBB_Order $order The saved order.
				 */
				do_action( 'openbb_order_submitted', $saved );
			}
			return $saved;
		}

		if ( ! $is_new && $changed ) {
			/**
			 * Fires after staff change an existing order.
			 *
			 * @param OPENBB_Order $order    The saved order.
			 * @param OPENBB_Order $before   The order before the change.
			 * @param string       $reason   Why it changed; may be empty for drafts.
			 * @param int          $actor_id User who made the change.
			 */
			do_action( 'openbb_order_corrected', $order, $before, $reason, $actor_id );
		}

		return OPENBB_Orders::get( $order->id ) ?? $order;
	}

	/**
	 * Turns the requested lines into rows to write and ids to delete. Lines missing
	 * from the request are removed: deleted on drafts, zeroed otherwise so the
	 * submitted history is kept.
	 *
	 * @return array{0: OPENBB_Order_Line[], 1: int[]}|WP_Error
	 */
	private static function plan_lines( OPENBB_Order $order, array $input, bool $is_draft ) {
		$existing = array();
		foreach ( $order->get_lines() as $line ) {
			$existing[ $line->id ] = $line;
		}
		$products_on_order = array();
		foreach ( $existing as $line ) {
			$products_on_order[ $line->product_id ] = $line->id;
		}

		$write = array();
		$keep  = array();
		foreach ( $input as $row ) {
			$row     = (array) $row;
			$line_id = (int) ( $row['id'] ?? 0 );
			$qty     = $row['qty'] ?? 0;
			if ( ! is_numeric( $qty ) || ! is_finite( (float) $qty ) || $qty < 0 ) {
				return self::invalid( __( 'Purchase quantities must be non-negative whole numbers of selling packages.', 'open-bulk-buying' ) );
			}
			$qty     = (float) $qty;
			$own     = ! empty( $row['own_container'] );

			if ( $line_id ) {
				$line = $existing[ $line_id ] ?? null;
				if ( ! $line ) {
					return self::invalid( __( 'An order line no longer exists. Reload the order and try again.', 'open-bulk-buying' ) );
				}
				if ( isset( $keep[ $line_id ] ) ) {
					return self::invalid( __( 'Each line can only be listed once.', 'open-bulk-buying' ) );
				}
				$keep[ $line_id ] = true;
				if ( $line->is_membership_fee() ) {
					if ( abs( $qty - $line->qty_requested ) >= self::EPSILON || $own ) {
						return self::invalid( __( 'Membership fees are managed from the member payment screen.', 'open-bulk-buying' ) );
					}
					continue;
				}

				// Keep historical fractional quantities readable and unchanged.
				if ( $qty !== $line->qty_requested && floor( $qty ) !== $qty ) {
					return self::invalid( __( 'Purchase quantities must be non-negative whole numbers of selling packages.', 'open-bulk-buying' ) );
				}
				$is_prepared = null !== $line->qty_prepared || null !== $line->qty_final;
				$unchanged   = abs( $line->qty_requested - $qty ) < self::EPSILON && $line->own_container === $own;
				if ( $unchanged ) {
					continue;
				}
				if ( $is_prepared ) {
					return new WP_Error(
						'openbb_line_prepared',
						/* translators: %s: product name. */
						sprintf( __( '"%s" has already been prepared and cannot be changed here.', 'open-bulk-buying' ), $line->product_name ),
						array( 'status' => 409 )
					);
				}
				if ( $qty <= 0 && $is_draft ) {
					unset( $keep[ $line_id ] );
					continue;
				}

				$line                = clone $line;
				$line->qty_requested = $qty;
				$line->own_container = $own;
				$write[]             = $line;
				continue;
			}

			$product_id = (int) ( $row['product_id'] ?? 0 );
			if ( floor( $qty ) !== $qty ) {
				return self::invalid( __( 'Purchase quantities must be non-negative whole numbers of selling packages.', 'open-bulk-buying' ) );
			}
			if ( isset( $products_on_order[ $product_id ] ) ) {
				return self::invalid( __( 'A product can only appear once on an order. Change the existing line instead.', 'open-bulk-buying' ) );
			}
			if ( $qty <= 0 ) {
				return self::invalid( __( 'Enter a quantity for each new line.', 'open-bulk-buying' ) );
			}
			$product = $product_id ? OPENBB_Product::get( $product_id ) : null;
			if ( ! $product || ! $product->is_active() ) {
				return self::invalid( __( 'Only active products can be added to an order.', 'open-bulk-buying' ) );
			}
			$products_on_order[ $product_id ] = 0;

			$line                   = new OPENBB_Order_Line();
			$line->order_id         = (int) $order->id;
			$line->product_id       = $product->id;
			$line->source           = OPENBB_Order_Line::SOURCE_MEMBER;
			$line->product_name     = $product->get_name( $order->language );
			$line->brand            = $product->brand;
			$line->unit_amount      = $product->unit_amount;
			$line->unit_type        = $product->unit_type;
			$line->base_per_unit    = $product->get_base_per_unit();
			$line->unit_price       = $product->price;
			$line->container_charge = $product->container_charge;
			$line->own_container    = $own;
			$line->qty_requested    = $qty;
			$write[]                = $line;
		}

		$delete = array();
		foreach ( $existing as $line_id => $line ) {
			if ( isset( $keep[ $line_id ] ) || $line->is_membership_fee() ) {
				continue;
			}
			if ( null !== $line->qty_prepared || null !== $line->qty_final ) {
				return new WP_Error(
					'openbb_line_prepared',
					/* translators: %s: product name. */
					sprintf( __( '"%s" has already been prepared and cannot be removed here.', 'open-bulk-buying' ), $line->product_name ),
					array( 'status' => 409 )
				);
			}
			if ( $is_draft ) {
				$delete[] = $line_id;
			} elseif ( $line->qty_requested > 0 ) {
				$line                = clone $line;
				$line->qty_requested = 0.0;
				$write[]             = $line;
			}
		}

		return array( $write, $delete );
	}

	/**
	 * The lines $order will hold once $writes are written and $delete_ids removed.
	 *
	 * @param OPENBB_Order_Line[] $writes
	 * @param int[]               $delete_ids
	 * @return OPENBB_Order_Line[]
	 */
	private static function final_lines( OPENBB_Order $order, array $writes, array $delete_ids ): array {
		$final = array();
		foreach ( $order->get_lines() as $line ) {
			$final[ $line->id ] = $line;
		}
		foreach ( $delete_ids as $line_id ) {
			unset( $final[ $line_id ] );
		}
		$new_index = 0;
		foreach ( $writes as $line ) {
			$final[ $line->id ? $line->id : 'new' . ( $new_index++ ) ] = $line;
		}

		return array_values( $final );
	}

	/**
	 * Applies aid_request / aid_reason from $changes: a request is pending until staff
	 * decide it.
	 *
	 * @return true|WP_Error
	 */
	private static function apply_aid_request( OPENBB_Order $order, array $changes ) {
		if ( ! isset( $changes['aid_request'] ) && ! isset( $changes['aid_reason'] ) ) {
			return true;
		}

		$decided   = in_array( $order->aid_status, array( OPENBB_Order::AID_APPROVED, OPENBB_Order::AID_DECLINED ), true );
		$requested = isset( $changes['aid_request'] ) ? (bool) $changes['aid_request'] : OPENBB_Order::AID_NONE !== $order->aid_status;
		$reason    = $requested ? ( isset( $changes['aid_reason'] ) ? (string) $changes['aid_reason'] : $order->aid_reason ) : '';
		if ( $decided ) {
			if ( ! $requested || $reason !== $order->aid_reason ) {
				return new WP_Error( 'openbb_aid_decided', __( 'Your financial aid request has already been decided and can no longer be changed.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			return true;
		}

		$order->aid_status = $requested ? OPENBB_Order::AID_PENDING : OPENBB_Order::AID_NONE;
		$order->aid_reason = $reason;

		return true;
	}

	/**
	 * Compares what each product's lines will hold after saving with what the
	 * order may hold.
	 *
	 * @param OPENBB_Order_Line[] $writes
	 * @param int[]               $delete_ids
	 * @return true|WP_Error
	 */
	private static function check_stock( OPENBB_Order $order, array $writes, array $delete_ids ) {
		$wanted = array();
		$names  = array();
		foreach ( self::final_lines( $order, $writes, $delete_ids ) as $line ) {
			if ( $line->is_membership_fee() ) {
				continue;
			}
			$wanted[ $line->product_id ] = ( $wanted[ $line->product_id ] ?? 0.0 ) + $line->get_base_quantity();
			$names[ $line->product_id ]  = $line;
		}
		$limits = self::limits_for( array_keys( $wanted ), $order->id ? $order : null );

		foreach ( $wanted as $product_id => $base ) {
			$limit = $limits[ $product_id ] ?? 0.0;
			if ( $base > $limit + self::EPSILON ) {
				$line    = $names[ $product_id ];
				$max_qty = self::to_units( $limit, $line->base_per_unit );
				return new WP_Error(
					'openbb_insufficient_stock',
					sprintf(
						/* translators: 1: product name, 2: quantity available, 3: unit label. */
						__( 'Not enough stock for "%1$s": at most %2$s × %3$s can be ordered.', 'open-bulk-buying' ),
						$line->product_name,
						openbb_format_quantity( $max_qty ),
						$line->get_unit_label()
					),
					array(
						'status'     => 409,
						'product_id' => $product_id,
						'max_qty'    => $max_qty,
					)
				);
			}
		}

		return true;
	}

	private static function invalid( string $message, string $code = 'openbb_invalid_order' ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
