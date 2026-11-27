<?php
/**
 * Polls, for staff: definitions, lifecycle and results.
 *
 * Polls have their own ids and top-level routes, although they are managed from their
 * cycle, so a screen listing polls across cycles needs no new API.
 *
 * Every route needs openbb_manage_polls. Results come from two routes on purpose:
 * /results has counts only, /responses has who answered what.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_REST_Polls extends OPENBB_REST_Controller {

	public function register_routes(): void {
		$can_manage = static function () {
			return self::authorize( 'openbb_manage_polls' );
		};
		$cycle_id   = array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);
		$revision   = array(
			'type'     => 'integer',
			'minimum'  => 0,
			'required' => true,
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/polls',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $can_manage,
					'args'                => array( 'cycle_id' => $cycle_id ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $can_manage,
					'args'                => array_merge(
						$this->write_args(),
						array(
							'cycle_id'  => $cycle_id,
							// Copy this poll's definition instead of starting empty.
							'copy_from' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/polls/audience-cycles',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_audience_cycles' ),
				'permission_callback' => $can_manage,
				'args'                => array( 'cycle_id' => $cycle_id ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/polls/(?P<id>\d+)',
			array(
				'args' => self::id_arg(),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $can_manage,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $can_manage,
					'args'                => array_merge( $this->write_args(), array( 'revision' => $revision ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $can_manage,
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/polls/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_status' ),
				'permission_callback' => $can_manage,
				'args'                => array_merge(
					self::id_arg(),
					array(
						'status'   => array(
							'type'     => 'string',
							'enum'     => array( OPENBB_Poll::STATUS_PUBLISHED, OPENBB_Poll::STATUS_CLOSED ),
							'required' => true,
						),
						'revision' => $revision,
					)
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/polls/(?P<id>\d+)/results',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_results' ),
				'permission_callback' => $can_manage,
				'args'                => self::id_arg(),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/polls/(?P<id>\d+)/responses',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_responses' ),
				'permission_callback' => $can_manage,
				'args'                => array_merge(
					self::id_arg(),
					array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
					)
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
			'title'           => $localized,
			'intro'           => $localized,
			'open_rule'       => array(
				'type' => 'string',
				'enum' => OPENBB_Poll::OPEN_RULES,
			),
			'open_stage'      => array(
				'type' => 'string',
				'enum' => OPENBB_Cycle::STATUSES,
			),
			'open_at'         => $date,
			'close_rule'      => array(
				'type' => 'string',
				'enum' => OPENBB_Poll::CLOSE_RULES,
			),
			'close_stage'     => array(
				'type' => 'string',
				'enum' => OPENBB_Cycle::STATUSES,
			),
			'close_at'        => $date,
			'placements'      => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
					'enum' => OPENBB_Poll::PLACEMENTS,
				),
			),
			'audience'        => array(
				'type' => 'string',
				'enum' => OPENBB_Poll::AUDIENCES,
			),
			'audience_cycles' => array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'questions'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
		);
	}

	/** @return WP_REST_Response|WP_Error */
	public function get_items( WP_REST_Request $request ) {
		$cycle = OPENBB_Cycles::get( (int) $request['cycle_id'] );
		if ( ! $cycle ) {
			return self::not_found( __( 'Cycle not found.', 'open-bulk-buying' ) );
		}

		$polls  = OPENBB_Polls::for_cycle( $cycle->id );
		$counts = OPENBB_Poll_Responses::counts( wp_list_pluck( $polls, 'id' ) );

		return rest_ensure_response(
			array_map(
				static function ( OPENBB_Poll $poll ) use ( $cycle, $counts ): array {
					return self::prepare_poll( $poll, $cycle, $counts[ $poll->id ] ?? 0 );
				},
				$polls
			)
		);
	}

	/** @return WP_REST_Response|WP_Error */
	public function get_item( WP_REST_Request $request ) {
		$poll = $this->find( $request );

		return is_wp_error( $poll ) ? $poll : self::poll_response( $poll );
	}

	/** @return WP_REST_Response|WP_Error */
	public function create_item( WP_REST_Request $request ) {
		$cycle = OPENBB_Cycles::get( (int) $request['cycle_id'] );
		if ( ! $cycle ) {
			return self::not_found( __( 'Cycle not found.', 'open-bulk-buying' ) );
		}

		if ( $request->has_param( 'copy_from' ) ) {
			$source = OPENBB_Polls::get( (int) $request['copy_from'] );
			if ( ! $source ) {
				return self::not_found( __( 'The poll to copy no longer exists.', 'open-bulk-buying' ) );
			}
			$poll = OPENBB_Polls::copy( $source, $cycle, get_current_user_id() );
		} else {
			$input = $this->read_input( $request );
			$poll  = is_wp_error( $input )
				? $input
				: OPENBB_Polls::save( null, array_merge( $input, array( 'cycle_id' => $cycle->id ) ), 0, get_current_user_id() );
		}

		return is_wp_error( $poll ) ? $poll : self::poll_response( $poll, 201 );
	}

	/**
	 * Changes a poll; 409 if someone saved since `revision`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ) {
		$poll = $this->find( $request );
		if ( is_wp_error( $poll ) ) {
			return $poll;
		}
		$input = $this->read_input( $request );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$saved = OPENBB_Polls::save( $poll, $input, (int) $request['revision'], get_current_user_id() );

		return is_wp_error( $saved ) ? $saved : self::poll_response( $saved );
	}

	/** @return WP_REST_Response|WP_Error */
	public function update_status( WP_REST_Request $request ) {
		$poll = $this->find( $request );
		if ( is_wp_error( $poll ) ) {
			return $poll;
		}

		$saved = OPENBB_Polls::set_status( $poll, (string) $request['status'], (int) $request['revision'], get_current_user_id() );

		return is_wp_error( $saved ) ? $saved : self::poll_response( $saved );
	}

	/** @return WP_REST_Response|WP_Error */
	public function delete_item( WP_REST_Request $request ) {
		$poll = $this->find( $request );
		if ( is_wp_error( $poll ) ) {
			return $poll;
		}

		$deleted = OPENBB_Polls::delete( $poll );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $poll->id,
			)
		);
	}

	/**
	 * Cycles to choose a poll's audience from, with the one to suggest for a poll in `cycle_id`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_audience_cycles( WP_REST_Request $request ) {
		$cycle = OPENBB_Cycles::get( (int) $request['cycle_id'] );
		if ( ! $cycle ) {
			return self::not_found( __( 'Cycle not found.', 'open-bulk-buying' ) );
		}

		$labels = OPENBB_Cycle::status_labels();

		return rest_ensure_response(
			array(
				'suggested' => OPENBB_Polls::suggested_cycle( $cycle ),
				'cycles'    => array_map(
					static function ( array $row ) use ( $labels ): array {
						$row['status_label'] = $labels[ $row['status'] ] ?? $row['status'];
						$row['opens_at']     = self::to_local_datetime( $row['opens_at'] );

						return $row;
					},
					OPENBB_Polls::audience_cycles()
				),
			)
		);
	}

	/**
	 * Counts only: no member identities and no free text.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_results( WP_REST_Request $request ) {
		$poll = $this->find( $request );
		if ( is_wp_error( $poll ) ) {
			return $poll;
		}

		$tally              = OPENBB_Poll_Responses::tally( $poll );
		$tally['questions'] = (object) array_map(
			static function ( array $entry ): array {
				if ( isset( $entry['counts'] ) ) {
					$entry['counts'] = (object) $entry['counts'];
				}

				return $entry;
			},
			$tally['questions']
		);

		return rest_ensure_response( $tally );
	}

	/**
	 * Who answered what, most recently changed first.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_responses( WP_REST_Request $request ) {
		$poll = $this->find( $request );
		if ( is_wp_error( $poll ) ) {
			return $poll;
		}

		$per_page = (int) $request['per_page'];
		$result   = OPENBB_Poll_Responses::responses( $poll, (int) $request['page'], $per_page );
		$statuses = OPENBB_Access::status_labels();

		return self::paginated(
			array_map(
				static function ( array $item ) use ( $statuses ): array {
					$status = OPENBB_Access::get_status( $item['user_id'] );

					return array(
						'member'     => array_merge(
							self::user_summary( $item['user_id'] ),
							array(
								'status'       => $status,
								'status_label' => $statuses[ $status ] ?? '',
							)
						),
						'created_at' => self::to_local_datetime( $item['created_at'] ),
						'updated_at' => self::to_local_datetime( $item['updated_at'] ),
						'answers'    => (object) $item['answers'],
					);
				},
				$result['items']
			),
			$result['total'],
			$per_page
		);
	}

	/** @return OPENBB_Poll|WP_Error */
	private function find( WP_REST_Request $request ) {
		$poll = OPENBB_Polls::get( (int) $request['id'] );

		return $poll ? $poll : self::not_found( __( 'Poll not found.', 'open-bulk-buying' ) );
	}

	/**
	 * The fields present in the request, with dates converted to UTC.
	 *
	 * @return array|WP_Error
	 */
	private function read_input( WP_REST_Request $request ) {
		$input = array();
		foreach ( array( 'title', 'intro', 'open_rule', 'open_stage', 'close_rule', 'close_stage', 'placements', 'audience', 'audience_cycles', 'questions' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$input[ $field ] = $request[ $field ];
			}
		}

		$labels = array(
			'open_at'  => __( 'Poll opens', 'open-bulk-buying' ),
			'close_at' => __( 'Poll closes', 'open-bulk-buying' ),
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

	/** @return WP_REST_Response */
	private static function poll_response( OPENBB_Poll $poll, int $status = 200 ) {
		$count    = OPENBB_Poll_Responses::counts( array( $poll->id ) )[ $poll->id ] ?? 0;
		$response = rest_ensure_response( self::prepare_poll( $poll, OPENBB_Cycles::get( $poll->cycle_id ), $count ) );
		$response->set_status( $status );

		return $response;
	}

	private static function prepare_poll( OPENBB_Poll $poll, ?OPENBB_Cycle $cycle, int $responses ): array {
		global $wpdb;

		$phase   = $cycle ? $poll->phase( $cycle ) : OPENBB_Poll::PHASE_CLOSED;
		$missing = array();
		if ( $poll->audience_cycles ) {
			$existing = array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->openbb_cycles} WHERE id IN (" . implode( ',', array_fill( 0, count( $poll->audience_cycles ), '%d' ) ) . ')',
						...$poll->audience_cycles
					)
				)
			);
			$missing  = array_values( array_diff( $poll->audience_cycles, $existing ) );
		}

		return array(
			'id'                      => $poll->id,
			'cycle_id'                => $poll->cycle_id,
			'title'                   => $poll->title,
			'intro'                   => $poll->intro,
			'status'                  => $poll->status,
			'phase'                   => $phase,
			'phase_label'             => OPENBB_Poll::phase_labels()[ $phase ],
			'open_rule'               => $poll->open_rule,
			'open_stage'              => $poll->open_stage,
			'open_at'                 => self::to_local_datetime( $poll->open_at ),
			'close_rule'              => $poll->close_rule,
			'close_stage'             => $poll->close_stage,
			'close_at'                => self::to_local_datetime( $poll->close_at ),
			'placements'              => $poll->placements,
			'audience'                => $poll->audience,
			'audience_cycles'         => $poll->audience_cycles,
			// Selected cycles that were deleted since; they no longer match anyone.
			'missing_audience_cycles' => $missing,
			'questions'               => $poll->questions,
			'revision'                => $poll->revision,
			'responses'               => $responses,
			'link'                    => OPENBB_Polls_View::page_url( $poll->id ),
			'created_at'              => self::to_local_datetime( $poll->created_at ),
			'modified'                => self::to_local_datetime( $poll->updated_at ),
			'published_at'            => self::to_local_datetime( $poll->published_at ),
			'closed_at'               => self::to_local_datetime( $poll->closed_at ),
		);
	}
}
