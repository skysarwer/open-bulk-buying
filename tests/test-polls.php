<?php
/**
 * Cycle polls: lifecycle, eligibility, answers, results, privacy and cleanup.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Polls extends OPENBB_TestCase {

	/** @var int A coordinator, who can manage cycles and so polls. */
	private $staff;

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		$this->staff = $this->make_user( 'coordinator' );
	}

	private function api( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/openbb/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}

		return rest_do_request( $request );
	}

	private function question( string $key, string $type = 'boolean', array $extra = array() ): array {
		return array_merge(
			array(
				'key'   => $key,
				'label' => array( 'default' => ucfirst( $key ) ),
				'type'  => $type,
			),
			$extra
		);
	}

	private function choice( string $key, array $option_keys, array $options = array() ): array {
		return $this->question(
			$key,
			'options',
			array(
				'options' => array_merge(
					array(
						'style'  => 'chips',
						'groups' => array(
							array(
								'key'     => '',
								'options' => array_map(
									static function ( string $option ): array {
										return array(
											'key'   => $option,
											'label' => array( 'default' => ucfirst( $option ) ),
										);
									},
									$option_keys
								),
							),
						),
					),
					$options
				),
			)
		);
	}

	private function poll( int $cycle_id, array $input = array(), bool $publish = true ): OPENBB_Poll {
		$poll = OPENBB_Polls::save(
			null,
			array_merge(
				array(
					'cycle_id'  => $cycle_id,
					'title'     => array( 'default' => 'Supplier vote' ),
					'questions' => array( $this->question( 'happy' ) ),
				),
				$input
			),
			0,
			$this->staff
		);
		$this->assertNotWPError( $poll );

		if ( $publish ) {
			$poll = OPENBB_Polls::set_status( $poll, OPENBB_Poll::STATUS_PUBLISHED, $poll->revision, $this->staff );
			$this->assertNotWPError( $poll );
		}

		return $poll;
	}

	private function move_cycle( int $cycle_id, string $status ): OPENBB_Cycle {
		global $wpdb;

		$wpdb->update( $wpdb->openbb_cycles, array( 'status' => $status ), array( 'id' => $cycle_id ) );

		return OPENBB_Cycles::get( $cycle_id );
	}

	private function order_with_status( int $cycle_id, int $user_id, string $status ): int {
		global $wpdb;

		$order_id = $this->make_order( $cycle_id, $user_id );
		$wpdb->update( $wpdb->openbb_orders, array( 'status' => $status ), array( 'id' => $order_id ) );

		return $order_id;
	}

	public function test_questions_use_the_shared_engine_but_are_never_required(): void {
		$poll = $this->poll(
			$this->make_cycle( 'ordering' ),
			array( 'questions' => array( $this->question( 'happy', 'boolean', array( 'required' => true ) ) ) ),
			false
		);

		$this->assertFalse( $poll->questions[0]['required'] );
		$this->assertWPError( OPENBB_Polls::save( $poll, array( 'questions' => array( $this->question( 'Not a key' ) ) ), $poll->revision, $this->staff ) );
	}

	public function test_default_rules_follow_the_cycle_stage_and_a_manual_close_is_final(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$poll     = $this->poll( $cycle_id );
		$member   = $this->make_user( 'member' );

		$this->assertSame( OPENBB_Poll::PHASE_SCHEDULED, $poll->phase( OPENBB_Cycles::get( $cycle_id ) ) );
		$this->assertSame( 'openbb_poll_not_open', OPENBB_Polls::check_access( $poll, $member )->get_error_code() );

		$this->assertTrue( $poll->is_open( $this->move_cycle( $cycle_id, 'ordering' ) ) );
		$this->assertTrue( OPENBB_Polls::check_access( $poll, $member ) );

		$this->assertSame( OPENBB_Poll::PHASE_ENDED, $poll->phase( $this->move_cycle( $cycle_id, 'processing' ) ) );
		$this->assertSame( 'openbb_poll_closed', OPENBB_Polls::check_access( $poll, $member )->get_error_code() );

		// Moving the cycle back re-evaluates the rule from its current stage.
		$this->assertTrue( $poll->is_open( $this->move_cycle( $cycle_id, 'ordering' ) ) );

		$closed = OPENBB_Polls::set_status( $poll, OPENBB_Poll::STATUS_CLOSED, $poll->revision, $this->staff );
		$this->assertNotWPError( $closed );
		$this->assertFalse( $closed->is_open( OPENBB_Cycles::get( $cycle_id ) ) );
		$this->assertWPError( OPENBB_Polls::set_status( $closed, OPENBB_Poll::STATUS_PUBLISHED, $closed->revision, $this->staff ) );
		$this->assertSame( 'openbb_poll_closed', OPENBB_Polls::save( $closed, array( 'title' => array( 'default' => 'Reopened' ) ), $closed->revision, $this->staff )->get_error_code() );
	}

	public function test_date_rules_open_and_close_at_the_exact_second(): void {
		$cycle = OPENBB_Cycles::get( $this->make_cycle( 'complete' ) );
		$poll  = $this->poll(
			$cycle->id,
			array(
				'open_rule'  => OPENBB_Poll::RULE_DATE,
				'open_at'    => '2030-01-01 10:00:00',
				'close_rule' => OPENBB_Poll::RULE_DATE,
				'close_at'   => '2030-01-02 10:00:00',
			)
		);
		$opens  = strtotime( '2030-01-01 10:00:00 UTC' );
		$closes = strtotime( '2030-01-02 10:00:00 UTC' );

		$this->assertFalse( $poll->is_open( $cycle, $opens - 1 ) );
		$this->assertTrue( $poll->is_open( $cycle, $opens ) );
		$this->assertTrue( $poll->is_open( $cycle, $closes - 1 ) );
		$this->assertFalse( $poll->is_open( $cycle, $closes ) );
	}

	public function test_rules_that_could_never_open_are_rejected(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$base     = array(
			'cycle_id'  => $cycle_id,
			'title'     => array( 'default' => 'Poll' ),
			'questions' => array( $this->question( 'happy' ) ),
		);

		$same_stage = OPENBB_Polls::save( null, array_merge( $base, array( 'open_stage' => 'processing' ) ), 0, $this->staff );
		$first      = OPENBB_Polls::save( null, array_merge( $base, array( 'open_rule' => OPENBB_Poll::RULE_PUBLISH, 'close_stage' => 'setup' ) ), 0, $this->staff );
		$backwards  = OPENBB_Polls::save(
			null,
			array_merge(
				$base,
				array(
					'open_rule'  => OPENBB_Poll::RULE_DATE,
					'open_at'    => '2030-01-02 10:00:00',
					'close_rule' => OPENBB_Poll::RULE_DATE,
					'close_at'   => '2030-01-01 10:00:00',
				)
			),
			0,
			$this->staff
		);
		$no_date    = OPENBB_Polls::save( null, array_merge( $base, array( 'close_rule' => OPENBB_Poll::RULE_DATE ) ), 0, $this->staff );

		foreach ( array( $same_stage, $first, $backwards, $no_date ) as $result ) {
			$this->assertWPError( $result );
			$this->assertSame( 'openbb_invalid_poll', $result->get_error_code() );
		}
	}

	public function test_a_supplier_vote_is_answered_in_setup_without_an_order(): void {
		$cycle_id = $this->make_cycle( 'setup' );
		$poll     = $this->poll(
			$cycle_id,
			array(
				'open_rule'   => OPENBB_Poll::RULE_PUBLISH,
				'close_stage' => 'ordering',
				'questions'   => array( $this->choice( 'supplier', array( 'acme', 'bulk_co' ) ) ),
			)
		);
		$member   = $this->make_user( 'member' );

		$this->assertFalse( OPENBB_Cycles::get( $cycle_id )->accepts_orders() );
		$saved = OPENBB_Poll_Responses::save( $poll, $member, array( 'supplier' => 'acme' ) );

		$this->assertNotWPError( $saved );
		$this->assertSame( array( 'supplier' => array( 'acme' ) ), $saved['answers'] );
		$this->assertNull( OPENBB_Orders::get_for_member( $cycle_id, $member ) );
	}

	public function test_only_active_members_can_answer(): void {
		$poll = $this->poll( $this->make_cycle( 'ordering' ) );

		foreach ( array( 'applicant', 'inactive', 'declined', '' ) as $membership ) {
			$result = OPENBB_Poll_Responses::save( $poll, $this->make_user( $membership ), array( 'happy' => true ) );
			$this->assertWPError( $result );
			$this->assertSame( 'openbb_not_member', $result->get_error_code() );
		}

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->api( 'POST', '/poll/' . $poll->id, array( 'answers' => array( 'happy' => '1' ) ) )->get_status() );
		wp_set_current_user( $this->make_user( 'applicant' ) );
		$this->assertSame( 403, $this->api( 'POST', '/poll/' . $poll->id, array( 'answers' => array( 'happy' => '1' ) ) )->get_status() );
	}

	public function test_a_draft_reads_as_missing_to_members(): void {
		$poll   = $this->poll( $this->make_cycle( 'ordering' ), array(), false );
		$result = OPENBB_Poll_Responses::save( $poll, $this->make_user( 'member' ), array( 'happy' => true ) );

		$this->assertSame( 'openbb_poll_not_found', $result->get_error_code() );
	}

	public function test_a_member_has_one_response_and_can_change_it_until_the_poll_closes(): void {
		global $wpdb;

		$cycle_id = $this->make_cycle( 'ordering' );
		$poll     = $this->poll(
			$cycle_id,
			array( 'questions' => array( $this->question( 'happy' ), $this->question( 'note', 'text' ) ) )
		);
		$member   = $this->make_user( 'member' );

		OPENBB_Poll_Responses::save( $poll, $member, array( 'happy' => true, 'note' => ' First ' ) );
		$saved = OPENBB_Poll_Responses::save( $poll, $member, array( 'happy' => false, 'note' => 'Second' ) );

		$this->assertSame( array( 'happy' => false, 'note' => 'Second' ), $saved['answers'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_poll_responses} WHERE poll_id = %d", $poll->id ) ) );
		$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_poll_answers} WHERE poll_id = %d", $poll->id ) ) );

		$this->move_cycle( $cycle_id, 'processing' );
		$this->assertWPError( OPENBB_Poll_Responses::save( $poll, $member, array( 'happy' => true ) ) );
		$this->assertSame( array( 'happy' => false, 'note' => 'Second' ), OPENBB_Poll_Responses::get_answers( $poll, $member ) );
	}

	public function test_an_empty_response_is_rejected_and_clearing_every_answer_removes_it(): void {
		$poll   = $this->poll( $this->make_cycle( 'ordering' ), array( 'questions' => array( $this->question( 'happy' ), $this->question( 'note', 'text' ) ) ) );
		$member = $this->make_user( 'member' );

		$empty = OPENBB_Poll_Responses::save( $poll, $member, array( 'happy' => '', 'note' => '  ' ) );
		$this->assertSame( 'openbb_poll_empty', $empty->get_error_code() );
		$this->assertSame( array(), OPENBB_Poll_Responses::responded( $member, array( $poll->id ) ) );

		// Skipping one question is fine: nothing is required.
		$this->assertTrue( OPENBB_Poll_Responses::save( $poll, $member, array( 'note' => 'Hello' ) )['responded'] );
		$this->assertSame( array( $poll->id ), OPENBB_Poll_Responses::responded( $member, array( $poll->id ) ) );

		$cleared = OPENBB_Poll_Responses::save( $poll, $member, array( 'happy' => '', 'note' => '' ) );
		$this->assertFalse( $cleared['responded'] );
		$this->assertSame( array(), OPENBB_Poll_Responses::responded( $member, array( $poll->id ) ) );
		$this->assertSame( 0, OPENBB_Poll_Responses::tally( $poll )['responses'] );
	}

	public function test_no_is_an_answer_and_differs_from_not_answering(): void {
		$poll    = $this->poll( $this->make_cycle( 'ordering' ), array( 'questions' => array( $this->question( 'happy' ), $this->question( 'again' ) ) ) );
		$member  = $this->make_user( 'member' );
		$skipper = $this->make_user( 'member' );

		OPENBB_Poll_Responses::save( $poll, $member, array( 'happy' => '0' ) );
		OPENBB_Poll_Responses::save( $poll, $skipper, array( 'again' => '1' ) );

		$this->assertSame( array( 'happy' => false ), OPENBB_Poll_Responses::get_answers( $poll, $member ) );
		$this->assertArrayNotHasKey( 'happy', OPENBB_Poll_Responses::get_answers( $poll, $skipper ) );

		$tally = OPENBB_Poll_Responses::tally( $poll );
		$this->assertSame( array( '1' => 0, '0' => 1 ), $tally['questions']['happy']['counts'] );
		$this->assertSame( 1, $tally['questions']['happy']['answered'] );

		// The form shows two unselected choices, never a checkbox whose "off" means No.
		$input = openbb_poll_field_input( $poll->questions[0], null );
		$this->assertSame( 'chips', $input['kind'] );
		$this->assertSame( '', $input['value'] );
		$this->assertSame( '0', openbb_poll_field_input( $poll->questions[0], false )['value'] );
	}

	public function test_unknown_questions_and_values_are_rejected_with_field_errors(): void {
		$poll   = $this->poll( $this->make_cycle( 'ordering' ), array( 'questions' => array( $this->choice( 'supplier', array( 'acme' ) ) ) ) );
		$member = $this->make_user( 'member' );

		$result = OPENBB_Poll_Responses::save( $poll, $member, array( 'supplier' => 'other', 'gone' => 'x' ) );

		$this->assertSame( 'openbb_poll_answers_invalid', $result->get_error_code() );
		$this->assertSame( array( 'supplier', 'gone' ), array_keys( $result->get_error_data()['errors'] ) );
		$this->assertSame( array(), OPENBB_Poll_Responses::get_answers( $poll, $member ) );
	}

	public function test_audience_is_members_who_ordered_in_any_selected_cycle(): void {
		global $wpdb;

		$last     = $this->make_cycle( 'complete' );
		$older    = $this->make_cycle( 'complete' );
		$current  = $this->make_cycle( 'ordering' );
		$poll     = $this->poll(
			$current,
			array(
				'audience'        => OPENBB_Poll::AUDIENCE_ORDERED,
				'audience_cycles' => array( $last, $older, 999999 ),
			)
		);
		$ordered  = $this->make_user( 'member' );
		$earlier  = $this->make_user( 'member' );
		$drafted  = $this->make_user( 'member' );
		$stranger = $this->make_user( 'member' );
		$only_fee = $this->make_user( 'member' );
		$order_id = $this->order_with_status( $last, $ordered, OPENBB_Order::STATUS_FULFILLED );
		$this->make_order( $older, $earlier );
		$this->order_with_status( $last, $drafted, OPENBB_Order::STATUS_DRAFT );
		$this->make_order( $current, $stranger );
		$wpdb->insert(
			$wpdb->openbb_orders,
			array(
				'cycle_id'   => null,
				'user_id'    => $only_fee,
				'status'     => OPENBB_Order::STATUS_SUBMITTED,
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);

		$this->assertTrue( OPENBB_Polls::check_access( $poll, $ordered ) );
		$this->assertTrue( OPENBB_Polls::check_access( $poll, $earlier ) );
		foreach ( array( $drafted, $stranger, $only_fee ) as $outsider ) {
			$this->assertSame( 'openbb_poll_audience', OPENBB_Polls::check_access( $poll, $outsider )->get_error_code() );
		}
		$this->assertSame( array( $poll->id ), wp_list_pluck( wp_list_pluck( OPENBB_Polls::open_for( $ordered ), 'poll' ), 'id' ) );
		$this->assertSame( array(), OPENBB_Polls::open_for( $stranger ) );

		// A cancelled order no longer qualifies, but what was answered is kept.
		OPENBB_Poll_Responses::save( $poll, $ordered, array( 'happy' => true ) );
		$wpdb->update( $wpdb->openbb_orders, array( 'status' => OPENBB_Order::STATUS_CANCELLED ), array( 'id' => $order_id ) );
		$this->assertWPError( OPENBB_Poll_Responses::save( $poll, $ordered, array( 'happy' => false ) ) );
		$this->assertSame( array( 'happy' => true ), OPENBB_Poll_Responses::get_answers( $poll, $ordered ) );
	}

	public function test_publishing_needs_a_question_and_a_selected_cycle(): void {
		$cycle_id = $this->make_cycle( 'ordering' );
		$no_cycle = $this->poll( $cycle_id, array( 'audience' => OPENBB_Poll::AUDIENCE_ORDERED ), false );
		$archived = $this->poll( $cycle_id, array( 'questions' => array( $this->question( 'happy', 'boolean', array( 'archived' => true ) ) ) ), false );

		foreach ( array( $no_cycle, $archived ) as $poll ) {
			$result = OPENBB_Polls::set_status( $poll, OPENBB_Poll::STATUS_PUBLISHED, $poll->revision, $this->staff );
			$this->assertWPError( $result );
			$this->assertSame( 'openbb_poll_incomplete', $result->get_error_code() );
		}

		// Changing the audience keeps earlier responses.
		$poll   = $this->poll( $cycle_id );
		$member = $this->make_user( 'member' );
		OPENBB_Poll_Responses::save( $poll, $member, array( 'happy' => true ) );
		$this->assertWPError( OPENBB_Polls::save( $poll, array( 'audience' => OPENBB_Poll::AUDIENCE_ORDERED ), $poll->revision, $this->staff ) );
		$narrowed = OPENBB_Polls::save( $poll, array( 'audience' => OPENBB_Poll::AUDIENCE_ORDERED, 'audience_cycles' => array( $cycle_id ) ), $poll->revision, $this->staff );

		$this->assertNotWPError( $narrowed );
		$this->assertWPError( OPENBB_Polls::check_access( $narrowed, $member ) );
		$this->assertSame( 1, OPENBB_Poll_Responses::tally( $narrowed )['responses'] );
	}

	public function test_suggested_cycle_is_the_latest_earlier_cycle_with_committed_orders(): void {
		global $wpdb;

		$dates = array(
			'old'     => '2030-01-01 00:00:00',
			'empty'   => '2030-03-01 00:00:00',
			'drafts'  => '2030-04-01 00:00:00',
			'current' => '2030-05-01 00:00:00',
			'future'  => '2030-06-01 00:00:00',
		);
		$ids   = array();
		foreach ( $dates as $name => $opens ) {
			$ids[ $name ] = $this->make_cycle( 'complete' );
			$wpdb->update( $wpdb->openbb_cycles, array( 'opens_at' => $opens, 'closes_at' => $opens ), array( 'id' => $ids[ $name ] ) );
		}
		$member = $this->make_user( 'member' );
		$this->make_order( $ids['old'], $member );
		$this->order_with_status( $ids['drafts'], $member, OPENBB_Order::STATUS_DRAFT );
		$this->make_order( $ids['future'], $member );

		$this->assertSame( $ids['old'], OPENBB_Polls::suggested_cycle( OPENBB_Cycles::get( $ids['current'] ) ) );
		$this->assertSame( 0, OPENBB_Polls::suggested_cycle( OPENBB_Cycles::get( $ids['old'] ) ) );

		// A copy re-runs the suggestion for its own cycle; the source's cycles would be stale.
		$source = $this->poll(
			$ids['old'],
			array(
				'audience'        => OPENBB_Poll::AUDIENCE_ORDERED,
				'audience_cycles' => array( $ids['empty'], $ids['future'] ),
				'close_rule'      => OPENBB_Poll::RULE_DATE,
				'close_at'        => '2030-02-01 00:00:00',
				'questions'       => array( $this->question( 'happy' ), $this->question( 'old', 'text', array( 'archived' => true ) ) ),
			)
		);
		$this->assertNotWPError( OPENBB_Poll_Responses::save( $source, $member, array( 'happy' => true ) ) );
		$copy = OPENBB_Polls::copy( $source, OPENBB_Cycles::get( $ids['current'] ), $this->staff );

		$this->assertNotWPError( $copy );
		$this->assertNotSame( $source->id, $copy->id );
		$this->assertSame( OPENBB_Poll::STATUS_DRAFT, $copy->status );
		$this->assertSame( $ids['current'], $copy->cycle_id );
		$this->assertSame( array( $ids['old'] ), $copy->audience_cycles );
		$this->assertSame( OPENBB_Poll::RULE_STAGE, $copy->close_rule );
		$this->assertSame( array( 'happy' ), array_column( $copy->questions, 'key' ) );
		$this->assertSame( 0, OPENBB_Poll_Responses::tally( $copy )['responses'] );
	}

	public function test_answers_never_touch_the_members_order(): void {
		global $wpdb;

		$cycle_id = $this->make_cycle( 'ordering' );
		$member   = $this->make_user( 'member' );
		$order_id = $this->make_order( $cycle_id, $member );
		$before   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->openbb_orders} WHERE id = %d", $order_id ), ARRAY_A );
		$poll     = $this->poll( $cycle_id );

		wp_set_current_user( $member );
		$response = $this->api( 'POST', '/poll/' . $poll->id, array( 'answers' => array( 'happy' => '1' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['responded'] );
		$this->assertSame( $before, $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->openbb_orders} WHERE id = %d", $order_id ), ARRAY_A ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_order_events} WHERE order_id = %d", $order_id ) ) );

		// Cancelling the order leaves the response alone.
		$wpdb->update( $wpdb->openbb_orders, array( 'status' => OPENBB_Order::STATUS_CANCELLED ), array( 'id' => $order_id ) );
		$this->assertSame( array( 'happy' => true ), OPENBB_Poll_Responses::get_answers( $poll, $member ) );
	}

	public function test_star_rating_is_a_single_choice_from_one_to_five(): void {
		$stars  = $this->question( 'rating', 'options', array( 'options' => array( 'style' => 'stars', 'multiple' => true ) ) );
		$poll   = $this->poll( $this->make_cycle( 'ordering' ), array( 'questions' => array( $stars ) ) );
		$member = $this->make_user( 'member' );
		$other  = $this->make_user( 'member' );

		$this->assertFalse( $poll->questions[0]['options']['multiple'] );
		$this->assertSame( array( '1', '2', '3', '4', '5' ), OPENBB_Field_Schema::option_keys( $poll->questions[0] ) );

		$this->assertWPError( OPENBB_Poll_Responses::save( $poll, $member, array( 'rating' => '6' ) ) );
		OPENBB_Poll_Responses::save( $poll, $member, array( 'rating' => '4' ) );
		OPENBB_Poll_Responses::save( $poll, $other, array( 'rating' => '5' ) );

		$tally = OPENBB_Poll_Responses::tally( $poll )['questions']['rating'];
		$this->assertSame( 4.5, $tally['average'] );
		$this->assertSame( array( '1' => 0, '2' => 0, '3' => 0, '4' => 1, '5' => 1 ), $tally['counts'] );

		// Stars render in reading order; which are filled comes from the value, not the markup order.
		$field = openbb_poll_field_input( $poll->questions[0], array( '4' ) );
		$html  = openbb_render_field( $field );
		$this->assertSame( 'stars', $field['kind'] );
		$this->assertSame( 1, preg_match_all( '/value="1".*value="2".*value="3".*value="4".*value="5"/s', $html ) );
		$this->assertSame( 4, substr_count( $html, 'openbb-star is-filled' ) );
		$this->assertSame( 5, substr_count( $html, 'type="radio"' ) );
		$this->assertStringContainsString( '4 of 5 stars', $html );
		$this->assertStringContainsString( 'openbb/forms::actions.clear', $html );

		// A rating can be taken back.
		$this->assertFalse( OPENBB_Poll_Responses::save( $poll, $member, array( 'rating' => '' ) )['responded'] );

		// Profile fields don't offer the style.
		$profile = OPENBB_Profile_Fields::normalize_field( $stars );
		$this->assertSame( 'select', $profile['options']['style'] );
	}

	public function test_a_filtered_star_icon_is_reduced_to_the_svg_allowlist(): void {
		$filter = static function (): string {
			return '<svg viewBox="0 0 10 10" onload="alert(1)"><script>alert(1)</script><circle cx="5" cy="5" r="4" onclick="x()"/></svg>';
		};
		add_filter( 'openbb_rating_star_svg', $filter );
		$icon = openbb_star_icon();
		remove_filter( 'openbb_rating_star_svg', $filter );

		$this->assertStringContainsString( '<circle cx="5" cy="5" r="4"', $icon );
		$this->assertStringContainsString( 'viewBox="0 0 10 10"', $icon );
		$this->assertStringNotContainsString( 'onload', $icon );
		$this->assertStringNotContainsString( 'onclick', $icon );
		$this->assertStringNotContainsString( '<script', $icon );

		add_filter( 'openbb_rating_star_svg', '__return_empty_string' );
		$this->assertStringContainsString( '<svg', openbb_star_icon() );
		remove_filter( 'openbb_rating_star_svg', '__return_empty_string' );
	}

	public function test_published_questions_keep_their_key_and_type_and_archiving_keeps_answers(): void {
		$questions = array( $this->question( 'happy' ), $this->choice( 'supplier', array( 'acme', 'bulk_co' ) ) );
		$draft     = $this->poll( $this->make_cycle( 'ordering' ), array( 'questions' => $questions ), false );

		// A draft has no answers yet, so anything can still change.
		$draft = OPENBB_Polls::save( $draft, array( 'questions' => array( $this->question( 'happy', 'text' ), $questions[1] ) ), $draft->revision, $this->staff );
		$this->assertNotWPError( $draft );
		$draft = OPENBB_Polls::save( $draft, array( 'questions' => $questions ), $draft->revision, $this->staff );
		$poll  = OPENBB_Polls::set_status( $draft, OPENBB_Poll::STATUS_PUBLISHED, $draft->revision, $this->staff );

		$member = $this->make_user( 'member' );
		OPENBB_Poll_Responses::save( $poll, $member, array( 'happy' => true, 'supplier' => 'acme' ) );

		$removed  = OPENBB_Polls::save( $poll, array( 'questions' => array( $questions[1] ) ), $poll->revision, $this->staff );
		$retyped  = OPENBB_Polls::save( $poll, array( 'questions' => array( $this->question( 'happy', 'text' ), $questions[1] ) ), $poll->revision, $this->staff );
		$dropped  = OPENBB_Polls::save( $poll, array( 'questions' => array( $questions[0], $this->choice( 'supplier', array( 'bulk_co' ) ) ) ), $poll->revision, $this->staff );
		$multiple = OPENBB_Polls::save( $poll, array( 'questions' => array( $questions[0], $this->choice( 'supplier', array( 'acme', 'bulk_co' ), array( 'multiple' => true ) ) ) ), $poll->revision, $this->staff );
		foreach ( array( $removed, $retyped, $dropped, $multiple ) as $result ) {
			$this->assertWPError( $result );
			$this->assertSame( 'openbb_invalid_poll', $result->get_error_code() );
		}

		// Archive one question and one chosen option, relabel another, add a new question.
		$questions[0]['archived']                                     = true;
		$questions[1]['label']['default']                             = 'Preferred supplier';
		$questions[1]['options']['groups'][0]['options'][0]['archived'] = true;
		$questions[]  = $this->question( 'note', 'text' );
		$edited       = OPENBB_Polls::save( $poll, array( 'questions' => $questions ), $poll->revision, $this->staff );
		$this->assertNotWPError( $edited );

		// The member's form no longer asks the archived question but keeps their archived choice.
		$this->assertSame( array( 'supplier', 'note' ), array_keys( $edited->active_questions() ) );
		$resaved = OPENBB_Poll_Responses::save( $edited, $member, array( 'supplier' => 'acme', 'note' => 'Thanks' ) );
		$this->assertNotWPError( $resaved );
		$this->assertSame( array( 'happy' => true, 'supplier' => array( 'acme' ), 'note' => 'Thanks' ), $resaved['answers'] );
		$this->assertWPError( OPENBB_Poll_Responses::save( $edited, $this->make_user( 'member' ), array( 'supplier' => 'acme' ) ) );
		$this->assertWPError( OPENBB_Poll_Responses::save( $edited, $member, array( 'happy' => false ) ) );
		$this->assertSame( 1, OPENBB_Poll_Responses::tally( $edited )['questions']['happy']['counts']['1'] );
	}

	public function test_a_stale_revision_is_a_conflict(): void {
		$poll  = $this->poll( $this->make_cycle( 'ordering' ), array(), false );
		$first = OPENBB_Polls::save( $poll, array( 'title' => array( 'default' => 'First' ) ), $poll->revision, $this->staff );
		$stale = OPENBB_Polls::save( $poll, array( 'title' => array( 'default' => 'Second' ) ), $poll->revision, $this->staff );

		$this->assertNotWPError( $first );
		$this->assertSame( 'openbb_poll_stale', $stale->get_error_code() );
		$this->assertSame( 409, $stale->get_error_data()['status'] );
		$this->assertSame( 'First', OPENBB_Polls::get( $poll->id )->get_title() );
	}

	public function test_staff_routes_need_poll_management(): void {
		$cycle_id = $this->make_cycle( 'ordering' );
		$poll     = $this->poll( $cycle_id );

		$routes = array(
			array( 'GET', '/polls', array( 'cycle_id' => $cycle_id ) ),
			array( 'GET', '/polls/' . $poll->id, array() ),
			array( 'GET', '/polls/' . $poll->id . '/results', array() ),
			array( 'GET', '/polls/' . $poll->id . '/responses', array() ),
			array( 'PUT', '/polls/' . $poll->id, array( 'revision' => $poll->revision, 'audience' => 'all' ) ),
			array( 'POST', '/polls/' . $poll->id . '/status', array( 'revision' => $poll->revision, 'status' => 'closed' ) ),
			array( 'DELETE', '/polls/' . $poll->id, array() ),
		);
		foreach ( array( 'member' => 403, 'aid_reviewer' => 403, '' => 403 ) as $membership => $status ) {
			wp_set_current_user( $this->make_user( $membership ) );
			foreach ( $routes as list( $method, $route, $params ) ) {
				$this->assertSame( $status, $this->api( $method, $route, $params )->get_status(), "$method $route as '$membership'" );
			}
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->api( 'GET', '/polls/' . $poll->id . '/responses' )->get_status() );

		$this->assertTrue( user_can( $this->staff, 'openbb_manage_polls' ) );
		$this->assertFalse( user_can( $this->make_user( 'member' ), 'openbb_manage_polls' ) );
		$this->assertTrue( user_can( $this->make_user( 'member' ), 'openbb_answer_polls' ) );
		$this->assertSame( OPENBB_Poll::STATUS_PUBLISHED, OPENBB_Polls::get( $poll->id )->status );
	}

	public function test_staff_manage_a_poll_through_rest(): void {
		$cycle_id = $this->make_cycle( 'ordering' );
		wp_set_current_user( $this->staff );

		$created = $this->api(
			'POST',
			'/polls',
			array(
				'cycle_id'   => $cycle_id,
				'title'      => array( 'default' => ' <b>Feedback</b> ', 'fr' => 'Avis' ),
				'intro'      => array( 'default' => "Line one\nLine two" ),
				'close_rule' => 'date',
				'close_at'   => '2030-01-02T10:00:00+00:00',
				'placements' => array( 'thank_you' ),
				'questions'  => array( $this->question( 'happy' ) ),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$poll = $created->get_data();
		$this->assertSame( array( 'default' => 'Feedback', 'fr' => 'Avis' ), $poll['title'] );
		$this->assertSame( "Line one\nLine two", $poll['intro']['default'] );
		$this->assertSame( array( 'thank_you' ), $poll['placements'] );
		$this->assertSame( 'draft', $poll['phase'] );
		$this->assertSame( strtotime( '2030-01-02 10:00:00 UTC' ), strtotime( $poll['close_at'] ) );

		$published = $this->api( 'POST', '/polls/' . $poll['id'] . '/status', array( 'status' => 'published', 'revision' => $poll['revision'] ) );
		$this->assertSame( 'open', $published->get_data()['phase'] );
		$this->assertSame( 409, $this->api( 'PUT', '/polls/' . $poll['id'], array( 'revision' => $poll['revision'], 'audience' => 'all' ) )->get_status() );

		$list = $this->api( 'GET', '/polls', array( 'cycle_id' => $cycle_id ) )->get_data();
		$this->assertSame( array( $poll['id'] ), wp_list_pluck( $list, 'id' ) );

		$copied = $this->api( 'POST', '/polls', array( 'cycle_id' => $this->make_cycle( 'setup' ), 'copy_from' => $poll['id'] ) );
		$this->assertSame( 201, $copied->get_status() );
		$this->assertSame( 'draft', $copied->get_data()['status'] );

		$this->assertTrue( $this->api( 'DELETE', '/polls/' . $poll['id'] )->get_data()['deleted'] );
		$this->assertSame( 404, $this->api( 'GET', '/polls/' . $poll['id'] )->get_status() );
	}

	public function test_counts_hold_no_identity_or_free_text_and_responses_do(): void {
		$poll   = $this->poll( $this->make_cycle( 'ordering' ), array( 'questions' => array( $this->question( 'happy' ), $this->question( 'note', 'text' ) ) ) );
		$member = $this->make_user( 'member' );
		OPENBB_Poll_Responses::save( $poll, $member, array( 'happy' => true, 'note' => 'My name is Sam and I live on Elm Street' ) );
		// Deactivating a member is not an erasure request: their answers stay.
		openbb_update_member_field( $member, 'status', OPENBB_Access::STATUS_INACTIVE );
		OPENBB_Access::flush_cache();

		wp_set_current_user( $this->staff );
		$results   = $this->api( 'GET', '/polls/' . $poll->id . '/results' );
		$responses = $this->api( 'GET', '/polls/' . $poll->id . '/responses' );
		$user      = get_userdata( $member );

		$encoded = wp_json_encode( $results->get_data() );
		$this->assertSame( 1, $results->get_data()['responses'] );
		$this->assertStringNotContainsString( 'Elm Street', $encoded );
		$this->assertStringNotContainsString( $user->user_email, $encoded );
		$this->assertStringNotContainsString( $user->display_name, $encoded );
		$this->assertArrayNotHasKey( 'counts', (array) ( (array) $results->get_data()['questions'] )['note'] );

		$row = $responses->get_data()[0];
		$this->assertSame( '1', $responses->get_headers()['X-WP-Total'] );
		$this->assertSame( $member, $row['member']['id'] );
		$this->assertSame( OPENBB_Access::STATUS_INACTIVE, $row['member']['status'] );
		$this->assertSame( 'My name is Sam and I live on Elm Street', ( (array) $row['answers'] )['note'] );
	}

	public function test_members_list_and_read_only_their_own_open_polls(): void {
		$cycle_id = $this->make_cycle( 'ordering' );
		$open     = $this->poll( $cycle_id, array( 'questions' => array( $this->choice( 'supplier', array( 'acme', 'bulk_co' ) ) ) ) );
		$this->poll( $cycle_id, array(), false );
		$this->poll( $this->make_cycle( 'setup' ) );
		$member = $this->make_user( 'member' );
		OPENBB_Poll_Responses::save( $open, $this->make_user( 'member' ), array( 'supplier' => 'bulk_co' ) );

		wp_set_current_user( $member );
		$list = $this->api( 'GET', '/poll' )->get_data();
		$this->assertSame( array( $open->id ), wp_list_pluck( $list, 'id' ) );
		$this->assertFalse( $list[0]['responded'] );

		$this->api( 'POST', '/poll/' . $open->id, array( 'answers' => array( 'supplier' => 'acme' ) ) );
		$state = $this->api( 'GET', '/poll/' . $open->id )->get_data();
		$this->assertTrue( $state['responded'] );
		$this->assertSame( array( 'supplier' => 'acme' ), (array) $state['answers'] );
		$this->assertSame( 'chips', $state['fields'][0]['kind'] );
		$this->assertTrue( $state['fields'][0]['clearable'] );
	}

	public function test_privacy_tools_export_and_erase_poll_answers(): void {
		global $wpdb;

		$cycle_id = $this->make_cycle( 'ordering' );
		$poll     = $this->poll( $cycle_id, array( 'questions' => array( $this->question( 'happy' ), $this->choice( 'supplier', array( 'acme' ) ), $this->question( 'note', 'text' ) ) ) );
		$member   = $this->make_user( 'member' );
		$other    = $this->make_user( 'member' );
		OPENBB_Poll_Responses::save( $poll, $member, array( 'happy' => false, 'supplier' => 'acme', 'note' => 'Private note' ) );
		OPENBB_Poll_Responses::save( $poll, $other, array( 'happy' => true ) );

		$provider = OPENBB_Privacy::providers()['polls'];
		$export   = $provider->export( get_userdata( $member ), 1 );
		$data     = wp_list_pluck( $export['data'][0]['data'], 'value', 'name' );

		$this->assertTrue( $export['done'] );
		$this->assertSame( 'Supplier vote', $data['Poll'] );
		$this->assertSame( 'Test cycle', $data['Cycle'] );
		$this->assertSame( 'No', $data['Happy'] );
		$this->assertSame( 'Acme', $data['Supplier'] );
		$this->assertSame( 'Private note', $data['Note'] );

		$erased = $provider->erase( get_userdata( $member ), 1 );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertFalse( $erased['items_retained'] );
		$this->assertSame( array(), OPENBB_Poll_Responses::get_answers( $poll, $member ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_poll_answers} WHERE value_text = %s", 'Private note' ) ) );
		$this->assertSame( array( 'happy' => true ), OPENBB_Poll_Responses::get_answers( $poll, $other ) );
		$this->assertSame( array(), $provider->export( get_userdata( $member ), 1 )['data'] );
	}

	public function test_deleting_a_poll_or_its_cycle_or_all_site_data_removes_the_answers(): void {
		global $wpdb;

		$count = static function () use ( $wpdb ): array {
			return array(
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_polls}" ),
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_poll_responses}" ),
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_poll_answers}" ),
			);
		};
		$member = $this->make_user( 'member' );
		$cycles = array( $this->make_cycle( 'ordering' ), $this->make_cycle( 'ordering' ), $this->make_cycle( 'ordering' ), $this->make_cycle( 'ordering' ) );
		$polls  = array();
		foreach ( $cycles as $cycle_id ) {
			$poll = $this->poll( $cycle_id );
			OPENBB_Poll_Responses::save( $poll, $member, array( 'happy' => true ) );
			$polls[] = $poll;
		}
		$this->make_order( $cycles[2], $member );
		$this->assertSame( array( 4, 4, 4 ), $count() );

		$this->assertTrue( OPENBB_Polls::delete( $polls[0] ) );
		$this->assertSame( array( 3, 3, 3 ), $count() );

		$this->assertTrue( OPENBB_Cycles::get( $cycles[1] )->delete() );
		$this->assertSame( array( 2, 2, 2 ), $count() );

		$this->assertTrue( OPENBB_Cycles::get( $cycles[2] )->delete( true ) );
		$this->assertSame( array( 1, 1, 1 ), $count() );

		$this->assertTrue( OPENBB_Maintenance::delete_all_site_data() );
		$this->assertSame( array( 0, 0, 0 ), $count() );
	}

	public function test_the_polls_view_renders_a_members_answers_without_script(): void {
		$cycle_id = $this->make_cycle( 'ordering' );
		$poll     = $this->poll(
			$cycle_id,
			array(
				'intro'     => array( 'default' => "First line\n\nSecond <b>line</b>" ),
				'questions' => array(
					$this->question( 'rating', 'options', array( 'options' => array( 'style' => 'stars' ) ) ),
					$this->question( 'happy' ),
					$this->question( 'note', 'text', array( 'text' => array( 'multiline' => true ) ) ),
				),
			)
		);
		$this->poll( $cycle_id, array( 'title' => array( 'default' => 'Other poll' ) ) );
		$member = $this->make_user( 'member' );
		wp_set_current_user( $member );

		$empty = OPENBB_Polls_View::polls( $member, $poll->id );
		$this->assertStringContainsString( 'Supplier vote', $empty );
		$this->assertStringNotContainsString( 'Other poll', $empty );
		$this->assertStringNotContainsString( 'openbb-star is-filled', $empty );
		$this->assertStringContainsString( '<p>Second line</p>', $empty );
		$this->assertStringContainsString( 'Co-op staff can see your answers.', $empty );
		$this->assertSame( 0, preg_match( '/<input[^>]*\schecked/', $empty ) );
		// Saving swaps the form for a confirmation, hidden until then. The polls page has
		// no order page to go back to.
		$this->assertMatchesRegularExpression( '/<div class="openbb-poll__confirmation"[^>]*\shidden/', $empty );
		$this->assertDoesNotMatchRegularExpression( '/<form class="openbb-poll__form"[^>]*\shidden/', $empty );
		$this->assertStringContainsString( 'View or change your answers', $empty );
		$this->assertStringNotContainsString( 'Back to the order page', $empty );

		OPENBB_Poll_Responses::save( $poll, $member, array( 'rating' => '2', 'happy' => '0', 'note' => 'Thanks' ) );
		$answered = OPENBB_Polls_View::polls( $member, $poll->id );
		$this->assertSame( 2, substr_count( $answered, 'openbb-star is-filled' ) );
		$this->assertSame( 2, preg_match_all( '/<input[^>]*\schecked/', $answered ) );
		$this->assertSame( 1, preg_match( '/<input[^>]*value="0"[^>]*\schecked/', $answered ) );
		$this->assertStringContainsString( '>Thanks</textarea>', $answered );

		// Someone outside the audience is told why, and offered what they can answer.
		$closed = OPENBB_Polls::set_status( OPENBB_Polls::get( $poll->id ), OPENBB_Poll::STATUS_CLOSED, $poll->revision, $this->staff );
		$this->assertNotWPError( $closed );
		$after = OPENBB_Polls_View::polls( $member, $poll->id );
		$this->assertStringContainsString( 'This poll is closed.', $after );
		$this->assertStringContainsString( 'Other poll', $after );
	}

	public function test_the_order_form_invites_to_polls_and_shows_one_on_request(): void {
		global $wpdb;

		$cycle_id = $this->make_cycle( 'ordering' );
		$wpdb->update(
			$wpdb->openbb_cycles,
			array(
				'opens_at'  => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'closes_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			),
			array( 'id' => $cycle_id )
		);
		$card   = $this->poll( $cycle_id, array( 'title' => array( 'default' => 'Card poll' ) ) );
		$this->poll( $cycle_id, array( 'title' => array( 'default' => 'Post-order feedback' ), 'placements' => array( OPENBB_Poll::PLACEMENT_THANK_YOU ) ) );
		$member = $this->make_user( 'member' );
		wp_set_current_user( $member );

		$form = OPENBB_Order_Form_View::render();
		$this->assertStringContainsString( 'openbb-catalog', $form );
		$this->assertStringContainsString( 'Card poll', $form );
		$this->assertStringNotContainsString( 'Post-order feedback', $form );
		$this->assertStringNotContainsString( 'openbb-poll__form', $form );

		$_GET['poll'] = (string) $card->id;
		$single       = OPENBB_Order_Form_View::render();
		unset( $_GET['poll'] );
		$this->assertStringContainsString( 'openbb-poll__form', $single );
		$this->assertStringContainsString( 'Back to the order page', $single );
		$this->assertStringNotContainsString( 'openbb-catalog', $single );
		// Once saved, the poll's confirmation leads back to the order page first.
		$this->assertMatchesRegularExpression( '/openbb-poll__confirmation.+<a class="openbb-button openbb-button--primary" href="[^"]*#openbb-order">Back to the order page<\/a>/s', $single );

		// With ordering closed the page still leads to open polls.
		$this->move_cycle( $cycle_id, 'setup' );
		$vote   = $this->poll( $cycle_id, array( 'title' => array( 'default' => 'Vote before ordering' ), 'open_rule' => OPENBB_Poll::RULE_PUBLISH ) );
		$closed = OPENBB_Order_Form_View::render();
		$this->assertStringContainsString( 'no ordering period open', $closed );
		$this->assertStringContainsString( 'Vote before ordering', $closed );
		$this->assertStringContainsString( 'poll=' . $vote->id, $closed );
	}

	public function test_order_page_surfaces_follow_each_polls_placement(): void {
		$cycle_id = $this->make_cycle( 'ordering' );
		$card     = $this->poll( $cycle_id, array( 'title' => array( 'default' => 'Card poll' ) ) );
		$survey   = $this->poll(
			$cycle_id,
			array(
				'title'           => array( 'default' => 'After ordering' ),
				'placements'      => array( OPENBB_Poll::PLACEMENT_THANK_YOU ),
				'audience'        => OPENBB_Poll::AUDIENCE_ORDERED,
				'audience_cycles' => array( $cycle_id ),
				'questions'       => array( $this->question( 'feedback', 'text' ) ),
			)
		);
		$this->poll( $cycle_id, array( 'title' => array( 'default' => 'Link only poll' ), 'placements' => array() ) );
		$member = $this->make_user( 'member' );
		wp_set_current_user( $member );

		$this->assertSame( array(), OPENBB_Polls_View::thank_you_polls( $member ) );
		$this->make_order( $cycle_id, $member );
		$cards = OPENBB_Polls_View::cards( $member );
		$this->assertStringContainsString( 'Card poll', $cards );
		$this->assertStringContainsString( 'poll=' . $card->id, $cards );
		$this->assertStringNotContainsString( 'After ordering', $cards );
		$this->assertStringNotContainsString( 'Link only poll', $cards );

		OPENBB_Poll_Responses::save( $survey, $member, array( 'feedback' => 'More oats please' ) );
		$_GET['cycle']      = (string) $cycle_id;
		$_GET['order_view'] = 'thank-you';
		try {
			$html = OPENBB_Order_Form_View::render();
			$this->assertStringContainsString( 'After ordering', $html );
			$this->assertStringContainsString( 'data-wp-interactive="openbb/polls"', $html );
			$this->assertStringContainsString( 'More oats please', $html );
			$this->assertStringContainsString( 'Answering is optional.', $html );
			// The thank-you page confirms a save under the form instead.
			$this->assertStringContainsString( 'openbb-poll__status', $html );
			$this->assertStringNotContainsString( 'openbb-poll__confirmation', $html );
			$this->assertStringNotContainsString( 'Card poll', $html );
			$this->assertStringNotContainsString( 'Link only poll', $html );
		} finally {
			unset( $_GET['cycle'], $_GET['order_view'] );
		}

		// Once answered, the invitation becomes a link to change the answers.
		OPENBB_Poll_Responses::save( $card, $member, array( 'happy' => true ) );
		$answered = OPENBB_Polls_View::cards( $member );
		$this->assertStringNotContainsString( 'openbb-poll-card__title', $answered );
		$this->assertStringContainsString( 'Change your answers', $answered );

		$this->assertSame( '', OPENBB_Polls_View::cards( $this->make_user( 'applicant' ) ) );
	}

	public function test_existing_finalize_placements_move_to_thank_you_but_new_writes_cannot_select_finalize(): void {
		global $wpdb;

		$poll = $this->poll( $this->make_cycle( 'ordering' ) );
		$wpdb->update( $wpdb->openbb_polls, array( 'placements' => '["card","finalize"]' ), array( 'id' => $poll->id ) );
		wp_set_current_user( $this->staff );

		$settings = $this->api( 'GET', '/polls/' . $poll->id )->get_data();
		$this->assertSame( array( 'card', 'thank_you' ), $settings['placements'] );
		$invalid = $this->api( 'PUT', '/polls/' . $poll->id, array( 'revision' => $poll->revision, 'placements' => array( 'finalize' ) ) );
		$this->assertSame( 400, $invalid->get_status() );
	}
}
