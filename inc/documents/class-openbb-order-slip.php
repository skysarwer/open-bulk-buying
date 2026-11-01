<?php
/**
 * Order slips handed out at distribution: one page per order with the member's name,
 * pickup details, what to pack and whether the order is paid.
 *
 * Slips show no prices per line, contact details, staff notes or financial-aid details.
 * Each slip is printed in the order's language.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Order_Slip extends OPENBB_Document {

	const STATUSES   = array( OPENBB_Order::STATUS_SUBMITTED, OPENBB_Order::STATUS_PREPAID, OPENBB_Order::STATUS_FULFILLED );
	const MAX_ORDERS = 500;

	/** @var OPENBB_Order[] */
	private $orders;

	/** @var array<int, OPENBB_Order_Line[]> */
	private $lines = array();

	/** @var array<int, OPENBB_Cycle|null> */
	private $cycles = array();

	/**
	 * @param OPENBB_Order[] $orders Eligible orders, in print order.
	 */
	public function __construct( array $orders ) {
		$this->orders = array_values( $orders );
		$this->load();
	}

	public static function is_eligible( OPENBB_Order $order ): bool {
		return $order->cycle_id > 0 && in_array( $order->status, self::STATUSES, true );
	}

	/**
	 * Eligible orders sorted by last name, first name, then order number.
	 *
	 * @param array{order_ids?: int[], cycle_id?: int, statuses?: string[], paid?: string, search?: string} $args
	 * @return OPENBB_Order[]|WP_Error
	 */
	public static function query( array $args ) {
		global $wpdb;

		$statuses = array_values( array_intersect( self::STATUSES, $args['statuses'] ?? array() ) );
		$statuses = $statuses ? $statuses : self::STATUSES;
		$where    = array( 'o.cycle_id > 0', 'o.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')' );
		$params   = $statuses;

		$order_ids = array_values( array_filter( array_map( 'intval', $args['order_ids'] ?? array() ) ) );
		if ( $order_ids ) {
			$where[] = 'o.id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $order_ids );
		}
		if ( ! empty( $args['cycle_id'] ) ) {
			$where[]  = 'o.cycle_id = %d';
			$params[] = (int) $args['cycle_id'];
		}
		if ( 'paid' === ( $args['paid'] ?? '' ) ) {
			$where[] = 'o.paid_at IS NOT NULL';
		} elseif ( 'unpaid' === ( $args['paid'] ?? '' ) ) {
			$where[] = 'o.paid_at IS NULL';
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(o.id = %d OR u.display_name LIKE %s OR u.user_email LIKE %s)';
			$params[] = ctype_digit( ltrim( $search, '#' ) ) ? (int) ltrim( $search, '#' ) : 0;
			$params[] = $like;
			$params[] = $like;
		}
		$params[] = self::MAX_ORDERS + 1;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT o.* FROM {$wpdb->openbb_orders} o
				LEFT JOIN {$wpdb->users} u ON u.ID = o.user_id
				LEFT JOIN {$wpdb->usermeta} ln ON ln.user_id = o.user_id AND ln.meta_key = 'last_name'
				LEFT JOIN {$wpdb->usermeta} fn ON fn.user_id = o.user_id AND fn.meta_key = 'first_name'
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY COALESCE(NULLIF(ln.meta_value, ''), NULLIF(fn.meta_value, ''), u.display_name) ASC,
					fn.meta_value ASC, o.id ASC
				LIMIT %d",
				...$params
			),
			ARRAY_A
		);
		if ( $wpdb->last_error ) {
			return new WP_Error( 'openbb_document_failed', __( 'The order slips could not be prepared.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}
		if ( count( (array) $rows ) > self::MAX_ORDERS ) {
			return new WP_Error(
				'openbb_too_many_documents',
				/* translators: %d: maximum number of order slips. */
				sprintf( __( 'Print at most %d order slips at a time. Narrow the list and try again.', 'open-bulk-buying' ), self::MAX_ORDERS ),
				array( 'status' => 400 )
			);
		}

		return array_map(
			static function ( array $row ): OPENBB_Order {
				return new OPENBB_Order( $row );
			},
			(array) $rows
		);
	}

	public function type(): string {
		return 'order-slip';
	}

	public function title(): string {
		if ( 1 === count( $this->orders ) ) {
			/* translators: %d: order number. */
			return sprintf( __( 'Order slip #%d', 'open-bulk-buying' ), $this->orders[0]->id );
		}
		/* translators: %d: number of order slips. */
		return sprintf( _n( '%d order slip', '%d order slips', count( $this->orders ), 'open-bulk-buying' ), count( $this->orders ) );
	}

	public function pages(): array {
		return array_map(
			function ( OPENBB_Order $order ): array {
				return array(
					'template' => 'order-slip',
					'locale'   => $order->locale(),
					'data'     => function () use ( $order ): array {
						return $this->slip( $order );
					},
				);
			},
			$this->orders
		);
	}

	/**
	 * What one slip shows. Built in the order's locale.
	 */
	private function slip( OPENBB_Order $order ): array {
		$cycle  = $this->cycles[ $order->cycle_id ] ?? null;
		$items  = array();
		$extras = array();
		foreach ( $this->lines[ $order->id ] ?? array() as $line ) {
			if ( $line->get_quantity() <= 0 ) {
				continue;
			}
			if ( $line->is_membership_fee() ) {
				$extras[] = '' !== $line->product_name ? $line->product_name : __( 'Membership fee', 'open-bulk-buying' );
				continue;
			}
			$items[] = array(
				'name'          => $line->product_name,
				'brand'         => $line->brand,
				'quantity'      => openbb_format_quantity( $line->get_quantity() ),
				'unit'          => $line->get_unit_label(),
				'own_container' => $line->own_container,
			);
		}

		$paid = null !== $order->paid_at;

		return array(
			/* translators: %d: order number. */
			'order_number' => sprintf( __( 'Order #%d', 'open-bulk-buying' ), $order->id ),
			'member_name'  => self::member_name( $order->user_id ),
			'cycle_name'   => $cycle ? $cycle->name : '',
			'pickup_when'  => $cycle ? $cycle->pickup_window() : '',
			'pickup_where' => $cycle ? trim( $cycle->pickup_location ) : '',
			'items'        => $items,
			'extras'       => $extras,
			'paid'         => $paid,
			'paid_on'      => $paid ? wp_date( get_option( 'date_format' ), strtotime( $order->paid_at . ' UTC' ) ) : '',
			'amount_due'   => $paid ? '' : ( preg_match( '/^[A-Z]{3}$/', $order->currency ) ? openbb_format_money( $order->total, $order->currency ) : '' ),
			'member_note'  => trim( $order->member_note ),
		);
	}

	/**
	 * "Last, First" so a pile of slips sorted by last name is easy to search; the
	 * display name when either part is missing.
	 */
	private static function member_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return __( 'Deleted member', 'open-bulk-buying' );
		}
		$first = trim( (string) $user->first_name );
		$last  = trim( (string) $user->last_name );
		if ( '' !== $first && '' !== $last ) {
			/* translators: 1: last name, 2: first name. */
			return sprintf( __( '%1$s, %2$s', 'open-bulk-buying' ), $last, $first );
		}

		return '' !== $user->display_name ? $user->display_name : $user->user_login;
	}

	/**
	 * Loads every slip's lines, members and cycles up front.
	 */
	private function load(): void {
		global $wpdb;

		if ( ! $this->orders ) {
			return;
		}
		$ids  = wp_list_pluck( $this->orders, 'id' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->openbb_order_lines} WHERE order_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY order_id, id', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$ids
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$line                              = new OPENBB_Order_Line( $row );
			$this->lines[ $line->order_id ][] = $line;
		}

		cache_users( array_unique( wp_list_pluck( $this->orders, 'user_id' ) ) );
		foreach ( array_unique( wp_list_pluck( $this->orders, 'cycle_id' ) ) as $cycle_id ) {
			$this->cycles[ $cycle_id ] = OPENBB_Cycles::get( (int) $cycle_id );
		}
	}
}
