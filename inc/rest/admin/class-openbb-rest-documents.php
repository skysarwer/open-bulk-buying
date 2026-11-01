<?php
/**
 * Printable documents for staff. Opened as a plain link in a new tab, so the wp_rest
 * nonce travels in the query string (_wpnonce) and the response is HTML.
 *
 * Order slips need openbb_view_orders, or openbb_process_orders while the order's cycle
 * is being prepared or distributed. Members never reach this route for their own orders.
 * Orders the viewer cannot see are reported as not found.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_REST_Documents extends OPENBB_REST_Controller {

	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/documents/order-slip',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'order_slips' ),
					'permission_callback' => static function () {
						return self::authorize_any( array( 'openbb_view_orders', 'openbb_process_orders' ) );
					},
					'args'                => array(
						'orders'    => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'maxItems' => OPENBB_Order_Slip::MAX_ORDERS,
							'default'  => array(),
						),
						'cycle_id'  => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
						'status'    => array(
							'type'    => 'array',
							'items'   => array(
								'type' => 'string',
								'enum' => OPENBB_Order_Slip::STATUSES,
							),
							'default' => array(),
						),
						'paid'      => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'paid', 'unpaid' ),
							'default' => 'all',
						),
						'search'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'autoprint' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function order_slips( WP_REST_Request $request ) {
		$order_ids = array_values( array_unique( array_map( 'intval', (array) $request['orders'] ) ) );
		$cycle_id  = (int) $request['cycle_id'];
		if ( ! $order_ids && ! $cycle_id ) {
			return self::invalid( __( 'Choose the orders or the cycle to print.', 'open-bulk-buying' ) );
		}

		$not_found = self::not_found( __( 'Order not found.', 'open-bulk-buying' ) );
		if ( $cycle_id && ! self::can_print_cycle( $cycle_id ) ) {
			return $not_found;
		}
		if ( $order_ids ) {
			$order_cycles = self::order_cycles( $order_ids );
			if ( in_array( null, $order_cycles, true ) ) {
				return $not_found;
			}
			foreach ( array_unique( $order_cycles ) as $order_cycle ) {
				if ( ! self::can_print_cycle( $order_cycle ) ) {
					return $not_found;
				}
			}
		}

		if ( 1 !== count( $order_ids ) ) {
			$allowed = OPENBB_Rate_Limit::hit( 'documents_' . get_current_user_id(), 30, MINUTE_IN_SECONDS );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$orders = OPENBB_Order_Slip::query(
			array(
				'order_ids' => $order_ids,
				'cycle_id'  => $cycle_id,
				'statuses'  => (array) $request['status'],
				'paid'      => $request['paid'],
				'search'    => $request['search'],
			)
		);
		if ( is_wp_error( $orders ) ) {
			return $orders;
		}

		return OPENBB_Documents::response( new OPENBB_Order_Slip( $orders ), array( 'autoprint' => (bool) $request['autoprint'] ) );
	}

	private static function can_print_cycle( int $cycle_id ): bool {
		if ( ! OPENBB_Cycles::get( $cycle_id ) ) {
			return false;
		}
		return current_user_can( 'openbb_view_orders' ) || OPENBB_REST_Orders::can_process_cycle( $cycle_id );
	}

	/**
	 * Each requested order's cycle: null for a missing or membership order.
	 *
	 * @param int[] $order_ids
	 * @return array<int, int|null>
	 */
	private static function order_cycles( array $order_ids ): array {
		global $wpdb;

		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, cycle_id FROM {$wpdb->openbb_orders} WHERE id IN (" . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$order_ids
			),
			ARRAY_A
		);
		$cycles = array_fill_keys( $order_ids, null );
		foreach ( (array) $rows as $row ) {
			$cycles[ (int) $row['id'] ] = (int) $row['cycle_id'] ? (int) $row['cycle_id'] : null;
		}

		return $cycles;
	}
}
