<?php
/**
 * Cycle notices, for staff.
 *
 * Notices are stored on their cycle, so every route is under /cycles/{cycle_id}/notices.
 * Each needs openbb_manage_cycles: notices are part of running a cycle. Status changes
 * (publish, unpublish) are an ordinary edit of `status`.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_REST_Cycle_Notices extends OPENBB_REST_Controller {

	public function register_routes(): void {
		$can_manage = static function () {
			return self::authorize( 'openbb_manage_cycles' );
		};
		$route_args = array(
			'cycle_id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/cycles/(?P<cycle_id>\d+)/notices',
			array(
				'args' => $route_args,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $can_manage,
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
			'/cycles/(?P<cycle_id>\d+)/notices/(?P<id>[a-z0-9]{8,32})',
			array(
				'args' => array_merge(
					$route_args,
					array(
						'id' => array(
							'type'     => 'string',
							'required' => true,
						),
					)
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $can_manage,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $can_manage,
					'args'                => array_merge(
						$this->write_args(),
						array(
							'revision' => array(
								'type'     => 'integer',
								'minimum'  => 0,
								'required' => true,
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $can_manage,
				),
			)
		);
	}

	private function write_args(): array {
		$localized = array(
			'type'                 => 'object',
			'additionalProperties' => array( 'type' => 'string' ),
		);
		$date      = array( 'type' => array( 'string', 'null' ) );

		return array(
			'type'       => array(
				'type' => 'string',
				'enum' => OPENBB_Cycle_Notices::TYPES,
			),
			'status'     => array(
				'type' => 'string',
				'enum' => OPENBB_Cycle_Notices::STATUSES,
			),
			'content'    => $localized,
			'open_rule'  => array(
				'type' => 'string',
				'enum' => OPENBB_Cycle_Notices::OPEN_RULES,
			),
			'open_stage' => array(
				'type' => 'string',
				'enum' => OPENBB_Cycle::STATUSES,
			),
			'open_at'    => $date,
			'close_rule' => array(
				'type' => 'string',
				'enum' => OPENBB_Cycle_Notices::CLOSE_RULES,
			),
			'close_stage' => array(
				'type' => 'string',
				'enum' => OPENBB_Cycle::STATUSES,
			),
			'close_at'   => $date,
			'cta_text'   => $localized,
			'cta_url'    => array(
				'type'      => 'string',
				'maxLength' => OPENBB_Cycle_Notices::CTA_URL_MAX,
			),
		);
	}

	/** @return WP_REST_Response|WP_Error */
	public function get_items( WP_REST_Request $request ) {
		$cycle = OPENBB_Cycles::get( (int) $request['cycle_id'] );
		if ( ! $cycle ) {
			return self::not_found( __( 'Cycle not found.', 'open-bulk-buying' ) );
		}

		return rest_ensure_response(
			array_map(
				static function ( array $notice ) use ( $cycle ): array {
					return self::prepare_notice( $notice, $cycle );
				},
				$cycle->notices
			)
		);
	}

	/** @return WP_REST_Response|WP_Error */
	public function get_item( WP_REST_Request $request ) {
		$cycle = OPENBB_Cycles::get( (int) $request['cycle_id'] );
		if ( ! $cycle ) {
			return self::not_found( __( 'Cycle not found.', 'open-bulk-buying' ) );
		}
		$notice = OPENBB_Cycle_Notices::find( $cycle, (string) $request['id'] );

		return $notice ? rest_ensure_response( self::prepare_notice( $notice, $cycle ) ) : self::not_found( __( 'Notice not found.', 'open-bulk-buying' ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function create_item( WP_REST_Request $request ) {
		$input = $this->read_input( $request );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		return $this->save( $request, null, $input, 0, 201 );
	}

	/**
	 * Changes a notice; 409 if someone saved since `revision`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ) {
		$input = $this->read_input( $request );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		return $this->save( $request, (string) $request['id'], $input, (int) $request['revision'], 200 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function delete_item( WP_REST_Request $request ) {
		$deleted = OPENBB_Cycle_Notices::delete( (int) $request['cycle_id'], (string) $request['id'] );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => (string) $request['id'],
			)
		);
	}

	/** @return WP_REST_Response|WP_Error */
	private function save( WP_REST_Request $request, ?string $id, array $input, int $revision, int $status ) {
		$saved = OPENBB_Cycle_Notices::save( (int) $request['cycle_id'], $id, $input, $revision );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$response = rest_ensure_response( self::prepare_notice( $saved, OPENBB_Cycles::get( (int) $request['cycle_id'] ) ) );
		$response->set_status( $status );

		return $response;
	}

	/**
	 * The fields present in the request, with dates converted to UTC.
	 *
	 * @return array|WP_Error
	 */
	private function read_input( WP_REST_Request $request ) {
		$input = array();
		foreach ( array( 'type', 'status', 'content', 'open_rule', 'open_stage', 'close_rule', 'close_stage', 'cta_text', 'cta_url' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$input[ $field ] = $request[ $field ];
			}
		}

		$labels = array(
			'open_at'  => __( 'Notice opens', 'open-bulk-buying' ),
			'close_at' => __( 'Notice closes', 'open-bulk-buying' ),
		);
		foreach ( $labels as $field => $label ) {
			if ( $request->has_param( $field ) ) {
				$value = self::to_gmt_datetime( $request[ $field ], $label );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$input[ $field ] = $value;
			}
		}

		return $input;
	}

	private static function prepare_notice( array $notice, OPENBB_Cycle $cycle ): array {
		$phase = OPENBB_Cycle_Notices::phase( $notice, $cycle );

		return array(
			'id'          => $notice['id'],
			'cycle_id'    => $cycle->id,
			'type'        => $notice['type'],
			'content'     => $notice['content'],
			'status'      => $notice['status'],
			'phase'       => $phase,
			'phase_label' => OPENBB_Cycle_Notices::phase_labels()[ $phase ],
			'open_rule'   => $notice['open_rule'],
			'open_stage'  => $notice['open_stage'],
			'open_at'     => self::to_local_datetime( $notice['open_at'] ),
			'close_rule'  => $notice['close_rule'],
			'close_stage' => $notice['close_stage'],
			'close_at'    => self::to_local_datetime( $notice['close_at'] ),
			'cta_text'    => $notice['cta_text'],
			'cta_url'     => $notice['cta_url'],
			'revision'    => $notice['revision'],
			'created_at'  => self::to_local_datetime( $notice['created_at'] ),
			'modified'    => self::to_local_datetime( $notice['updated_at'] ),
		);
	}
}
