<?php
/**
 * Admin REST API: staff recording and reviewing membership fee payments on members.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_REST_Membership_Fees extends OPENBB_TestCase {

	/** @var int */
	private $coordinator;

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		$this->coordinator = $this->make_user( 'coordinator', '' );
		wp_set_current_user( $this->coordinator );
	}

	private function api( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/openbb/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_do_request( $request );
	}

	private function pay( int $member_id, array $params = array() ): WP_REST_Response {
		return $this->api( 'POST', "/members/{$member_id}/membership-payment", $params );
	}

	private function require_service(): void {
		$this->assertTrue( class_exists( 'OPENBB_Membership_Fees' ) );
	}

	private function set_one_time_fee( int $amount ): void {
		update_option(
			OPENBB_Settings::GENERAL_OPTION,
			array_merge(
				(array) get_option( OPENBB_Settings::GENERAL_OPTION, array() ),
				array(
					'membership_fee_type'   => 'one_time',
					'membership_fee_amount' => $amount,
				)
			)
		);
	}

	public function test_member_response_has_fee_summary_and_permission_flag(): void {
		$member = $this->make_user( 'member' );
		$item   = $this->api( 'GET', "/members/{$member}" )->get_data();

		$this->assertArrayHasKey( 'membership_fee', $item );
		$this->assertArrayHasKey( 'can_record_membership_payment', $item );
		$this->assertIsBool( $item['can_record_membership_payment'] );

		$this->assertTrue( $item['can_record_membership_payment'] );
		foreach ( array( 'status', 'amount', 'currency', 'pending_order_id', 'history' ) as $key ) {
			$this->assertArrayHasKey( $key, $item['membership_fee'] );
		}
		$this->assertContains( $item['membership_fee']['status'], array( 'not_applicable', 'due', 'pending', 'paid' ) );

		// Viewing members without recording payments: summary shown, no recording.
		$viewer = $this->make_user( 'member' );
		$this->set_viewer_caps( $viewer );
		wp_set_current_user( $viewer );
		$item = $this->api( 'GET', "/members/{$member}" )->get_data();
		$this->assertFalse( $item['can_record_membership_payment'] );
	}

	/**
	 * A role that may view members but not record payments.
	 */
	private function set_viewer_caps( int $user_id ): void {
		add_filter(
			'openbb_default_roles',
			static function ( array $roles ): array {
				$roles['members_viewer'] = array( 'openbb_view_members' );
				return $roles;
			}
		);
		OPENBB_Roles::flush();
		add_user_meta( $user_id, openbb_member_meta_key( 'roles' ), 'members_viewer' );
		OPENBB_Roles::flush();
	}

	public function test_payment_requires_login_record_payments_and_edit_members(): void {
		$member = $this->make_user( 'member' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->pay( $member )->get_status() );

		wp_set_current_user( $this->make_user( 'member' ) );
		$this->assertSame( 403, $this->pay( $member )->get_status() );

		// Distribution helpers record order payments but can't edit members.
		wp_set_current_user( $this->make_user( 'distribution_helper' ) );
		$this->assertSame( 403, $this->pay( $member )->get_status() );
		$this->assertSame( 403, $this->pay( $member, array( 'order_id' => 1 ) )->get_status() );
	}

	public function test_payment_validates_member_and_review_flags(): void {
		$member = $this->make_user( 'applicant' );

		$this->assertSame( 404, $this->pay( 999999 )->get_status() );
		$this->assertSame( 404, $this->pay( $this->make_user() )->get_status() );

		$response = $this->pay( $member, array( 'approve_member' => true ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( 400, $this->pay( $member, array( 'reject' => true ) )->get_status() );
		$this->assertSame(
			400,
			$this->pay(
				$member,
				array(
					'order_id'       => 1,
					'approve_member' => true,
					'reject'         => true,
				)
			)->get_status()
		);
		$this->assertSame( 400, $this->pay( $member, array( 'order_id' => -1 ) )->get_status() );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, OPENBB_Access::get_status( $member ) );
	}

	public function test_coordinator_records_due_fee_once(): void {
		$this->require_service();
		$this->set_one_time_fee( 2500 );
		$member = $this->make_user( 'member' );

		$before = $this->api( 'GET', "/members/{$member}" )->get_data()['membership_fee'];
		$this->assertSame( 'due', $before['status'] );

		$response = $this->pay( $member, array( 'reference' => 'ET-123' ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$item = $response->get_data();
		$this->assertSame( $member, $item['id'] );
		$this->assertGreaterThan( 0, $item['order']['id'] );
		$this->assertSame( 'paid', $item['membership_fee']['status'] );
		$this->assertContains( $item['order']['id'], wp_list_pluck( $item['membership_fee']['history'], 'id' ) );

		// A one-time fee is settled: recording again must not create a second payment.
		$again = $this->pay( $member );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'openbb_membership_fee_not_due', $again->get_data()['code'] );
		$this->assertCount( 1, $this->api( 'GET', "/members/{$member}" )->get_data()['membership_fee']['history'] );
	}

	public function test_no_fee_configured_is_not_applicable_and_not_recordable(): void {
		$this->require_service();
		$this->set_one_time_fee( 0 );
		$member = $this->make_user( 'member' );

		$this->assertSame( 'not_applicable', $this->api( 'GET', "/members/{$member}" )->get_data()['membership_fee']['status'] );
		$response = $this->pay( $member );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'openbb_membership_fee_not_due', $response->get_data()['code'] );
	}

	public function test_service_refuses_targets_the_actor_cannot_edit(): void {
		$this->require_service();
		$this->set_one_time_fee( 2500 );
		// Coordinators can't edit someone holding openbb_manage_access.
		$manager = $this->make_user( 'manager' );

		$response = $this->pay( $manager );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->api( 'GET', "/members/{$manager}" )->get_data()['membership_fee']['history'] );
		$this->assertFalse( $this->api( 'GET', "/members/{$manager}" )->get_data()['can_record_membership_payment'] );
	}

	private function claim( int $user_id, string $reference = 'Sent by e-transfer' ): int {
		$order = OPENBB_Membership_Fees::claim( $user_id, $reference );
		$this->assertInstanceOf( OPENBB_Order::class, $order, is_wp_error( $order ) ? $order->get_error_message() : '' );

		return $order->id;
	}

	public function test_confirming_active_members_pending_claim_marks_it_paid(): void {
		$this->require_service();
		$this->set_one_time_fee( 2500 );
		$member   = $this->make_user( 'member' );
		$order_id = $this->claim( $member );

		$fee = $this->api( 'GET', "/members/{$member}" )->get_data()['membership_fee'];
		$this->assertSame( 'pending', $fee['status'] );
		$this->assertSame( $order_id, $fee['pending_order_id'] );
		$this->assertSame( 'Sent by e-transfer', $fee['history'][0]['reference'] );

		$response = $this->pay( $member, array( 'order_id' => $order_id ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$item = $response->get_data();
		$this->assertSame( $order_id, $item['order']['id'] );
		$this->assertSame( OPENBB_Order::STATUS_FULFILLED, $item['order']['status'] );
		$this->assertSame( 'paid', $item['membership_fee']['status'] );
		$this->assertSame( 0, $item['membership_fee']['pending_order_id'] );
		$this->assertNotNull( $item['membership_fee']['history'][0]['paid_at'] );
	}

	public function test_rejecting_a_claim_cancels_it_and_leaves_fee_due(): void {
		$this->require_service();
		$this->set_one_time_fee( 2500 );
		$member   = $this->make_user( 'member' );
		$order_id = $this->claim( $member );

		$response = $this->pay(
			$member,
			array(
				'order_id' => $order_id,
				'reject'   => true,
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( OPENBB_Order::STATUS_CANCELLED, $response->get_data()['order']['status'] );
		$this->assertSame( 'due', $response->get_data()['membership_fee']['status'] );

		// A reviewed claim can't then be confirmed.
		$this->assertSame( 409, $this->pay( $member, array( 'order_id' => $order_id ) )->get_status() );
	}

	public function test_combined_approval_activates_applicant_and_confirms_claim(): void {
		$this->require_service();
		$this->set_one_time_fee( 2500 );
		$applicant = $this->make_user( 'applicant' );
		$order_id  = $this->claim( $applicant );

		$response = $this->pay(
			$applicant,
			array(
				'order_id'       => $order_id,
				'approve_member' => true,
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$item = $response->get_data();
		$this->assertSame( OPENBB_Access::STATUS_ACTIVE, $item['status'] );
		$this->assertSame( OPENBB_Access::STATUS_ACTIVE, OPENBB_Access::get_status( $applicant ) );
		$this->assertSame( 'paid', $item['membership_fee']['status'] );
	}

	public function test_confirming_claim_alone_leaves_applicant_pending_approval(): void {
		$this->require_service();
		$this->set_one_time_fee( 2500 );
		$applicant = $this->make_user( 'applicant' );
		$order_id  = $this->claim( $applicant );

		$response = $this->pay( $applicant, array( 'order_id' => $order_id ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, OPENBB_Access::get_status( $applicant ) );
		$this->assertSame( 'paid', $response->get_data()['membership_fee']['status'] );
	}

	public function test_review_of_another_members_claim_fails_without_changes(): void {
		$this->require_service();
		$this->set_one_time_fee( 2500 );
		$owner     = $this->make_user( 'member' );
		$applicant = $this->make_user( 'applicant' );
		$order_id  = $this->claim( $owner );

		$response = $this->pay(
			$applicant,
			array(
				'order_id'       => $order_id,
				'approve_member' => true,
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'openbb_invalid_membership_order', $response->get_data()['code'] );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, OPENBB_Access::get_status( $applicant ) );
		$this->assertSame( 'pending', $this->api( 'GET', "/members/{$owner}" )->get_data()['membership_fee']['status'] );
	}

	public function test_review_of_unknown_order_fails_and_leaves_applicant(): void {
		$this->require_service();
		$this->set_one_time_fee( 2500 );
		$applicant = $this->make_user( 'applicant' );

		$response = $this->pay(
			$applicant,
			array(
				'order_id'       => 999999,
				'approve_member' => true,
			)
		);
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertLessThan( 500, $response->get_status() );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, OPENBB_Access::get_status( $applicant ) );
	}
}
