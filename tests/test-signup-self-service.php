<?php
/**
 * Self-signup from the [openbb_signup] form: the model and the visitor REST routes.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Signup_Self_Service extends OPENBB_TestCase {

	/** @var array[] Mail sent during the test: to, subject, message. */
	private $mail = array();

	/** @var int Published rules version. */
	private $rules = 0;

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		$this->mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		add_filter( 'send_auth_cookies', '__return_false' );
		add_filter( 'openbb_rate_limit', '__return_zero' );
		unset( $_COOKIE[ OPENBB_REST_Signup::COOKIE ] );
		wp_set_current_user( 0 );

		$this->set_general(
			array(
				'allow_signups'           => true,
				'require_member_approval' => true,
			)
		);

		OPENBB_Rules::save_draft( array( array( 'default' => '<p>Be kind.</p>' ) ) );
		$this->rules = OPENBB_Rules::publish( true, 1 )->id;
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		remove_filter( 'send_auth_cookies', '__return_false' );
		remove_filter( 'openbb_rate_limit', '__return_zero' );
		unset( $_COOKIE[ OPENBB_REST_Signup::COOKIE ] );
		parent::tear_down();
	}

	public function capture_mail( $result, array $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	private function set_general( array $values ): void {
		update_option( OPENBB_Settings::GENERAL_OPTION, array_merge( OPENBB_Settings::get_general(), $values ), false );
	}

	private function account( array $overrides = array() ): array {
		return array_merge(
			array(
				'email'      => 'jane.doe@example.com',
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'phone'      => '555-0100',
			),
			$overrides
		);
	}

	private function add_profile_field( bool $required = false ): void {
		$schema = OPENBB_Profile_Fields::save_schema(
			array(
				array(
					'key'      => 'household',
					'label'    => array( 'default' => 'Household' ),
					'type'     => 'text',
					'required' => $required,
				),
			),
			OPENBB_Profile_Fields::get_schema()['revision']
		);
		$this->assertNotWPError( $schema );
	}

	private function post( string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/openbb/v1/signup' . $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );

		return rest_do_request( $request );
	}

	private function start( array $overrides = array() ): WP_REST_Response {
		return $this->post(
			'',
			array_merge(
				array(
					'account'       => $this->account(),
					'rules_version' => $this->rules,
				),
				$overrides
			)
		);
	}

	private function last_code(): string {
		$this->assertNotEmpty( $this->mail );
		$this->assertSame( 1, preg_match( '/font-family: monospace[^>]*>(\d{6})<\/p>/', end( $this->mail )['message'], $match ) );

		return $match[1];
	}

	// ------------------------------------------------------------------
	// Model.
	// ------------------------------------------------------------------

	public function test_register_creates_an_applicant_with_rules_and_profile(): void {
		$this->add_profile_field();

		$result = OPENBB_Signup::register( $this->account( array( 'language' => 'en' ) ), array( 'household' => 'Three' ), $this->rules );

		$this->assertIsArray( $result );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, $result['status'] );
		$user = get_userdata( $result['user_id'] );
		$this->assertSame( 'janedoe', $user->user_login );
		$this->assertSame( 'Jane', $user->first_name );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, OPENBB_Access::get_status( $user->ID ) );
		$this->assertSame( '555-0100', openbb_get_member_field( $user->ID, 'phone' ) );
		$this->assertSame( 'en', openbb_get_member_field( $user->ID, 'language' ) );
		$this->assertSame( $this->rules, (int) openbb_get_member_field( $user->ID, 'rules_version' ) );
		$this->assertNotSame( '', (string) openbb_get_member_field( $user->ID, 'rules_accepted_at' ) );
		$this->assertSame( 'Three', OPENBB_Profile_Fields::get_values( $user->ID, OPENBB_Profile_Fields::CONTEXT_SIGNUP )['household'] );
		$this->assertSame( array( 'jane.doe@example.com' ), wp_list_pluck( $this->mail, 'to' ) );
		$this->assertStringContainsString( 'We received your membership application', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'review your application', $this->mail[0]['message'] );
		$this->assertStringNotContainsString( 'Welcome to the co-op', $this->mail[0]['subject'] );
	}

	public function test_register_gives_member_when_approval_is_off(): void {
		$this->set_general( array( 'require_member_approval' => false ) );

		$result = OPENBB_Signup::register( $this->account(), array(), $this->rules );

		$this->assertSame( OPENBB_Access::STATUS_ACTIVE, $result['status'] );
		$this->assertSame( OPENBB_Access::STATUS_ACTIVE, OPENBB_Access::get_status( $result['user_id'] ) );
		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'Welcome to the co-op', $this->mail[0]['subject'] );
		$this->assertStringNotContainsString( 'review your application', $this->mail[0]['message'] );
	}

	public function test_register_refuses_when_signups_are_closed(): void {
		$this->set_general( array( 'allow_signups' => false ) );

		$this->assertWPError( OPENBB_Signup::register( $this->account(), array(), $this->rules ) );
		$this->assertFalse( email_exists( 'jane.doe@example.com' ) );
	}

	public function test_register_refuses_an_existing_email(): void {
		self::factory()->user->create( array( 'user_email' => 'jane.doe@example.com' ) );

		$result = OPENBB_Signup::register( $this->account(), array(), $this->rules );

		$this->assertSame( 'openbb_email_exists', $result->get_error_code() );
	}

	public function test_validate_signup_requires_the_latest_rules(): void {
		$errors = OPENBB_Signup::validate_signup( $this->account(), array(), 0 )->get_error_data()['errors'];
		$this->assertArrayHasKey( OPENBB_Signup::RULES_FIELD, $errors );

		OPENBB_Rules::save_draft( array( array( 'default' => '<p>Be kinder.</p>' ) ) );
		OPENBB_Rules::publish( true, 1 );
		$errors = OPENBB_Signup::validate_signup( $this->account(), array(), $this->rules )->get_error_data()['errors'];
		$this->assertArrayHasKey( OPENBB_Signup::RULES_FIELD, $errors );
	}

	public function test_validate_signup_reports_every_field_at_once(): void {
		$this->add_profile_field( true );

		$result = OPENBB_Signup::validate_signup( array( 'email' => 'nope' ), array(), 0 );

		$errors = $result->get_error_data()['errors'];
		$this->assertArrayHasKey( 'email', $errors );
		$this->assertArrayHasKey( 'first_name', $errors );
		$this->assertArrayHasKey( 'household', $errors );
		$this->assertArrayHasKey( OPENBB_Signup::RULES_FIELD, $errors );
	}

	public function test_join_keeps_existing_values_and_fills_empty_ones(): void {
		$this->add_profile_field();
		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'old@example.com',
				'first_name' => 'Janet',
				'last_name'  => '',
			)
		);

		$result = OPENBB_Signup::join(
			$user_id,
			$this->account(),
			array( 'household' => 'Two' ),
			$this->rules
		);

		$this->assertIsArray( $result );
		$user = get_userdata( $user_id );
		$this->assertSame( 'old@example.com', $user->user_email );
		$this->assertSame( 'Janet', $user->first_name );
		$this->assertSame( 'Doe', $user->last_name );
		$this->assertSame( '555-0100', openbb_get_member_field( $user_id, 'phone' ) );
		$this->assertSame( 'Two', OPENBB_Profile_Fields::get_values( $user_id, OPENBB_Profile_Fields::CONTEXT_SIGNUP )['household'] );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, OPENBB_Access::get_status( $user_id ) );
		$this->assertSame( $this->rules, (int) openbb_get_member_field( $user_id, 'rules_version' ) );
	}

	public function test_join_locks_existing_profile_values(): void {
		$this->add_profile_field();
		$user_id = self::factory()->user->create( array( 'first_name' => 'Janet' ) );
		OPENBB_Profile_Fields::set_values( $user_id, array( 'household' => 'One' ), OPENBB_Profile_Fields::CONTEXT_STAFF );

		OPENBB_Signup::join( $user_id, $this->account(), array( 'household' => 'Ten' ), $this->rules );

		$this->assertSame( 'One', OPENBB_Profile_Fields::get_values( $user_id, OPENBB_Profile_Fields::CONTEXT_SIGNUP )['household'] );
	}

	public function test_join_refuses_existing_members(): void {
		$user_id = $this->make_user( 'inactive' );

		$result = OPENBB_Signup::join( $user_id, $this->account(), array(), $this->rules );

		$this->assertSame( 'openbb_already_joined', $result->get_error_code() );
		$this->assertSame( 'inactive', OPENBB_Access::get_status( $user_id ) );
	}

	public function test_staff_create_still_refuses_the_signup_context(): void {
		$result = OPENBB_Signup::create_member( $this->account(), array(), OPENBB_Profile_Fields::CONTEXT_SIGNUP );

		$this->assertWPError( $result );
		$this->assertFalse( email_exists( 'jane.doe@example.com' ) );
	}

	// ------------------------------------------------------------------
	// REST.
	// ------------------------------------------------------------------

	public function test_routes_are_closed_when_signups_are_off(): void {
		$this->set_general( array( 'allow_signups' => false ) );

		foreach ( array( '', '/resend' ) as $route ) {
			$response = $this->post( $route );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'openbb_signup_closed', $response->get_data()['code'] );
		}
		$this->assertSame( 403, $this->post( '/verify', array( 'code' => '123456' ) )->get_status() );
	}

	public function test_visitor_flow_creates_the_account_only_after_the_code(): void {
		$response = $this->start();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'code', $response->get_data()['step'] );
		$this->assertSame( 'jane.doe@example.com', $response->get_data()['email'] );
		$this->assertStringContainsString( 'Content-Type: text/html; charset=UTF-8', $this->mail[0]['headers'] );
		$this->assertFalse( email_exists( 'jane.doe@example.com' ) );
		$this->assertNotEmpty( $_COOKIE[ OPENBB_REST_Signup::COOKIE ] );
		$this->assertSame( 'jane.doe@example.com', OPENBB_REST_Signup::pending()['email'] );

		$wrong = $this->post( '/verify', array( 'code' => '000000' === $this->last_code() ? '111111' : '000000' ) );
		$this->assertSame( 400, $wrong->get_status() );
		$this->assertSame( OPENBB_Email_Code::MAX_ATTEMPTS - 1, $wrong->get_data()['data']['remaining'] );

		$verified = $this->post( '/verify', array( 'code' => $this->last_code() ) );

		$this->assertSame( 200, $verified->get_status() );
		$this->assertSame( 'done', $verified->get_data()['step'] );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, $verified->get_data()['status'] );
		$this->assertNotEmpty( $verified->get_data()['nonce'] );
		$user_id = email_exists( 'jane.doe@example.com' );
		$this->assertNotFalse( $user_id );
		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertArrayNotHasKey( OPENBB_REST_Signup::COOKIE, $_COOKIE );
		$this->assertNull( OPENBB_REST_Signup::pending() );
	}

	public function test_invalid_form_returns_field_errors_and_sends_nothing(): void {
		$response = $this->start( array( 'account' => array( 'email' => 'nope' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'email', $response->get_data()['data']['errors'] );
		$this->assertSame( array(), $this->mail );
	}

	public function test_existing_email_gets_the_same_response_but_a_login_email(): void {
		self::factory()->user->create( array( 'user_email' => 'jane.doe@example.com' ) );

		$response = $this->start();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'code', $response->get_data()['step'] );
		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( wp_login_url(), $this->mail[0]['message'] );
		$this->assertSame( 0, preg_match( '/font-family: monospace[^>]*>\d{6}<\/p>/', $this->mail[0]['message'] ) );
		$this->assertFalse( OPENBB_REST_Signup::pending()['deliverable'] );
	}

	public function test_honeypot_gets_a_normal_response_and_nothing_is_sent(): void {
		$response = $this->start( array( OPENBB_REST_Signup::HONEYPOT => 'https://spam.example' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'code', $response->get_data()['step'] );
		$this->assertSame( array(), $this->mail );
		$this->assertNull( OPENBB_REST_Signup::pending() );
	}

	public function test_resend_respects_the_cooldown(): void {
		$this->start();

		$response = $this->post( '/resend' );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'openbb_code_cooldown', $response->get_data()['code'] );
		$this->assertCount( 1, $this->mail );
	}

	public function test_verify_without_a_pending_code_has_expired(): void {
		$response = $this->post( '/verify', array( 'code' => '123456' ) );

		$this->assertSame( 410, $response->get_status() );
	}

	public function test_start_is_rate_limited(): void {
		remove_filter( 'openbb_rate_limit', '__return_zero' );
		$limit = static function ( int $limit, string $bucket ): int {
			return 'signup_start' === $bucket ? 1 : $limit;
		};
		$ip    = 'rate-limit-test-' . uniqid();
		$fixed = static function () use ( $ip ): string {
			return $ip;
		};
		add_filter( 'openbb_rate_limit', $limit, 10, 2 );
		add_filter( 'openbb_client_ip', $fixed );

		$first  = $this->start( array( 'account' => array( 'email' => 'nope' ) ) );
		$second = $this->start();

		remove_filter( 'openbb_rate_limit', $limit );
		remove_filter( 'openbb_client_ip', $fixed );
		$this->assertSame( 400, $first->get_status() );
		$this->assertSame( 429, $second->get_status() );
		$this->assertSame( 'openbb_rate_limited', $second->get_data()['code'] );
		$this->assertSame( array(), $this->mail );
	}

	public function test_failed_mail_discards_the_code(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		add_filter( 'pre_wp_mail', '__return_false' );

		$response = $this->start();

		remove_filter( 'pre_wp_mail', '__return_false' );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'openbb_mail_failed', $response->get_data()['code'] );
		$this->assertNull( OPENBB_REST_Signup::pending() );
	}

	public function test_signed_in_user_without_a_role_joins_directly(): void {
		$user_id = self::factory()->user->create( array( 'first_name' => 'Janet' ) );
		wp_set_current_user( $user_id );

		$response = $this->start();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'done', $response->get_data()['step'] );
		$this->assertSame( OPENBB_Access::STATUS_APPLICANT, OPENBB_Access::get_status( $user_id ) );
		$this->assertSame( 'Janet', get_userdata( $user_id )->first_name );
	}

	public function test_signed_in_user_cannot_verify_a_code(): void {
		$this->start();
		wp_set_current_user( self::factory()->user->create() );

		$response = $this->post( '/verify', array( 'code' => $this->last_code() ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertFalse( email_exists( 'jane.doe@example.com' ) );
	}
}
