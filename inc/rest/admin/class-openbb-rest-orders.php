<?php
/**
 * Staff order management: list, view, create and correct orders.
 *
 * The editing rules (stock limits, locking, reasons) live in OPENBB_Order_Editor;
 * this controller checks permissions, maps the request and shapes responses.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_REST_Orders extends OPENBB_REST_Controller {

	public function register_routes(): void {
		$can_view = static function () {
			return self::authorize( 'openbb_view_orders' );
		};
		$can_edit = static function () {
			return self::authorize( 'openbb_edit_orders' );
		};

		register_rest_route(
			self::API_NAMESPACE,
			'/orders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $can_view,
					'args'                => self::collection_args(
						array( 'id', 'submitted_at', 'total', 'member' ),
						'desc',
						array(
							'cycle_id' => array(
								'type'    => 'integer',
								'default' => 0,
								'minimum' => 0,
							),
							'status'   => array(
								'type'    => 'array',
								'items'   => array(
									'type' => 'string',
									'enum' => array_keys( OPENBB_Order::status_labels() ),
								),
								'default' => array(),
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $can_edit,
					'args'                => array_merge(
						$this->write_args(),
						array(
							'cycle_id' => array(
								'type'     => 'integer',
								'minimum'  => 1,
								'required' => true,
							),
							'user_id'  => array(
								'type'     => 'integer',
								'minimum'  => 1,
								'required' => true,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/orders/(?P<id>\d+)',
			array(
				'args' => self::id_arg(),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $can_view,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $can_edit,
					'args'                => $this->write_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $can_edit,
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/order-products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order_products' ),
				'permission_callback' => $can_edit,
				'args'                => array(
					'order_id' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/orders/(?P<id>\d+)/(?P<action>prepay|fulfill)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'confirm_order' ),
				'permission_callback' => static function ( WP_REST_Request $request ) {
					$allowed = self::authorize( 'openbb_view_order', (int) $request['id'] );
					if ( true !== $allowed ) {
						return $allowed;
					}
					$allowed = self::authorize( 'openbb_record_payments' );
					return true === $allowed && 'fulfill' === $request['action']
						? self::authorize( 'openbb_distribute_orders' )
						: $allowed;
				},
				'args'                => array_merge(
					self::id_arg(),
					array(
						'confirmed' => array( 'type' => 'boolean', 'required' => true ),
						'expected_updated_at' => array( 'type' => 'string', 'required' => true ),
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 0, 'required' => true ),
					)
				),
			)
		);
	}

	public function confirm_order( WP_REST_Request $request ) {
		if ( true !== $request['confirmed'] || '' === trim( (string) $request['expected_updated_at'] ) ) {
			return new WP_Error( 'openbb_confirmation_required', __( 'Confirm payment and reload the order before continuing.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}
		$expected = self::to_gmt_datetime( $request['expected_updated_at'], 'expected_updated_at' );
		if ( is_wp_error( $expected ) ) {
			return $expected;
		}
		$order = OPENBB_Order_Lifecycle::confirm( (int) $request['id'], $request['action'], get_current_user_id(), $expected, (int) $request['expected_revision'] );
		return is_wp_error( $order ) ? $order : rest_ensure_response( $this->prepare_order_detail( $order ) );
	}

	private function write_args(): array {
		return array(
			'status'              => array(
				'type' => 'string',
				'enum' => OPENBB_Order_Policy::EDITABLE_STATUSES,
			),
			'lines'               => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'            => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'product_id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'qty'           => array(
							'type'    => 'number',
							'minimum' => 0,
						),
						'own_container' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
			),
			'donation'            => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'staff_note'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'reason'              => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'expected_updated_at' => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();
		if ( (int) $request['cycle_id'] ) {
			$where[]  = 'o.cycle_id = %d';
			$params[] = (int) $request['cycle_id'];
		}
		$statuses = array_values( array_intersect( (array) $request['status'], array_keys( OPENBB_Order::status_labels() ) ) );
		if ( $statuses ) {
			$where[] = 'o.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params  = array_merge( $params, $statuses );
		}
		$search = trim( (string) $request['search'] );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(o.id = %d OR u.display_name LIKE %s OR u.user_email LIKE %s)';
			$params[] = ctype_digit( ltrim( $search, '#' ) ) ? (int) ltrim( $search, '#' ) : 0;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$order     = 'asc' === $request['order'] ? 'ASC' : 'DESC';
		$orderby   = array(
			'id'           => "o.id {$order}",
			'submitted_at' => "o.submitted_at IS NULL {$order}, o.submitted_at {$order}, o.id {$order}",
			'total'        => "o.total {$order}, o.id {$order}",
			'member'       => "u.display_name {$order}, o.id {$order}",
		)[ $request['orderby'] ];
		$per_page  = (int) $request['per_page'];
		$offset    = ( (int) $request['page'] - 1 ) * $per_page;
		$from      = "{$wpdb->openbb_orders} o LEFT JOIN {$wpdb->users} u ON u.ID = o.user_id";

		$count_sql = "SELECT COUNT(*) FROM {$from} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, ...$params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows      = $wpdb->get_results(
			$wpdb->prepare( "SELECT o.* FROM {$from} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d", ...array_merge( $params, array( $per_page, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		$orders = array_map(
			static function ( array $row ): OPENBB_Order {
				return new OPENBB_Order( $row );
			},
			(array) $rows
		);

		return self::paginated( $this->prepare_orders( $orders ), $total, $per_page );
	}

	public function get_item( WP_REST_Request $request ) {
		$order = OPENBB_Orders::get( (int) $request['id'] );
		if ( ! $order ) {
			return self::not_found( __( 'Order not found.', 'open-bulk-buying' ) );
		}
		$allowed = self::authorize( 'openbb_view_order', $order->id );
		if ( true !== $allowed ) {
			return $allowed;
		}

		return rest_ensure_response( $this->prepare_order_detail( $order ) );
	}

	public function create_item( WP_REST_Request $request ) {
		$order = OPENBB_Order_Editor::start( OPENBB_Cycles::get( (int) $request['cycle_id'] ), (int) $request['user_id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return $this->save( $request, $order );
	}

	public function update_item( WP_REST_Request $request ) {
		$order = OPENBB_Orders::get( (int) $request['id'] );
		if ( ! $order ) {
			return self::not_found( __( 'Order not found.', 'open-bulk-buying' ) );
		}
		$allowed = self::authorize( 'openbb_edit_order', $order->id );
		if ( true !== $allowed ) {
			return $allowed;
		}

		return $this->save( $request, $order );
	}

	public function delete_item( WP_REST_Request $request ) {
		$order = OPENBB_Orders::get( (int) $request['id'] );
		if ( ! $order ) {
			return self::not_found( __( 'Order not found.', 'open-bulk-buying' ) );
		}
		$allowed = self::authorize( 'openbb_edit_order', $order->id );
		if ( true !== $allowed ) {
			return $allowed;
		}

		$deleted = $order->delete();
		if ( is_wp_error( $deleted ) ) {
			$deleted->add_data( array( 'status' => 'openbb_order_delete_failed' === $deleted->get_error_code() ? 500 : 409 ) );
			return $deleted;
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => (int) $request['id'],
			)
		);
	}

	/**
	 * Active products for the line picker, with how many units this order may hold.
	 */
	public function get_order_products( WP_REST_Request $request ) {
		$order = null;
		if ( (int) $request['order_id'] ) {
			$order = OPENBB_Orders::get( (int) $request['order_id'] );
			if ( ! $order ) {
				return self::not_found( __( 'Order not found.', 'open-bulk-buying' ) );
			}
		}

		$query = new WP_Query(
			array(
				'post_type'      => OPENBB_PRODUCT_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
				's'              => $request['search'],
				'meta_query'     => array(
					array(
						'key'   => OPENBB_META_PREFIX . 'active',
						'value' => '1',
					),
				),
			)
		);
		$products = array_filter( array_map( array( 'OPENBB_Product', 'get' ), wp_list_pluck( $query->posts, 'ID' ) ) );
		$limits   = OPENBB_Order_Editor::limits_for( wp_list_pluck( $products, 'id' ), $order );
		$language = $order ? $order->language : null;

		$items = array();
		foreach ( $products as $product ) {
			$base_per_unit = $product->get_base_per_unit();
			$items[]       = array(
				'id'               => $product->id,
				'name'             => $product->get_name( $language ),
				'brand'            => $product->brand,
				'unit_label'       => $product->get_unit_label(),
				'price'            => $product->price,
				'container_charge' => $product->container_charge,
				'base_per_unit'    => $base_per_unit,
				'max_qty'          => OPENBB_Order_Editor::to_units( $limits[ $product->id ] ?? 0.0, $base_per_unit ),
			);
		}

		return rest_ensure_response( $items );
	}

	/**
	 * Hands the request's changes to OPENBB_Order_Editor and returns the saved order.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function save( WP_REST_Request $request, OPENBB_Order $order ) {
		$is_new  = ! $order->id;
		$changes = array( 'reason' => (string) $request['reason'] );
		foreach ( array( 'status', 'lines', 'donation', 'staff_note' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$changes[ $key ] = $request[ $key ];
			}
		}
		// The client echoes the ISO updated_at it was given; the model compares UTC.
		if ( '' !== (string) $request['expected_updated_at'] ) {
			$expected = self::to_gmt_datetime( $request['expected_updated_at'], 'expected_updated_at' );
			if ( is_wp_error( $expected ) ) {
				return $expected;
			}
			$changes['expected_updated_at'] = (string) $expected;
		}

		$saved = OPENBB_Order_Editor::apply( $order, $changes, get_current_user_id() );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$response = rest_ensure_response( $this->prepare_order_detail( $saved ) );
		if ( $is_new ) {
			$response->set_status( 201 );
		}

		return $response;
	}

	/**
	 * @param OPENBB_Order[] $orders
	 */
	private function prepare_orders( array $orders ): array {
		global $wpdb;

		$cycle_ids = array_unique( array_filter( wp_list_pluck( $orders, 'cycle_id' ) ) );
		$cycles    = array();
		foreach ( $cycle_ids as $cycle_id ) {
			$cycles[ $cycle_id ] = OPENBB_Cycles::get( (int) $cycle_id );
		}

		$line_counts = array();
		$order_ids   = array_filter( wp_list_pluck( $orders, 'id' ) );
		if ( $order_ids ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT order_id, COUNT(*) AS lines_count FROM {$wpdb->openbb_order_lines}
					WHERE order_id IN (" . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ') AND COALESCE(qty_final, qty_prepared, qty_requested) > 0
					GROUP BY order_id',
					...$order_ids
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$line_counts[ (int) $row['order_id'] ] = (int) $row['lines_count'];
			}
		}

		$labels     = OPENBB_Order::status_labels();
		$aid_labels = OPENBB_Order::aid_status_labels();
		$items      = array();
		foreach ( $orders as $order ) {
			$cycle             = $cycles[ $order->cycle_id ] ?? null;
			$can_edit          = current_user_can( 'openbb_edit_order', $order->id );
			$can_edit_contents = OPENBB_Order_Policy::can_edit_contents( $order, $cycle );
			$item   = array(
				'id'               => $order->id,
				'cycle_id'         => $order->cycle_id,
				'cycle_name'       => $cycle ? $cycle->name : '',
				'cycle_status'     => $cycle ? $cycle->status : '',
				'user_id'          => $order->user_id,
				'member'           => self::user_summary( $order->user_id ),
				'status'           => $order->status,
				'status_label'     => $labels[ $order->status ] ?? $order->status,
				'language'         => $order->language,
				'currency'         => $order->currency,
				'subtotal'         => $order->subtotal,
				'container_credit' => $order->container_credit,
				'donation'         => $order->donation,
				'total'            => $order->total,
				'total_formatted'  => preg_match( '/^[A-Z]{3}$/', $order->currency )
					? openbb_format_money( $order->total, $order->currency )
					: __( 'Unknown currency', 'open-bulk-buying' ),
				'lines_count'      => $line_counts[ $order->id ] ?? 0,
				'submitted_at'     => self::to_local_datetime( $order->submitted_at ),
				'collected_at'     => self::to_local_datetime( $order->collected_at ),
				'updated_at'       => self::to_local_datetime( $order->updated_at ),
				'revision'         => $order->revision,
				'reserves_stock'   => OPENBB_Inventory::order_reserves( $order, $cycle ),
				'is_locked'        => ! $can_edit_contents,
				'can_edit_lines'   => $can_edit_contents && $can_edit,
				'can_edit_amounts' => $can_edit_contents && $can_edit,
				'can_cancel'       => OPENBB_Order_Policy::can_cancel( $order, $cycle ) && $can_edit,
				'can_delete'       => OPENBB_Order_Policy::can_delete( $order ) && $can_edit,
				'is_abandoned'     => $order->is_abandoned( $cycle ),
				'hold_until'       => $cycle ? self::to_local_datetime( $cycle->hold_until( $order->status ) ) : null,
				'paid_at'          => self::to_local_datetime( $order->paid_at ),
				'paid_by'          => $order->paid_by,
				'paid_amount'      => $order->paid_amount,
				'paid_currency'    => $order->paid_currency,
				'collected_by'     => $order->collected_by,
				'can_prepay'       => OPENBB_Order_Policy::can_confirm( $order, $cycle, 'prepay' ) && current_user_can( 'openbb_record_payments' ),
				'can_fulfill'      => OPENBB_Order_Policy::can_confirm( $order, $cycle, 'fulfill' ) && current_user_can( 'openbb_record_payments' ) && current_user_can( 'openbb_distribute_orders' ),
				'editable_statuses' => OPENBB_Order_Policy::editable_statuses( $order, $cycle ),
				'reason_required_statuses' => array_values(
					array_filter(
						OPENBB_Order_Policy::EDITABLE_STATUSES,
						static function ( string $status ) use ( $order ): bool {
							return OPENBB_Order_Policy::needs_reason( $order, $status );
						}
					)
				),
				'can_edit'         => $can_edit,
			);
			if ( current_user_can( 'openbb_view_order_aid', $order->id ) ) {
				$item['aid_status']       = $order->aid_status;
				$item['aid_status_label'] = $aid_labels[ $order->aid_status ] ?? $order->aid_status;
				$item['aid_requested']    = $order->aid_requested;
				$item['aid_approved']     = $order->aid_approved;
			}
			$items[] = $item;
		}

		return $items;
	}

	private function prepare_order_detail( OPENBB_Order $order ): array {
		$item                = $this->prepare_orders( array( $order ) )[0];
		$item['member_note'] = $order->member_note;
		$item['staff_note']  = $order->staff_note;

		$lines  = $order->get_lines();
		$limits = OPENBB_Order_Editor::limits_for( array_unique( wp_list_pluck( $lines, 'product_id' ) ), $order );

		$item['lines'] = array_map(
			static function ( OPENBB_Order_Line $line ) use ( $limits ): array {
				return array(
					'id'               => $line->id,
					'product_id'       => $line->product_id,
					'product_name'     => $line->product_name,
					'brand'            => $line->brand,
					'unit_label'       => $line->get_unit_label(),
					'base_per_unit'    => $line->base_per_unit,
					'unit_price'       => $line->unit_price,
					'container_charge' => $line->container_charge,
					'own_container'    => $line->own_container,
					'qty_requested'    => $line->qty_requested,
					'qty_prepared'     => $line->qty_prepared,
					'qty_final'        => $line->qty_final,
					'line_total'       => $line->line_total,
					'is_prepared'      => null !== $line->qty_prepared || null !== $line->qty_final,
					'max_qty'          => OPENBB_Order_Editor::to_units( $limits[ $line->product_id ] ?? 0.0, $line->base_per_unit ),
				);
			},
			$lines
		);

		return $item;
	}
}
