<?php
/**
 * The member order form's route: read and save the logged-in member's own order
 * in a cycle that is taking orders. The form autosaves drafts through POST.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_REST_Order extends OPENBB_REST_Controller {

	public function register_routes(): void {
		$cycle = array(
			'type'     => 'integer',
			'required' => true,
			'minimum'  => 1,
		);
		$money = array(
			'type'    => 'integer',
			'minimum' => 0,
		);
		$text  = array(
			'type'      => 'string',
			'maxLength' => OPENBB_Member_Orders::MAX_TEXT,
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/order',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_order' ),
					'permission_callback' => array( $this, 'can_order' ),
					'args'                => array( 'cycle_id' => $cycle ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_order' ),
					'permission_callback' => array( $this, 'can_order' ),
					'args'                => array(
						'cycle_id'          => $cycle,
						'lines'             => array(
							'type'     => 'array',
							'default'  => array(),
							'maxItems' => 500,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'product_id'    => array(
										'type'     => 'integer',
										'required' => true,
									),
									'qty'           => array(
										'type'     => 'integer',
										'required' => true,
										'minimum'  => 0,
										'maximum'  => OPENBB_Member_Orders::MAX_QTY,
									),
									'own_container' => array( 'type' => 'boolean' ),
								),
							),
						),
						'donation'          => $money,
						'aid_request'       => array( 'type' => 'boolean' ),
						'aid_reason'        => $text,
						'member_note'       => $text,
						'expected_revision' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'submit'            => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'commit'            => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/** @return true|WP_Error */
	public function can_order() {
		return self::authorize( 'openbb_place_orders' );
	}

	/** @return WP_REST_Response|WP_Error */
	public function get_order( WP_REST_Request $request ) {
		$cycle = $this->open_cycle( (int) $request['cycle_id'] );
		if ( is_wp_error( $cycle ) ) {
			return $cycle;
		}

		return rest_ensure_response( OPENBB_Member_Orders::state( OPENBB_Orders::get_for_member( $cycle->id, get_current_user_id() ), $cycle ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function save_order( WP_REST_Request $request ) {
		$cycle = $this->open_cycle( (int) $request['cycle_id'] );
		if ( is_wp_error( $cycle ) ) {
			return $cycle;
		}

		$input = array();
		foreach ( array( 'lines', 'donation', 'aid_request', 'aid_reason', 'member_note', 'expected_revision', 'submit', 'commit' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$input[ $key ] = $request[ $key ];
			}
		}

		$order = OPENBB_Member_Orders::save( $cycle, get_current_user_id(), $input );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return rest_ensure_response( OPENBB_Member_Orders::state( $order, $cycle ) );
	}

	/** @return OPENBB_Cycle|WP_Error */
	private function open_cycle( int $cycle_id ) {
		$cycle = OPENBB_Cycles::get( $cycle_id );
		if ( ! $cycle ) {
			return self::not_found( __( 'Cycle not found.', 'open-bulk-buying' ) );
		}
		if ( ! $cycle->accepts_orders() ) {
			return new WP_Error( 'openbb_ordering_closed', __( 'Ordering is closed for this cycle.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		return $cycle;
	}
}
