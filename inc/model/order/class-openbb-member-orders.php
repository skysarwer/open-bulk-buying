<?php
/**
 * Members placing their own orders from the order form.
 *
 * A member's basket is their order in the cycle: saved as a draft while they shop
 * (drafts reserve no stock), then submitted. While the cycle takes orders they can
 * keep changing a submitted order. Saving goes through OPENBB_Order_Editor, so stock
 * limits, locking and conflict checks are the same as for staff.
 *
 * Quantities are whole selling units here, even though order lines allow decimals.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_Member_Orders {

	/** The most of one product a member can order. */
	const MAX_QTY = 999;

	/** Longest member note and aid reason, in characters. */
	const MAX_TEXT = 2000;

	/** Statuses a member can still change. */
	const EDITABLE_STATUSES = array( OPENBB_Order::STATUS_DRAFT, OPENBB_Order::STATUS_SUBMITTED );

	/**
	 * Cycles taking orders now, soonest closing first.
	 *
	 * @return OPENBB_Cycle[]
	 */
	public static function open_cycles( ?int $timestamp = null ): array {
		$cycles = array_values(
			array_filter(
				OPENBB_Cycles::get_active(),
				static function ( OPENBB_Cycle $cycle ) use ( $timestamp ): bool {
					return $cycle->accepts_orders( $timestamp );
				}
			)
		);
		usort(
			$cycles,
			static function ( OPENBB_Cycle $a, OPENBB_Cycle $b ): int {
				return strcmp( (string) $a->closes_at, (string) $b->closes_at ) ?: $a->id <=> $b->id;
			}
		);

		return $cycles;
	}

	/**
	 * The next cycle due to open for ordering, if one is scheduled.
	 */
	public static function next_cycle( ?int $timestamp = null ): ?OPENBB_Cycle {
		$now  = $timestamp ?? time();
		$next = null;
		foreach ( OPENBB_Cycles::get_active() as $cycle ) {
			if ( ! in_array( $cycle->status, array( OPENBB_Cycle::STATUS_SETUP, OPENBB_Cycle::STATUS_ORDERING ), true )
				|| ! $cycle->opens_at || strtotime( $cycle->opens_at . ' UTC' ) <= $now ) {
				continue;
			}
			if ( ! $next || strcmp( $cycle->opens_at, $next->opens_at ) < 0 ) {
				$next = $cycle;
			}
		}

		return $next;
	}

	/**
	 * Whether the member can change $order (null: start one) in $cycle now.
	 */
	public static function can_edit( ?OPENBB_Order $order, OPENBB_Cycle $cycle ): bool {
		if ( ! $cycle->accepts_orders() ) {
			return false;
		}

		return ! $order || ( in_array( $order->status, self::EDITABLE_STATUSES, true ) && OPENBB_Order_Policy::can_edit_contents( $order, $cycle ) );
	}

	/**
	 * Saves the member's basket and order details.
	 *
	 * $input keys (all optional):
	 * - lines               List of { product_id, qty, own_container? }: the whole basket.
	 *                       Products left out are removed.
	 * - donation            Minor units.
	 * - aid_request         Ask the co-op for financial aid; needs aid_reason.
	 * - aid_reason, member_note
	 * - expected_revision   The order's revision from the last response, to detect
	 *                       changes made elsewhere.
	 * - submit              Commit a draft. Requires commit and at least one item.
	 * - commit              The member agrees to collect and pay for the order.
	 *
	 * Errors with a field have data 'errors' => array( field => message ).
	 *
	 * @return OPENBB_Order|null|WP_Error The saved order; null when an empty basket
	 *                                    had nothing to save yet.
	 */
	public static function save( OPENBB_Cycle $cycle, int $user_id, array $input ) {
		$member = OPENBB_Member::get( $user_id );
		if ( ! $member || ! $member->is_active() || ! user_can( $user_id, 'openbb_place_orders' ) ) {
			return new WP_Error( 'openbb_not_member', __( 'Only active co-op members can place orders.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}
		if ( ! $cycle->accepts_orders() ) {
			return new WP_Error( 'openbb_ordering_closed', __( 'Ordering is closed for this cycle.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		$order = OPENBB_Orders::get_for_member( $cycle->id, $user_id );
		if ( $order && ! self::can_edit( $order, $cycle ) ) {
			if ( OPENBB_Order::STATUS_CANCELLED === $order->status ) {
				return new WP_Error( 'openbb_order_locked', __( 'This order was cancelled. Reload the page to view its summary, or contact the co-op to have it reopened.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			return new WP_Error( 'openbb_order_locked', __( 'This order can no longer be changed here. Contact the co-op if something needs fixing.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		$basket = self::read_basket( $input['lines'] ?? array() );
		if ( is_wp_error( $basket ) ) {
			return $basket;
		}
		$has_items = (bool) array_filter(
			$basket,
			static function ( array $item ): bool {
				return $item['qty'] > 0;
			}
		);

		$submit    = ! empty( $input['submit'] );
		$submitted = $order && OPENBB_Order::STATUS_SUBMITTED === $order->status;
		if ( ( $submit || $submitted ) && ! $has_items ) {
			return self::field_error( 'lines', $submitted ? __( 'A submitted order needs at least one item. To cancel it, contact the co-op.', 'open-bulk-buying' ) : __( 'Add at least one item before submitting your order.', 'open-bulk-buying' ) );
		}
		if ( $submit && ! $submitted && empty( $input['commit'] ) ) {
			return self::field_error( 'commit', __( 'Confirm that you will pick up and pay for your order.', 'open-bulk-buying' ) );
		}

		$changes = array( 'lines' => self::editor_lines( $order, $basket ) );
		if ( isset( $input['donation'] ) ) {
			$changes['donation'] = max( 0, (int) $input['donation'] );
		}
		if ( isset( $input['aid_request'] ) ) {
			$changes['aid_request'] = (bool) $input['aid_request'];
		}
		foreach ( array( 'member_note', 'aid_reason' ) as $text ) {
			if ( isset( $input[ $text ] ) ) {
				$changes[ $text ] = mb_substr( sanitize_textarea_field( (string) $input[ $text ] ), 0, self::MAX_TEXT );
			}
		}
		if ( ! empty( $changes['aid_request'] ) && '' === trim( (string) ( $changes['aid_reason'] ?? ( $order ? $order->aid_reason : '' ) ) ) ) {
			return self::field_error( 'aid_reason', __( 'Tell us briefly why you are asking for financial aid.', 'open-bulk-buying' ) );
		}
		if ( $submit && ! $submitted ) {
			$changes['status'] = OPENBB_Order::STATUS_SUBMITTED;
		}
		if ( $order && isset( $input['expected_revision'] ) && '' !== $input['expected_revision'] ) {
			$changes['expected_revision'] = (int) $input['expected_revision'];
		}

		if ( ! $order ) {
			$has_details = ! empty( $changes['donation'] ) || ! empty( $changes['aid_request'] ) || '' !== trim( (string) ( $changes['member_note'] ?? '' ) );
			if ( ! $has_items && ! $has_details ) {
				return null;
			}
			$order = OPENBB_Order_Editor::start( $cycle, $user_id );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
		}

		return OPENBB_Order_Editor::apply( $order, $changes, $user_id, true );
	}

	/**
	 * product_id => array( qty, own_container ) from the requested basket.
	 *
	 * @return array<int, array{qty: int, own_container: bool}>|WP_Error
	 */
	private static function read_basket( $lines ) {
		if ( ! is_array( $lines ) ) {
			return self::field_error( 'lines', __( 'The basket could not be read. Reload the page and try again.', 'open-bulk-buying' ) );
		}

		$basket = array();
		foreach ( $lines as $row ) {
			$row        = (array) $row;
			$product_id = (int) ( $row['product_id'] ?? 0 );
			$qty        = $row['qty'] ?? 0;
			if ( $product_id <= 0 || isset( $basket[ $product_id ] ) ) {
				return self::field_error( 'lines', __( 'The basket could not be read. Reload the page and try again.', 'open-bulk-buying' ) );
			}
			if ( ! is_numeric( $qty ) || (float) $qty !== floor( (float) $qty ) || $qty < 0 || $qty > self::MAX_QTY ) {
				return self::field_error(
					'lines',
					/* translators: %d: maximum quantity. */
					sprintf( __( 'Quantities must be whole numbers from 0 to %d.', 'open-bulk-buying' ), self::MAX_QTY )
				);
			}
			$basket[ $product_id ] = array(
				'qty'           => (int) $qty,
				'own_container' => ! empty( $row['own_container'] ),
			);
		}

		return $basket;
	}

	/**
	 * The editor's full line list: existing lines by id, new products by product_id.
	 * Products dropped from the basket are left out, which removes them.
	 */
	private static function editor_lines( ?OPENBB_Order $order, array $basket ): array {
		$lines = array();
		$seen  = array();
		foreach ( $order ? $order->get_lines() : array() as $line ) {
			if ( ! isset( $basket[ $line->product_id ] ) ) {
				continue;
			}
			$seen[ $line->product_id ] = true;
			$lines[]                   = array(
				'id'            => $line->id,
				'qty'           => $basket[ $line->product_id ]['qty'],
				'own_container' => $basket[ $line->product_id ]['own_container'],
			);
		}
		foreach ( $basket as $product_id => $item ) {
			if ( isset( $seen[ $product_id ] ) || $item['qty'] <= 0 ) {
				continue;
			}
			$lines[] = array(
				'product_id'    => $product_id,
				'qty'           => $item['qty'],
				'own_container' => $item['own_container'],
			);
		}

		return $lines;
	}

	/**
	 * Whole selling units of each product the member's order may hold.
	 *
	 * @param OPENBB_Product[] $products
	 * @return array<int, int> product_id => max quantity.
	 */
	public static function limits( array $products, ?OPENBB_Order $order ): array {
		$by_id = array();
		foreach ( $products as $product ) {
			$by_id[ $product->id ] = $product;
		}
		$limits = array();
		foreach ( OPENBB_Order_Editor::limits_for( array_keys( $by_id ), $order && $order->id ? $order : null ) as $product_id => $base ) {
			$limits[ $product_id ] = min( self::MAX_QTY, (int) floor( OPENBB_Order_Editor::to_units( $base, $by_id[ $product_id ]->get_base_per_unit() ) + OPENBB_Order_Editor::EPSILON ) );
		}

		return $limits;
	}

	/**
	 * What the order form needs to know about $order (null: nothing saved yet).
	 * Line prices are the ones captured when each line was added.
	 */
	public static function state( ?OPENBB_Order $order, OPENBB_Cycle $cycle ): array {
		$lines = array();
		foreach ( $order ? $order->get_lines() : array() as $line ) {
			if ( $line->is_membership_fee() || $line->qty_requested <= 0 ) {
				continue;
			}
			$lines[] = array(
				'product_id'       => $line->product_id,
				'name'             => $line->product_name,
				'unit'             => $line->get_unit_label(),
				'qty'              => (int) round( $line->qty_requested ),
				'own_container'    => $line->own_container,
				'unit_price'       => $line->unit_price,
				'container_charge' => $line->container_charge,
			);
		}

		$user_id = $order ? $order->user_id : get_current_user_id();
		$fee_due = OPENBB_Membership_Fees::due_amount( $user_id, $order ? $order->id : 0 );
		$fee     = $order && ! self::can_edit( $order, $cycle ) ? $order->membership_fee() : ( $fee_due > 0 ? ( $order && $order->membership_fee() > 0 ? $order->membership_fee() : $fee_due ) : 0 );
		$summary = OPENBB_Membership_Fees::summary( $user_id );
		return array(
			'id'            => $order ? $order->id : 0,
			'status'        => $order ? $order->status : OPENBB_Order::STATUS_DRAFT,
			'revision'      => $order ? $order->revision : 0,
			'editable'      => self::can_edit( $order, $cycle ),
			'lines'         => $lines,
			'donation'      => $order ? $order->donation : 0,
			'membership_fee' => $fee,
			'membership_pending' => 'pending' === $summary['status'],
			'aid_status'    => $order ? $order->aid_status : OPENBB_Order::AID_NONE,
			'aid_approved'  => $order ? $order->aid_approved : 0,
			'aid_reason'    => $order ? $order->aid_reason : '',
			'member_note'   => $order ? $order->member_note : '',
			'subtotal'      => $order ? $order->subtotal : 0,
			'credit'        => $order ? $order->container_credit : 0,
			'total'         => $order ? $order->total : 0,
		);
	}

	private static function field_error( string $field, string $message ): WP_Error {
		return new WP_Error(
			'openbb_invalid_order',
			$message,
			array(
				'status' => 400,
				'errors' => array( $field => $message ),
			)
		);
	}
}
