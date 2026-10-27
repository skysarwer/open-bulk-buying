<?php
/**
 * Ordering cycles.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_REST_Cycles extends OPENBB_REST_Controller {

	public function register_routes(): void {
		$this->register_aid_routes();
		$can_read   = static function () {
			return self::authorize_any( array( 'openbb_manage_cycles', 'openbb_view_orders', 'openbb_view_aid' ) );
		};
		$can_manage = static function () {
			return self::authorize( 'openbb_manage_cycles' );
		};
		$can_delete = static function () {
			return self::authorize( 'openbb_delete_cycles_orders' );
		};

		register_rest_route(
			self::API_NAMESPACE,
			'/cycles',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $can_read,
					'args'                => self::collection_args(
						array( 'opens_at', 'name', 'status', 'id' ),
						'desc',
						array(
							'status' => array(
								'type'    => 'array',
								'items'   => array(
									'type' => 'string',
									'enum' => array_keys( OPENBB_Cycle::status_labels() ),
								),
								'default' => array(),
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $can_manage,
					'args'                => $this->write_args(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/cycles/(?P<id>\d+)',
			array(
				'args' => self::id_arg(),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $can_read,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $can_manage,
					'args'                => $this->write_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $can_delete,
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/cycles/(?P<id>\d+)/preparation-cleanup',
			array(
				'args' => self::id_arg(),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_preparation_cleanup' ),
					'permission_callback' => $can_manage,
					'args'                => array(
						'locked' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
	}

	private function register_aid_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/cycles/(?P<id>\d+)/aid',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_aid' ),
				'permission_callback' => static function () {
					return self::authorize( 'openbb_view_aid' );
				},
				'args'                => array_merge(
					self::id_arg(),
					self::collection_args(
						array( 'id' ),
						'desc',
						array(
							'aid_status' => array(
								'type'    => 'array',
								'items'   => array(
									'type' => 'string',
									'enum' => array( OPENBB_Order::AID_PENDING, OPENBB_Order::AID_APPROVED, OPENBB_Order::AID_DECLINED ),
								),
								'default' => array(),
							),
						)
					)
				),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/cycles/(?P<id>\d+)/aid/(?P<order_id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'decide_aid' ),
				'permission_callback' => static function ( WP_REST_Request $request ) {
					return self::authorize( 'openbb_decide_order_aid', (int) $request['order_id'] );
				},
				'args'                => array_merge(
					self::id_arg(),
					array(
						'order_id'          => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
						'decision'          => array( 'type' => 'string', 'enum' => array( 'approved', 'declined' ), 'required' => true ),
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 0, 'required' => true ),
					)
				),
			)
		);
	}

	public function get_aid( WP_REST_Request $request ) {
		global $wpdb;

		$cycle = OPENBB_Cycles::get( (int) $request['id'] );
		if ( ! $cycle ) {
			return self::not_found( __( 'Cycle not found.', 'open-bulk-buying' ) );
		}
		$where  = array( 'o.cycle_id = %d', "o.aid_status IN ('pending', 'approved', 'declined')", "o.status != 'draft'" );
		$params = array( $cycle->id );
		if ( $request['aid_status'] ) {
			$statuses = (array) $request['aid_status'];
			$where[]  = 'o.aid_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params   = array_merge( $params, $statuses );
		}
		if ( '' !== $request['search'] ) {
			$where[] = '(u.display_name LIKE %s OR o.id = %d)';
			$params[] = '%' . $wpdb->esc_like( $request['search'] ) . '%';
			$params[] = (int) $request['search'];
		}
		$sql = "FROM {$wpdb->openbb_orders} o LEFT JOIN {$wpdb->users} u ON u.ID = o.user_id WHERE " . implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$sql}", ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $wpdb->last_error ) {
			return new WP_Error( 'openbb_aid_load_failed', __( 'Aid requests could not be loaded.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}
		$direction = 'asc' === $request['order'] ? 'ASC' : 'DESC';
		$per_page = (int) $request['per_page'];
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.*, u.display_name AS member_name {$sql} ORDER BY o.id {$direction} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				...array_merge( $params, array( $per_page, ( (int) $request['page'] - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		if ( $wpdb->last_error ) {
			return new WP_Error( 'openbb_aid_load_failed', __( 'Aid requests could not be loaded.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}
		return self::paginated(
			array_map(
				function ( array $row ) use ( $cycle ): array {
					return $this->prepare_aid( new OPENBB_Order( $row ), $cycle, (string) $row['member_name'] );
				},
				$rows
			),
			$total,
			$per_page
		);
	}

	public function decide_aid( WP_REST_Request $request ) {
		$order = OPENBB_Order_Aid::decide(
			(int) $request['id'],
			(int) $request['order_id'],
			(string) $request['decision'],
			(int) $request['expected_revision'],
			get_current_user_id()
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$user = get_userdata( $order->user_id );
		return rest_ensure_response( $this->prepare_aid( $order, OPENBB_Cycles::get( $order->cycle_id ), $user ? $user->display_name : '' ) );
	}

	private function prepare_aid( OPENBB_Order $order, ?OPENBB_Cycle $cycle, string $member_name ): array {
		return array(
			'id'               => $order->id,
			'member_name'      => $member_name,
			'status_label'     => OPENBB_Order::status_labels()[ $order->status ] ?? $order->status,
			'aid_status'       => $order->aid_status,
			'aid_status_label' => OPENBB_Order::aid_status_labels()[ $order->aid_status ] ?? $order->aid_status,
			'aid_reason'       => $order->aid_reason,
			'aid_decided_at'   => self::to_local_datetime( $order->aid_decided_at ),
			'revision'         => $order->revision,
			'can_decide'       => current_user_can( 'openbb_decide_order_aid', $order->id ) && OPENBB_Order_Aid::can_decide( $order, $cycle ),
		);
	}

	private function write_args(): array {
		return array_merge(
			self::localized_args( 'pickup_instructions' ),
			array(
				'name'             => array( 'type' => 'string' ),
				'status'           => array(
					'type' => 'string',
					'enum' => array_keys( OPENBB_Cycle::status_labels() ),
				),
				'opens_at'         => array( 'type' => array( 'string', 'null' ) ),
				'closes_at'        => array( 'type' => array( 'string', 'null' ) ),
				'pickup_opens_at'  => array( 'type' => array( 'string', 'null' ) ),
				'pickup_closes_at' => array( 'type' => array( 'string', 'null' ) ),
				'pickup_location'  => array( 'type' => 'string' ),
			)
		);
	}

	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $request['search'] ) {
			$where[]  = 'name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $request['search'] ) . '%';
		}
		$statuses = array_values( array_intersect( (array) $request['status'], array_keys( OPENBB_Cycle::status_labels() ) ) );
		if ( $statuses ) {
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params  = array_merge( $params, $statuses );
		}

		$where_sql = implode( ' AND ', $where );
		$order     = 'asc' === $request['order'] ? 'ASC' : 'DESC';
		$orderby   = array(
			'opens_at' => "opens_at IS NULL {$order}, opens_at {$order}, id {$order}",
			'name'     => "name {$order}, id {$order}",
			'status'   => "FIELD(status, 'setup', 'ordering', 'processing', 'distribution', 'complete') {$order}, id {$order}",
			'id'       => "id {$order}",
		)[ $request['orderby'] ];
		$per_page  = (int) $request['per_page'];
		$offset    = ( (int) $request['page'] - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$wpdb->openbb_cycles} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, ...$params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows      = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->openbb_cycles} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d", ...array_merge( $params, array( $per_page, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		$cycles = array_map(
			static function ( array $row ): OPENBB_Cycle {
				return new OPENBB_Cycle( $row );
			},
			(array) $rows
		);

		return self::paginated( $this->prepare_cycles( $cycles ), $total, $per_page );
	}

	public function get_item( WP_REST_Request $request ) {
		$cycle = OPENBB_Cycles::get( (int) $request['id'] );
		if ( ! $cycle ) {
			return self::not_found( __( 'Cycle not found.', 'open-bulk-buying' ) );
		}

		return rest_ensure_response( $this->prepare_cycles( array( $cycle ) )[0] );
	}

	public function create_item( WP_REST_Request $request ) {
		return $this->save( $request, null );
	}

	public function update_item( WP_REST_Request $request ) {
		$cycle = OPENBB_Cycles::get( (int) $request['id'] );
		if ( ! $cycle ) {
			return self::not_found( __( 'Cycle not found.', 'open-bulk-buying' ) );
		}

		return $this->save( $request, $cycle );
	}

	public function update_preparation_cleanup( WP_REST_Request $request ) {
		$cycle = OPENBB_Cycles::set_cleanup_lock( (int) $request['id'], (bool) $request['locked'] );
		if ( is_wp_error( $cycle ) ) {
			return $cycle;
		}

		return rest_ensure_response( $this->prepare_cycles( array( $cycle ) )[0] );
	}

	public function delete_item( WP_REST_Request $request ) {
		$cycle = OPENBB_Cycles::get( (int) $request['id'] );
		if ( ! $cycle ) {
			return self::not_found( __( 'Cycle not found.', 'open-bulk-buying' ) );
		}

		$deleted = $cycle->delete( true );
		if ( is_wp_error( $deleted ) ) {
			if ( ! $deleted->get_error_data() ) {
				$deleted->add_data( array( 'status' => 'openbb_cycle_has_orders' === $deleted->get_error_code() ? 409 : 500 ) );
			}
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
	 * @return WP_REST_Response|WP_Error
	 */
	private function save( WP_REST_Request $request, ?OPENBB_Cycle $existing ) {
		$cycle      = $existing ? clone $existing : new OPENBB_Cycle();
		$old_status = $existing ? $existing->status : '';

		if ( $request->has_param( 'name' ) ) {
			$cycle->name = sanitize_text_field( (string) $request['name'] );
		}
		if ( $request->has_param( 'status' ) ) {
			$cycle->status = (string) $request['status'];
		}
		if ( $request->has_param( 'pickup_location' ) ) {
			$cycle->pickup_location = sanitize_textarea_field( (string) $request['pickup_location'] );
		}
		$cycle->pickup_instructions = openbb_sanitize_localized_html( self::localized_in( $request, 'pickup_instructions', $cycle->pickup_instructions ) );

		$labels = array(
			'opens_at'         => __( 'Ordering opens', 'open-bulk-buying' ),
			'closes_at'        => __( 'Ordering closes', 'open-bulk-buying' ),
			'pickup_opens_at'  => __( 'Pickup starts', 'open-bulk-buying' ),
			'pickup_closes_at' => __( 'Pickup ends', 'open-bulk-buying' ),
		);
		foreach ( $labels as $field => $label ) {
			if ( $request->has_param( $field ) ) {
				$value = self::to_gmt_datetime( $request[ $field ], $label );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$cycle->$field = $value;
			}
		}

		$stored = OPENBB_Cycles::store( $cycle, $old_status, get_current_user_id() );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$response = rest_ensure_response( $this->prepare_cycles( array( OPENBB_Cycles::get( $cycle->id ) ?? $cycle ) )[0] );
		if ( ! $existing ) {
			$response->set_status( 201 );
		}

		return $response;
	}

	/**
	 * @param OPENBB_Cycle[] $cycles
	 */
	private function prepare_cycles( array $cycles ): array {
		global $wpdb;

		$stats = array();
		$ids   = array_filter( wp_list_pluck( $cycles, 'id' ) );
		if ( $ids ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT cycle_id,
						SUM(status != %s) AS orders_count,
						SUM(status IN (%s, %s)) AS submitted_count,
						SUM(status = 'prepaid') AS prepaid_count,
						SUM(status = 'fulfilled') AS fulfilled_count,
						SUM(CASE WHEN status IN (%s, %s, %s) THEN total ELSE 0 END) AS total
					FROM {$wpdb->openbb_orders}
					WHERE cycle_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')
					GROUP BY cycle_id',
					...array_merge(
						array( OPENBB_Order::STATUS_CANCELLED, OPENBB_Order::STATUS_SUBMITTED, OPENBB_Order::STATUS_PREPAID, OPENBB_Order::STATUS_SUBMITTED, OPENBB_Order::STATUS_PREPAID, OPENBB_Order::STATUS_FULFILLED ),
						$ids
					)
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$stats[ (int) $row['cycle_id'] ] = $row;
			}
		}

		$labels = OPENBB_Cycle::status_labels();
		$items  = array();
		foreach ( $cycles as $cycle ) {
			$row     = $stats[ $cycle->id ] ?? array();
			$items[] = array_merge(
				array(
					'id'               => $cycle->id,
					'name'             => $cycle->name,
					'status'           => $cycle->status,
					'status_label'     => $labels[ $cycle->status ] ?? $cycle->status,
					'accepts_orders'   => $cycle->accepts_orders(),
					'opens_at'         => self::to_local_datetime( $cycle->opens_at ),
					'closes_at'        => self::to_local_datetime( $cycle->closes_at ),
					'pickup_opens_at'  => self::to_local_datetime( $cycle->pickup_opens_at ),
					'pickup_closes_at' => self::to_local_datetime( $cycle->pickup_closes_at ),
					'pickup_location'  => $cycle->pickup_location,
					'finalized_at'     => self::to_local_datetime( $cycle->finalized_at ),
					'finalized_by'     => $cycle->finalized_by,
					'cleanup_locked_at' => self::to_local_datetime( $cycle->cleanup_locked_at ),
					'cleanup_locked' => null !== $cycle->cleanup_locked_at,
					'preparation_cleanup' => $cycle->allows_preparation_cleanup(),
					'unpaid_hold_until' => self::to_local_datetime( $cycle->unpaid_hold_until ),
					'prepaid_hold_until' => self::to_local_datetime( $cycle->prepaid_hold_until ),
				),
				self::localized_out( 'pickup_instructions', $cycle->pickup_instructions ),
				array(
					'orders_count'    => (int) ( $row['orders_count'] ?? 0 ),
					'submitted_count' => (int) ( $row['submitted_count'] ?? 0 ),
					'prepaid_count'   => (int) ( $row['prepaid_count'] ?? 0 ),
					'fulfilled_count' => (int) ( $row['fulfilled_count'] ?? 0 ),
					'orders_total'    => (int) ( $row['total'] ?? 0 ),
					'modified'        => self::to_local_datetime( $cycle->updated_at ),
				)
			);
		}

		return $items;
	}
}
