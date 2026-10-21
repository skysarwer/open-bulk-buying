<?php
/**
 * Stock ledger queries. One shared inventory across cycles.
 *
 * Quantities are in the product's base unit (g, ml or units; see units.php). Each
 * move also records the product's selling unit at the time, for display only.
 * On-hand stock is the sum of moves; reserved stock is derived from pending order lines.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Inventory {

	public static function schema(): array {
		global $wpdb;

		return array(
			$wpdb->openbb_stock_moves => "CREATE TABLE {$wpdb->openbb_stock_moves} (
				id bigint(20) unsigned NOT NULL auto_increment,
				product_id bigint(20) unsigned NOT NULL,
				quantity decimal(12,3) NOT NULL,
				unit_amount decimal(12,3) NOT NULL default 1,
				unit_type varchar(20) NOT NULL default 'unit',
				reason varchar(30) NOT NULL,
				cycle_id bigint(20) unsigned NULL default NULL,
				order_id bigint(20) unsigned NULL default NULL,
				user_id bigint(20) unsigned NOT NULL default 0,
				request_key varchar(64) NULL default NULL,
				note text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY request_key (request_key),
				KEY product_id (product_id),
				KEY cycle_id (cycle_id),
				KEY order_id (order_id)
			) {$wpdb->get_charset_collate()};",
		);
	}

	public static function get_on_hand( int $product_id ): float {
		global $wpdb;

		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity), 0) FROM {$wpdb->openbb_stock_moves} WHERE product_id = %d", $product_id ) );
	}

	/**
	 * Base units held by submitted/prepaid, uncollected orders with an active hold.
	 * Uses each line's latest quantity (final, else prepared, else requested).
	 */
	public static function get_reserved( int $product_id ): float {
		global $wpdb;

		$condition = self::reservation_condition();

		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(COALESCE(l.qty_final, l.qty_prepared, l.qty_requested) * l.base_per_unit), 0)
				FROM {$wpdb->openbb_order_lines} l
				INNER JOIN {$wpdb->openbb_orders} o ON o.id = l.order_id
				INNER JOIN {$wpdb->openbb_cycles} c ON c.id = o.cycle_id
				WHERE l.product_id = %d AND {$condition}",
				$product_id
			)
		);
	}

	/**
	 * On-hand base units for several products at once.
	 *
	 * @param int[] $product_ids
	 * @return array<int, float> Product id => on-hand; products without moves are 0.
	 */
	public static function get_on_hand_for( array $product_ids ): array {
		global $wpdb;

		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );
		$totals      = array_fill_keys( $product_ids, 0.0 );
		if ( ! $product_ids ) {
			return $totals;
		}

		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT product_id, SUM(quantity) AS total FROM {$wpdb->openbb_stock_moves} WHERE product_id IN ({$placeholders}) GROUP BY product_id", ...$product_ids ),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$totals[ (int) $row['product_id'] ] = (float) $row['total'];
		}

		return $totals;
	}

	/**
	 * Reserved base units for several products at once; see get_reserved().
	 *
	 * @param int[] $product_ids
	 * @return array<int, float> Product id => reserved.
	 */
	public static function get_reserved_for( array $product_ids ): array {
		global $wpdb;

		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );
		$totals      = array_fill_keys( $product_ids, 0.0 );
		if ( ! $product_ids ) {
			return $totals;
		}

		$condition = self::reservation_condition();
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.product_id, SUM(COALESCE(l.qty_final, l.qty_prepared, l.qty_requested) * l.base_per_unit) AS total
				FROM ' . $wpdb->openbb_order_lines . ' l
				INNER JOIN ' . $wpdb->openbb_orders . ' o ON o.id = l.order_id
				INNER JOIN ' . $wpdb->openbb_cycles . ' c ON c.id = o.cycle_id
				WHERE l.product_id IN (' . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ')
				AND ' . $condition . '
				GROUP BY l.product_id',
				...$product_ids
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$totals[ (int) $row['product_id'] ] = (float) $row['total'];
		}

		return $totals;
	}

	/**
	 * Whether $order's lines currently count towards reserved stock (see get_reserved()).
	 */
	public static function order_reserves( OPENBB_Order $order, ?OPENBB_Cycle $cycle = null ): bool {
		if ( ! $order->id || ! in_array( $order->status, OPENBB_Order::COMMITTED_STATUSES, true ) || $order->is_collected() ) {
			return false;
		}
		$cycle = $cycle ?? OPENBB_Cycles::get( $order->cycle_id );

		if ( ! $cycle ) {
			return false;
		}
		$until = $cycle->hold_until( $order->status );
		return null !== $until ? ! $order->is_abandoned( $cycle ) : $cycle->is_active();
	}

	/**
	 * Shared by single-product and batch queries; aliases are fixed internal SQL.
	 */
	private static function reservation_condition(): string {
		global $wpdb;

		$committed   = OPENBB_Order::COMMITTED_STATUSES;
		$active      = OPENBB_Cycle::ACTIVE_STATUSES;
		$paid_hold   = OPENBB_Cycle::hold_field( OPENBB_Order::STATUS_PREPAID );
		$unpaid_hold = OPENBB_Cycle::hold_field( OPENBB_Order::STATUS_SUBMITTED );
		$deadline    = $wpdb->prepare( "CASE WHEN o.status = %s THEN c.{$paid_hold} ELSE c.{$unpaid_hold} END", OPENBB_Order::STATUS_PREPAID );

		return $wpdb->prepare(
			'o.status IN (' . implode( ',', array_fill( 0, count( $committed ), '%s' ) ) . ") AND o.collected_at IS NULL
			AND (
				({$deadline}) > UTC_TIMESTAMP()
				OR (
					({$deadline}) IS NULL
					AND c.status IN (" . implode( ',', array_fill( 0, count( $active ), '%s' ) ) . ')
				)
			)',
			...array_merge( $committed, $active )
		);
	}

	/**
	 * Base units of each product that $order's saved lines reserve; empty if it reserves nothing.
	 *
	 * @return array<int, float> Product id => reserved base units.
	 */
	public static function get_order_reservations( OPENBB_Order $order ): array {
		if ( ! self::order_reserves( $order ) ) {
			return array();
		}

		$reserved = array();
		foreach ( $order->get_lines() as $line ) {
			$reserved[ $line->product_id ] = ( $reserved[ $line->product_id ] ?? 0.0 ) + $line->get_base_quantity();
		}

		return $reserved;
	}

	/**
	 * Base units of each product available to new reservations: on hand minus reserved.
	 * Reservations made by $exclude (the order being edited) count as available, so an
	 * order can keep what it already holds.
	 *
	 * @param int[] $product_ids
	 * @return array<int, float> Product id => available base units (may be negative when oversold).
	 */
	public static function get_available_for( array $product_ids, ?OPENBB_Order $exclude = null ): array {
		$on_hand  = self::get_on_hand_for( $product_ids );
		$reserved = self::get_reserved_for( $product_ids );
		$own      = $exclude ? self::get_order_reservations( $exclude ) : array();

		$available = array();
		foreach ( $on_hand as $product_id => $quantity ) {
			$available[ $product_id ] = round( $quantity - $reserved[ $product_id ] + ( $own[ $product_id ] ?? 0.0 ), 3 );
		}

		return $available;
	}

	/**
	 * Serializes stock-changing writes (manual moves, order saves) for this site, so an
	 * availability check and the write that depends on it can't interleave with another
	 * request. Always pair with unlock().
	 */
	public static function lock( int $timeout = 10 ): bool {
		global $wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), $timeout ) );
	}

	public static function unlock(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	private static function lock_name(): string {
		global $wpdb;

		return substr( $wpdb->prefix . 'openbb_stock', 0, 64 );
	}

	/**
	 * @return OPENBB_Stock_Move[] Newest first.
	 */
	public static function get_moves( int $product_id, int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		$sql = $wpdb->prepare( "SELECT * FROM {$wpdb->openbb_stock_moves} WHERE product_id = %d ORDER BY created_at DESC, id DESC", $product_id );
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, max( 0, $offset ) );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			static function ( array $row ): OPENBB_Stock_Move {
				return new OPENBB_Stock_Move( $row );
			},
			(array) $rows
		);
	}

	public static function count_moves( int $product_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_stock_moves} WHERE product_id = %d", $product_id ) );
	}

	/**
	 * Appends a manual receipt, adjustment, spoilage or distribution move. Rejects moves that would
	 * take on-hand stock below zero, and replays of the same request_key return the
	 * move already recorded instead of adding another.
	 *
	 * Persistence only: callers check openbb_manage_inventory and the request nonce.
	 *
	 * @return OPENBB_Stock_Move|WP_Error
	 */
	public static function record_manual_move( OPENBB_Product $product, float $quantity, string $reason, string $note = '', string $request_key = '' ) {
		global $wpdb;

		$quantity = round( $quantity, 3 );
		if ( ! in_array( $reason, OPENBB_Stock_Move::MANUAL_REASONS, true ) ) {
			return new WP_Error( 'openbb_invalid_stock_reason', __( 'Choose a receipt, adjustment, spoilage or distribution.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}
		$removes_stock = in_array( $reason, array( OPENBB_Stock_Move::REASON_SPOILAGE, OPENBB_Stock_Move::REASON_DISTRIBUTION ), true );
		if ( 0.0 === $quantity
			|| ( OPENBB_Stock_Move::REASON_RECEIPT === $reason && $quantity < 0 )
			|| ( $removes_stock && $quantity > 0 ) ) {
			return new WP_Error( 'openbb_invalid_stock_quantity', __( 'Receipts add stock; spoilage and distribution remove it. Enter a non-zero quantity.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}

		if ( ! self::lock() ) {
			return new WP_Error( 'openbb_stock_busy', __( 'Inventory is being updated by someone else. Try again in a moment.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		try {
			if ( '' !== $request_key ) {
				$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->openbb_stock_moves} WHERE request_key = %s", $request_key ), ARRAY_A );
				if ( $existing ) {
					$move = new OPENBB_Stock_Move( $existing );
					return $move->product_id === $product->id
						? $move
						: new WP_Error( 'openbb_stock_request_conflict', __( 'This stock request was already used for another product.', 'open-bulk-buying' ), array( 'status' => 409 ) );
				}
			}

			$on_hand = self::get_on_hand( $product->id );
			if ( round( $on_hand + $quantity, 3 ) < 0 ) {
				return new WP_Error(
					'openbb_stock_negative',
					sprintf(
						/* translators: %s: quantity on hand in base units, e.g. "600 g". */
						__( 'Only %s is on hand; stock cannot go below zero.', 'open-bulk-buying' ),
						openbb_format_unit( $on_hand, openbb_base_unit_type( $product->unit_type ) )
					),
					array( 'status' => 409 )
				);
			}

			$move = new OPENBB_Stock_Move(
				array(
					'product_id'  => $product->id,
					'quantity'    => $quantity,
					'unit_amount' => $product->unit_amount,
					'unit_type'   => $product->unit_type,
					'reason'      => $reason,
					'user_id'     => get_current_user_id(),
					'request_key' => '' === $request_key ? null : $request_key,
					'note'        => $note,
				)
			);
			if ( ! $move->save() ) {
				return new WP_Error( 'openbb_stock_save_failed', __( 'The stock change could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
			}

			return $move;
		} finally {
			self::unlock();
		}
	}
}
