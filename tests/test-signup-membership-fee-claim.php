<?php
/**
 * Signup fee claims are email-bound, independently reviewed, and atomic with signup.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Signup_Membership_Fee_Claim extends OPENBB_TestCase {

	private $mail = array();
	private $rules = 0;

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		wp_set_current_user( 0 );
		unset( $_COOKIE[ OPENBB_REST_Signup::COOKIE ] );
		$this->mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		add_filter( 'send_auth_cookies', '__return_false' );
		add_filter( 'openbb_rate_limit', '__return_zero' );
		$this->settings(
			array(
				'allow_signups'           => true,
				'require_member_approval' => true,
				'membership_fee_amount'   => 1250,
				'membership_fee_type'     => 'one_time',
			)
		);
		OPENBB_Rules::save_draft( array( array( 'default' => '<p>Be kind.</p>' ) ) );
		$this->rules = OPENBB_Rules::publish( true, 1 )->id;
	}

	public function tear_down(): void {
		unset( $_COOKIE[ OPENBB_REST_Signup::COOKIE ] );
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		remove_filter( 'send_auth_cookies', '__return_false' );
		remove_filter( 'openbb_rate_limit', '__return_zero' );
		parent::tear_down();
	}

	public function capture_mail( $result, array $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	private function settings( array $values ): void {
		update_option( OPENBB_Settings::GENERAL_OPTION, array_merge( OPENBB_Settings::get_general(), $values ), false );
	}

	private function account(): array {
		return array(
			'email'      => 'claim-signup@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Doe',
			'phone'      => '555-0100',
		);
	}

	private function post( string $route = '', array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/openbb/v1/signup' . $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		return rest_do_request( $request );
	}

	private function start( bool $claim = true ): WP_REST_Response {
		return $this->post(
			'',
			array(
				'account'                  => $this->account(),
				'rules_version'            => $this->rules,
				'membership_fee_claim'     => $claim,
				'membership_fee_reference' => 'Transfer ABC-123',
			)
		);
	}

	private function code(): string {
		$this->assertNotEmpty( $this->mail );
		$this->assertSame( 1, preg_match( '/font-family: monospace[^>]*>(\d{6})<\/p>/', end( $this->mail )['message'], $matches ) );
		return $matches[1];
	}

	private function order_ids(): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$wpdb->openbb_orders} ORDER BY id" ) );
	}

	private function assert_claim( int $user_id, string $reference = 'Transfer ABC-123' ): void {
		global $wpdb;
		$ids = $this->order_ids();
		$this->assertCount( 1, $ids );
		$order = OPENBB_Orders::get( $ids[0] );
		$this->assertSame( $user_id, $order->user_id );
		$this->assertSame( 0, $order->cycle_id );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT cycle_id FROM {$wpdb->openbb_orders} WHERE id = %d", $order->id ) ) );
		$this->assertSame( OPENBB_Order::STATUS_SUBMITTED, $order->status );
		$this->assertSame( 1250, $order->membership_fee() );
		$this->assertNull( $order->paid_at );
		$this->assertNull( $order->paid_by );
		$this->assertNull( $order->paid_amount );
		$this->assertNull( $order->paid_currency );
		$this->assertFalse( $order->is_collected() );
		$this->assertStringContainsString( $reference, $order->member_note );
		$summary = OPENBB_Membership_Fees::summary( $user_id );
		$this->assertSame( 'pending', $summary['status'] );
		$this->assertSame( $order->id, $summary['pending_order_id'] );
		$this->assertSame( $reference, $summary['history'][0]['reference'] );
	}

	public function test_claim_is_bound_to_email_payload_and_created_only_after_verification(): void {
		$response = $this->start();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'code', $response->get_data()['step'] );
		$this->assertFalse( email_exists( $this->account()['email'] ) );
		$this->assertSame( array(), $this->order_ids() );
		$payload = OPENBB_REST_Signup::pending()['payload'];
		$this->assertTrue( $payload['membership_fee_claim'] );
		$this->assertSame( 'Transfer ABC-123', $payload['membership_fee_reference'] );

		$response = $this->post(
			'/verify',
			array(
				'code'                     => $this->code(),
				'membership_fee_claim'     => false,
				'membership_fee_reference' => 'Unbound replacement',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$user_id = (int) email_exists( $this->account()['email'] );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, OPENBB_Access::get_status( $user_id ) );
		$this->assert_claim( $user_id );
		wp_set_current_user( 0 );
		$this->assertSame( 410, $this->post( '/verify', array( 'code' => '123456' ) )->get_status() );
		$this->assertCount( 1, $this->order_ids() );
	}

	public function test_invalid_code_creates_neither_membership_nor_claim(): void {
		$this->start();
		$wrong = '000000' === $this->code() ? '111111' : '000000';
		$response = $this->post( '/verify', array( 'code' => $wrong ) );
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertFalse( email_exists( $this->account()['email'] ) );
		$this->assertSame( array(), $this->order_ids() );
	}

	public function test_verify_cannot_add_a_claim_not_in_the_verified_payload(): void {
		$this->start( false );
		$response = $this->post( '/verify', array( 'code' => $this->code(), 'membership_fee_claim' => true ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->order_ids() );
	}

	public function test_existing_register_and_join_arguments_do_not_create_claims(): void {
		$registered = OPENBB_Signup::register( $this->account(), array(), $this->rules );
		$this->assertNotWPError( $registered );
		$user_id = self::factory()->user->create();
		$joined = OPENBB_Signup::join( $user_id, $this->account(), array(), $this->rules );
		$this->assertNotWPError( $joined );
		$this->assertSame( array(), $this->order_ids() );
	}

	public function test_claim_never_bypasses_membership_approval(): void {
		$result = OPENBB_Signup::register( $this->account(), array(), $this->rules, true, 'Transfer ABC-123' );
		$this->assertNotWPError( $result );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, $result['status'] );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, OPENBB_Access::get_status( $result['user_id'] ) );
		$this->assertFalse( user_can( $result['user_id'], 'openbb_place_orders' ) );
		$this->assert_claim( $result['user_id'] );
	}

	public function test_disabling_membership_approval_does_not_approve_the_fee_payment(): void {
		$this->settings( array( 'require_member_approval' => false ) );
		$result = OPENBB_Signup::register( $this->account(), array(), $this->rules, true, 'Transfer ABC-123' );
		$this->assertNotWPError( $result );
		$this->assertSame( OPENBB_Access::STATUS_ACTIVE, $result['status'] );
		$this->assert_claim( $result['user_id'] );
	}

	public function test_logged_in_join_submits_an_unpaid_claim_directly(): void {
		$user_id = self::factory()->user->create( array( 'first_name' => 'Janet' ) );
		wp_set_current_user( $user_id );
		$response = $this->start();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'done', $response->get_data()['step'] );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, OPENBB_Access::get_status( $user_id ) );
		$this->assertSame( 'Janet', get_userdata( $user_id )->first_name );
		$this->assertNull( OPENBB_REST_Signup::pending() );
		$this->assert_claim( $user_id );
	}

	public function test_fee_removed_before_verification_rolls_back_the_new_user(): void {
		$this->start();
		$code = $this->code();
		$this->settings( array( 'membership_fee_amount' => 0 ) );
		$response = $this->post( '/verify', array( 'code' => $code ) );
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertFalse( email_exists( $this->account()['email'] ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( array(), $this->order_ids() );
		$this->assertCount( 1, $this->mail );
	}

	public function test_failed_claim_restores_existing_user_and_membership_fields(): void {
		$user_id = self::factory()->user->create( array( 'first_name' => '', 'last_name' => '' ) );
		$before = get_userdata( $user_id );
		$schema = OPENBB_Profile_Fields::save_schema(
			array( array( 'key' => 'household', 'label' => array( 'default' => 'Household' ), 'type' => 'text' ) ),
			OPENBB_Profile_Fields::get_schema()['revision']
		);
		$this->assertNotWPError( $schema );
		$this->settings( array( 'membership_fee_amount' => 0, 'require_member_approval' => false ) );

		$result = OPENBB_Signup::join( $user_id, $this->account(), array( 'household' => 'Three' ), $this->rules, true );
		$this->assertWPError( $result );
		$after = get_userdata( $user_id );
		$this->assertSame( $before->user_email, $after->user_email );
		$this->assertSame( $before->display_name, $after->display_name );
		$this->assertSame( $before->roles, $after->roles );
		$this->assertSame( '', $after->first_name );
		$this->assertSame( '', $after->last_name );
		foreach ( array( 'status', 'joined_at', 'phone', 'rules_version', 'rules_accepted_at' ) as $field ) {
			$this->assertSame( '', openbb_get_member_field( $user_id, $field ), $field );
		}
		$this->assertSame( '', get_user_meta( $user_id, OPENBB_Profile_Fields::meta_key( 'household' ), true ) );
		$this->assertSame( array(), $this->order_ids() );
		$this->assertSame( array(), $this->mail );
	}

	public function test_failed_membership_save_cannot_create_a_claim(): void {
		$fail = static function ( $check, $object_id, $meta_key ) {
			return openbb_member_meta_key( 'status' ) === $meta_key ? false : $check;
		};
		add_filter( 'add_user_metadata', $fail, 10, 3 );
		add_filter( 'update_user_metadata', $fail, 10, 3 );
		$result = OPENBB_Signup::register( $this->account(), array(), $this->rules, true );
		remove_filter( 'add_user_metadata', $fail );
		remove_filter( 'update_user_metadata', $fail );
		$this->assertWPError( $result );
		$this->assertFalse( email_exists( $this->account()['email'] ) );
		$this->assertSame( array(), $this->order_ids() );
	}

	public function test_failed_claim_event_leaves_no_user_membership_or_orphan_orders(): void {
		global $wpdb;
		$user_id = self::factory()->user->create();
		$attempts = 0;
		$fail = static function ( string $query ) use ( $wpdb, &$attempts ): string {
			if ( 0 === strpos( $query, "INSERT INTO `{$wpdb->openbb_order_events}`" ) ) {
				++$attempts;
				return 'INVALID OPENBB SIGNUP CLAIM EVENT';
			}
			return $query;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$registered = OPENBB_Signup::register( $this->account(), array(), $this->rules, true );
			$joined = OPENBB_Signup::join( $user_id, $this->account(), array(), $this->rules, true );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertWPError( $registered );
		$this->assertWPError( $joined );
		$this->assertSame( 2, $attempts );
		$this->assertFalse( email_exists( $this->account()['email'] ) );
		$this->assertNotFalse( get_userdata( $user_id ) );
		$this->assertSame( '', OPENBB_Access::get_status( $user_id ) );
		$this->assertSame( array(), $this->order_ids() );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_order_lines}" ) );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_order_events}" ) );
		$this->assertSame( array(), $this->mail );
	}

	public function test_claim_ui_is_available_only_when_the_fee_is_positive(): void {
		$html = OPENBB_Signup_View::render();
		$this->assertStringContainsString( 'name="membership_fee_claim"', $html );
		$this->assertStringContainsString( 'name="membership_fee_reference"', $html );
		$this->assertStringContainsString( 'Staff always review this payment claim separately from membership approval.', $html );
		$this->settings( array( 'membership_fee_amount' => 0 ) );
		$html = OPENBB_Signup_View::render();
		$this->assertStringNotContainsString( 'name="membership_fee_claim"', $html );
		$this->assertStringNotContainsString( 'name="membership_fee_reference"', $html );
	}

	public function test_fee_notice_precedes_optional_claim_and_reference_starts_hidden(): void {
		$html = OPENBB_Signup_View::render();
		$this->assertStringContainsString( 'charged on your first order.', $html );
		$this->assertLessThan( strpos( $html, 'name="membership_fee_claim"' ), strpos( $html, 'openbb-signup__fee"' ) );
		$this->assertStringContainsString( 'I already paid the membership fee (optional).', $html );
		$document = new DOMDocument();
		@$document->loadHTML( $html );
		$xpath = new DOMXPath( $document );
		$this->assertSame( 0, $xpath->query( '//input[@name="membership_fee_claim"][@required or @checked]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@name="membership_fee_reference"]/ancestor::div[@data-wp-bind--hidden="openbb/forms::!context.values.membership_fee_claim"][@hidden]' )->length );
	}

	public function test_saved_claim_reveals_reference_when_returning_to_the_form(): void {
		$this->start();
		$html = OPENBB_Signup_View::render();
		$document = new DOMDocument();
		@$document->loadHTML( $html );
		$xpath = new DOMXPath( $document );
		$this->assertSame( 1, $xpath->query( '//input[@name="membership_fee_claim"][@checked]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@name="membership_fee_reference"][@value="Transfer ABC-123"]/ancestor::div[@data-wp-bind--hidden="openbb/forms::!context.values.membership_fee_claim"][not(@hidden)]' )->length );
	}

	public function test_recurring_fee_notice_explains_first_order_and_interval(): void {
		$this->settings( array( 'membership_fee_type' => 'recurring', 'membership_fee_order_interval' => 1 ) );
		$this->assertStringContainsString( 'charged on your first order and each order after that.', OPENBB_Signup_View::render() );
		$this->settings( array( 'membership_fee_order_interval' => 3 ) );
		$this->assertStringContainsString( 'charged on your first order and every 3 orders after that.', OPENBB_Signup_View::render() );
	}
}
