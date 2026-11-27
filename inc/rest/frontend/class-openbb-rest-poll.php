<?php
/**
 * Polls, for members: the polls the logged-in member can answer, and their own answers.
 *
 * Separate from the order route on purpose. Answering needs no order and no open
 * ordering window, and saving answers never touches an order or its revision.
 *
 * Every route needs openbb_answer_polls (active members), and OPENBB_Polls::check_access()
 * decides per poll: it must be open and the member in its audience. A member only ever
 * reads their own answers here.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_REST_Poll extends OPENBB_REST_Controller {

	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/poll',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_polls' ),
				'permission_callback' => array( $this, 'can_answer' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/poll/(?P<id>\d+)',
			array(
				'args' => self::id_arg(),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_poll' ),
					'permission_callback' => array( $this, 'can_answer' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_answers' ),
					'permission_callback' => array( $this, 'can_answer' ),
					'args'                => array(
						// Question key => answer: text, "1"/"0" for yes/no, an option key or a list of them.
						'answers' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/** @return true|WP_Error */
	public function can_answer() {
		return self::authorize( 'openbb_answer_polls' );
	}

	/**
	 * The polls the member can answer now.
	 */
	public function get_polls(): WP_REST_Response {
		$user_id   = get_current_user_id();
		$open      = OPENBB_Polls::open_for( $user_id );
		$responded = OPENBB_Poll_Responses::responded( $user_id, wp_list_pluck( wp_list_pluck( $open, 'poll' ), 'id' ) );

		return rest_ensure_response(
			array_map(
				static function ( array $entry ) use ( $responded ): array {
					return self::summary( $entry['poll'], $entry['cycle'], in_array( $entry['poll']->id, $responded, true ) );
				},
				$open
			)
		);
	}

	/**
	 * A poll's questions with the member's own answers.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_poll( WP_REST_Request $request ) {
		$poll = $this->open_poll( (int) $request['id'] );
		if ( is_wp_error( $poll ) ) {
			return $poll;
		}

		$answers = OPENBB_Poll_Responses::get_answers( $poll, get_current_user_id() );

		return rest_ensure_response( self::state( $poll, OPENBB_Cycles::get( $poll->cycle_id ), $answers ) );
	}

	/**
	 * Saves the member's answers, replacing their earlier ones.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_answers( WP_REST_Request $request ) {
		$poll = $this->open_poll( (int) $request['id'] );
		if ( is_wp_error( $poll ) ) {
			return $poll;
		}

		$saved = OPENBB_Poll_Responses::save( $poll, get_current_user_id(), (array) $request['answers'] );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response( self::state( $poll, OPENBB_Cycles::get( $poll->cycle_id ), $saved['answers'] ) );
	}

	/** @return OPENBB_Poll|WP_Error */
	private function open_poll( int $poll_id ) {
		$poll = OPENBB_Polls::get( $poll_id );
		if ( ! $poll ) {
			return self::not_found( __( 'Poll not found.', 'open-bulk-buying' ) );
		}
		$allowed = OPENBB_Polls::check_access( $poll, get_current_user_id() );

		return is_wp_error( $allowed ) ? $allowed : $poll;
	}

	private static function summary( OPENBB_Poll $poll, ?OPENBB_Cycle $cycle, bool $responded ): array {
		return array(
			'id'         => $poll->id,
			'title'      => $poll->get_title(),
			'intro'      => $poll->get_intro(),
			'cycle_id'   => $poll->cycle_id,
			'cycle_name' => $cycle ? $cycle->name : '',
			'responded'  => $responded,
		);
	}

	/**
	 * The poll as a form: each question as a field for openbb_render_field(), holding
	 * the member's answer, plus the answers on their own keyed by question.
	 */
	private static function state( OPENBB_Poll $poll, ?OPENBB_Cycle $cycle, array $answers ): array {
		$fields = array();
		$values = array();
		foreach ( $poll->active_questions() as $key => $question ) {
			$field          = openbb_poll_field_input( $question, $answers[ $key ] ?? null );
			$fields[]       = $field;
			$values[ $key ] = $field['value'];
		}

		return array_merge(
			self::summary( $poll, $cycle, (bool) $answers ),
			array(
				'fields'  => $fields,
				'answers' => (object) $values,
			)
		);
	}
}
