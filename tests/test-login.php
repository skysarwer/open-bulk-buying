<?php
/**
 * Password and browser-bound email-code login routes and views.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Login extends OPENBB_TestCase {

	private $mail = array();
	private $user_id = 0;

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		$this->mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		add_filter( 'send_auth_cookies', '__return_false' );
		add_filter( 'openbb_rate_limit', '__return_zero' );
		unset( $_COOKIE[ OPENBB_REST_Login::COOKIE ], $_COOKIE[ OPENBB_REST_Signup::COOKIE ] );
		wp_set_current_user( 0 );
		$this->user_id = self::factory()->user->create(
			array( 'user_login' => 'jane', 'user_email' => 'jane@example.com', 'user_pass' => 'correct horse' )
		);
		$this->settings( array( 'allow_signups' => true, 'allow_login_codes' => true ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		remove_filter( 'send_auth_cookies', '__return_false' );
		remove_filter( 'openbb_rate_limit', '__return_zero' );
		unset( $_COOKIE[ OPENBB_REST_Login::COOKIE ], $_COOKIE[ OPENBB_REST_Signup::COOKIE ] );
		parent::tear_down();
	}

	public function capture_mail( $result, array $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	private function settings( array $changes ): void {
		update_option( OPENBB_Settings::GENERAL_OPTION, array_merge( OPENBB_Settings::get_general(), $changes ), false );
	}

	private function post( string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/openbb/v1/login' . $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		return rest_do_request( $request );
	}

	private function request_code( string $login = 'jane@example.com', string $redirect = '' ): WP_REST_Response {
		return $this->post( '/code', array( 'login' => $login, 'redirect_to' => $redirect ?: home_url( '/shop/' ), 'page' => home_url( '/login/' ) ) );
	}

	private function last_code(): string {
		$this->assertNotEmpty( $this->mail );
		$this->assertSame( 1, preg_match( '/font-family: monospace[^>]*>([0-9]{6})<\/p>/', end( $this->mail )['message'], $match ) );
		return $match[1];
	}

	private function age_challenge( int $expires_at = 0 ): void {
		$key = 'openbb_code_' . hash( 'sha256', $_COOKIE[ OPENBB_REST_Login::COOKIE ] );
		$challenge = get_transient( $key );
		$challenge['sent_at'] = time() - OPENBB_Email_Code::RESEND_COOLDOWN - 1;
		if ( $expires_at ) {
			$challenge['expires_at'] = $expires_at;
		}
		set_transient( $key, $challenge, OPENBB_Email_Code::TTL );
		delete_transient( 'openbb_login_code_user_' . $challenge['payload']['user_id'] );
	}

	private function panel_tag( string $html, string $name ): string {
		return preg_match( '/<div[^>]*data-panel="' . $name . '"[^>]*>/', $html, $match ) ? $match[0] : '';
	}

	public function test_password_login_logs_in_and_returns_the_redirect(): void {
		$response = $this->post( '', array( 'login' => 'jane@example.com', 'password' => 'correct horse', 'redirect_to' => home_url( '/shop/' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( home_url( '/shop/' ), $response->get_data()['redirect'] );
		$this->assertSame( $this->user_id, get_current_user_id() );
	}

	public function test_password_login_accepts_a_username(): void {
		$this->assertSame( 200, $this->post( '', array( 'login' => 'jane', 'password' => 'correct horse' ) )->get_status() );
		$this->assertSame( $this->user_id, get_current_user_id() );
	}

	public function test_off_site_redirect_falls_back_to_the_home_page(): void {
		$response = $this->post( '', array( 'login' => 'jane', 'password' => 'correct horse', 'redirect_to' => 'https://evil.example/' ) );
		$this->assertSame( home_url( '/' ), $response->get_data()['redirect'] );
	}

	public function test_wrong_password_and_unknown_user_get_the_same_error(): void {
		$wrong = $this->post( '', array( 'login' => 'jane@example.com', 'password' => 'nope' ) );
		$unknown = $this->post( '', array( 'login' => 'nobody@example.com', 'password' => 'nope' ) );
		$this->assertSame( 401, $wrong->get_status() );
		$this->assertSame( $wrong->get_data(), $unknown->get_data() );
		$this->assertSame( 'openbb_login_failed', $wrong->get_data()['code'] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_missing_fields_return_field_errors(): void {
		$response = $this->post( '' );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'login', 'password' ), array_keys( $response->get_data()['data']['errors'] ) );
		$this->assertSame( 400, $this->post( '/code' )->get_status() );
	}

	public function test_signed_in_users_cannot_log_in_again(): void {
		wp_set_current_user( $this->user_id );
		$this->assertSame( 409, $this->post( '', array( 'login' => 'jane', 'password' => 'correct horse' ) )->get_status() );
		$this->assertSame( 409, $this->request_code()->get_status() );
		$this->assertSame( 409, $this->post( '/code/resend' )->get_status() );
		$this->assertSame( 409, $this->post( '/code/verify', array( 'code' => '123456' ) )->get_status() );
	}

	public function test_core_login_redirect_filter_applies(): void {
		$filter = static function () { return home_url( '/account/' ); };
		add_filter( 'login_redirect', $filter );
		$response = $this->post( '', array( 'login' => 'jane', 'password' => 'correct horse' ) );
		remove_filter( 'login_redirect', $filter );
		$this->assertSame( home_url( '/account/' ), $response->get_data()['redirect'] );
	}

	public function test_code_flow_logs_in_once_and_fires_wp_login(): void {
		$response = $this->request_code();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'code', $response->get_data()['step'] );
		$this->assertSame( array( 'jane@example.com' ), wp_list_pluck( $this->mail, 'to' ) );
		$this->assertStringContainsString( 'Content-Type: text/html; charset=UTF-8', $this->mail[0]['headers'] );
		$code = $this->last_code();
		$token = $_COOKIE[ OPENBB_REST_Login::COOKIE ];
		$this->assertSame( 0, get_current_user_id() );
		$before = did_action( 'wp_login' );
		$verified = $this->post( '/code/verify', array( 'code' => $code ) );
		$this->assertSame( 200, $verified->get_status() );
		$this->assertSame( home_url( '/shop/' ), $verified->get_data()['redirect'] );
		$this->assertSame( $this->user_id, get_current_user_id() );
		$this->assertSame( $before + 1, did_action( 'wp_login' ) );
		$this->assertArrayNotHasKey( OPENBB_REST_Login::COOKIE, $_COOKIE );
		wp_set_current_user( 0 );
		$_COOKIE[ OPENBB_REST_Login::COOKIE ] = $token;
		$this->assertSame( 410, $this->post( '/code/verify', array( 'code' => $code ) )->get_status() );
	}

	public function test_code_by_username(): void {
		$this->request_code( 'jane' );
		$this->assertSame( array( 'jane@example.com' ), wp_list_pluck( $this->mail, 'to' ) );
		$this->assertSame( 200, $this->post( '/code/verify', array( 'code' => $this->last_code() ) )->get_status() );
	}

	public function test_unknown_account_gets_the_same_response_and_no_email(): void {
		$known = $this->request_code();
		$unknown = $this->request_code( 'nobody@example.com' );
		$this->assertSame( 200, $unknown->get_status() );
		$this->assertSame( $known->get_data(), $unknown->get_data() );
		$this->assertCount( 1, $this->mail );
		$this->assertSame( 400, $this->post( '/code/verify', array( 'code' => $this->last_code() ) )->get_status() );
	}

	public function test_code_requires_the_requesting_browser_and_purpose(): void {
		$this->request_code();
		$code = $this->last_code();
		$token = $_COOKIE[ OPENBB_REST_Login::COOKIE ];
		unset( $_COOKIE[ OPENBB_REST_Login::COOKIE ] );
		$this->assertSame( 410, $this->post( '/code/verify', array( 'code' => $code ) )->get_status() );
		$signup = OPENBB_Email_Code::issue( OPENBB_Email_Code::PURPOSE_SIGNUP, 'jane@example.com' );
		$_COOKIE[ OPENBB_REST_Login::COOKIE ] = $signup['token'];
		$this->assertSame( 410, $this->post( '/code/verify', array( 'code' => $signup['code'] ) )->get_status() );
		$_COOKIE[ OPENBB_REST_Login::COOKIE ] = $token;
		$this->assertSame( 200, $this->post( '/code/verify', array( 'code' => $code ) )->get_status() );
	}

	public function test_repeated_request_does_not_reset_attempts_or_replace_code(): void {
		$this->request_code();
		$code = $this->last_code();
		$token = $_COOKIE[ OPENBB_REST_Login::COOKIE ];
		$this->assertSame( 400, $this->post( '/code/verify', array( 'code' => 'wrong' ) )->get_status() );
		$this->request_code();
		$this->assertCount( 1, $this->mail );
		$this->assertSame( $token, $_COOKIE[ OPENBB_REST_Login::COOKIE ] );
		$pending = get_transient( 'openbb_code_' . hash( 'sha256', $token ) );
		$this->assertSame( 1, $pending['attempts'] );
		$this->assertSame( 200, $this->post( '/code/verify', array( 'code' => $code ) )->get_status() );
	}

	public function test_resend_cooldown_replacement_and_original_expiry(): void {
		$this->request_code();
		$old = $this->last_code();
		$this->assertSame( 429, $this->post( '/code/resend' )->get_status() );
		$expiry = time() + 300;
		$this->age_challenge( $expiry );
		$this->assertSame( 200, $this->post( '/code/resend' )->get_status() );
		$new = $this->last_code();
		$pending = get_transient( 'openbb_code_' . hash( 'sha256', $_COOKIE[ OPENBB_REST_Login::COOKIE ] ) );
		$this->assertSame( $expiry, $pending['expires_at'] );
		if ( $old !== $new ) {
			$this->assertSame( 400, $this->post( '/code/verify', array( 'code' => $old ) )->get_status() );
		}
		$this->assertSame( 200, $this->post( '/code/verify', array( 'code' => $new ) )->get_status() );
	}

	public function test_account_cooldown_applies_across_browsers_and_resend_recovers(): void {
		$this->request_code();
		$first = $_COOKIE[ OPENBB_REST_Login::COOKIE ];
		unset( $_COOKIE[ OPENBB_REST_Login::COOKIE ] );
		$this->request_code();
		$this->assertCount( 1, $this->mail );
		$this->assertNotSame( $first, $_COOKIE[ OPENBB_REST_Login::COOKIE ] );
		$this->age_challenge();
		$this->assertSame( 200, $this->post( '/code/resend' )->get_status() );
		$this->assertCount( 2, $this->mail );
		$this->assertSame( 200, $this->post( '/code/verify', array( 'code' => $this->last_code() ) )->get_status() );
	}

	public function test_expiry_and_attempt_limits_are_enforced(): void {
		$this->request_code();
		for ( $i = 1; $i <= OPENBB_Email_Code::MAX_ATTEMPTS; ++$i ) {
			$response = $this->post( '/code/verify', array( 'code' => 'wrong' ) );
		}
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 429, $this->post( '/code/verify', array( 'code' => $this->last_code() ) )->get_status() );
		$this->age_challenge( time() - 1 );
		$this->assertSame( 410, $this->post( '/code/verify', array( 'code' => $this->last_code() ) )->get_status() );
	}

	/** @dataProvider data_privileged_roles */
	public function test_privileged_users_are_told_to_use_their_password( string $role ): void {
		$user = self::factory()->user->create( array( 'role' => $role, 'user_email' => $role . '@example.com' ) );
		$response = $this->request_code( $role . '@example.com' );
		$this->request_code( $role . '@example.com' );
		$this->assertFalse( OPENBB_Login_Code::allowed( $user ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'password', $this->mail[0]['subject'] );
		$this->assertStringContainsString( home_url( '/login/' ), $this->mail[0]['message'] );
		$this->assertStringNotContainsString( 'Your login code:', $this->mail[0]['subject'] );
	}

	public function data_privileged_roles(): array {
		return array( 'administrator' => array( 'administrator' ), 'editor' => array( 'editor' ) );
	}

	public function test_eligibility_is_rechecked_on_verification(): void {
		$this->request_code();
		$code = $this->last_code();
		get_userdata( $this->user_id )->set_role( 'editor' );
		$this->assertSame( 403, $this->post( '/code/verify', array( 'code' => $code ) )->get_status() );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_changed_email_cannot_use_existing_code(): void {
		$this->request_code();
		$code = $this->last_code();
		wp_update_user( array( 'ID' => $this->user_id, 'user_email' => 'changed@example.com' ) );
		$this->assertSame( 403, $this->post( '/code/verify', array( 'code' => $code ) )->get_status() );
	}

	public function test_codes_can_be_turned_off_per_user(): void {
		add_filter( 'openbb_login_code_allowed', '__return_false' );
		$response = $this->request_code();
		remove_filter( 'openbb_login_code_allowed', '__return_false' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'password', $this->mail[0]['subject'] );
	}

	public function test_legacy_account_restrictions_still_apply_to_existing_codes(): void {
		$this->request_code();
		$code = $this->last_code();
		add_filter( 'openbb_login_link_allowed', '__return_false' );
		$response = $this->post( '/code/verify', array( 'code' => $code ) );
		remove_filter( 'openbb_login_link_allowed', '__return_false' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_authors_and_subscribers_may_use_codes(): void {
		$this->assertTrue( OPENBB_Login_Code::allowed( self::factory()->user->create( array( 'role' => 'author' ) ) ) );
		$this->assertTrue( OPENBB_Login_Code::allowed( $this->user_id ) );
	}

	public function test_privileged_users_can_be_allowed_by_filter(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_filter( 'openbb_login_code_allowed', '__return_true' );
		$allowed = OPENBB_Login_Code::allowed( $admin );
		remove_filter( 'openbb_login_code_allowed', '__return_true' );
		$this->assertTrue( $allowed );
	}

	public function test_failed_mail_does_not_leave_a_pending_challenge(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		add_filter( 'pre_wp_mail', '__return_false' );
		$response = $this->request_code();
		remove_filter( 'pre_wp_mail', '__return_false' );
		$this->assertSame( 500, $response->get_status() );
		$this->assertNull( OPENBB_REST_Login::pending() );
		$this->assertSame( 0, OPENBB_Login_Code::cooldown( $this->user_id ) );
	}

	public function test_password_required_page_must_be_on_this_site(): void {
		get_userdata( $this->user_id )->set_role( 'editor' );
		$this->post( '/code', array( 'login' => 'jane', 'page' => 'https://evil.example/' ) );
		$this->assertStringNotContainsString( 'evil.example', $this->mail[0]['message'] );
		$this->assertStringContainsString( home_url( '/' ), $this->mail[0]['message'] );
	}

	public function test_code_redirect_is_safe_and_filterable(): void {
		$this->request_code( 'jane', 'https://evil.example/' );
		$response = $this->post( '/code/verify', array( 'code' => $this->last_code() ) );
		$this->assertSame( home_url( '/' ), $response->get_data()['redirect'] );
	}

	public function test_login_view_starts_on_login_with_signup_hidden(): void {
		$html = do_shortcode( '[openbb_login login_redirect="' . home_url( '/shop/' ) . '"]' );
		$this->assertStringNotContainsString( ' hidden', $this->panel_tag( $html, 'login' ) );
		$this->assertStringContainsString( ' hidden', $this->panel_tag( $html, 'signup' ) );
		$this->assertStringContainsString( 'openbb/auth::actions.showSignup', $html );
		$this->assertStringContainsString( 'openbb/auth::actions.showLogin', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( wp_json_encode( home_url( '/shop/' ) ), $html );
		$this->assertStringContainsString( 'autocomplete="one-time-code"', $html );
		$this->assertStringNotContainsString( 'actions.confirm', $html );
	}

	public function test_login_view_leaves_out_signup_when_closed(): void {
		$this->settings( array( 'allow_signups' => false ) );
		$html = do_shortcode( '[openbb_login]' );
		$this->assertNotSame( '', $this->panel_tag( $html, 'login' ) );
		$this->assertSame( '', $this->panel_tag( $html, 'signup' ) );
		$this->assertStringNotContainsString( 'actions.showSignup', $html );
	}

	public function test_signup_view_starts_on_signup_with_login_hidden(): void {
		$html = do_shortcode( '[openbb_signup login_redirect="' . home_url( '/shop/' ) . '"]' );
		$this->assertStringContainsString( ' hidden', $this->panel_tag( $html, 'login' ) );
		$this->assertStringNotContainsString( ' hidden', $this->panel_tag( $html, 'signup' ) );
		$this->assertStringContainsString( 'href="' . home_url( '/shop/' ) . '"', $html );
	}

	public function test_signup_view_keeps_redirect_to_as_a_fallback(): void {
		$html = do_shortcode( '[openbb_signup redirect_to="' . home_url( '/old/' ) . '"]' );
		$this->assertStringContainsString( 'href="' . home_url( '/old/' ) . '"', $html );
	}

	public function test_reload_restores_pending_code_on_login_panel_without_exposing_token(): void {
		$this->request_code();
		$html = do_shortcode( '[openbb_signup]' );
		$this->assertStringNotContainsString( ' hidden', $this->panel_tag( $html, 'login' ) );
		$this->assertStringContainsString( ' hidden', $this->panel_tag( $html, 'signup' ) );
		$this->assertStringContainsString( 'jane@example.com', $html );
		$this->assertStringContainsString( '"code":true', $html );
		$this->assertStringContainsString( 'value="jane@example.com"', $html );
		$this->assertStringNotContainsString( $_COOKIE[ OPENBB_REST_Login::COOKIE ], $html );
		$this->assertNotNull( OPENBB_REST_Login::pending() );
	}

	public function test_login_codes_are_off_by_default(): void {
		delete_option( OPENBB_Settings::GENERAL_OPTION );
		$this->assertFalse( OPENBB_Login_Code::enabled() );
	}

	public function test_turning_codes_off_hides_the_option_and_refuses_pending_codes(): void {
		$this->request_code();
		$code = $this->last_code();
		$this->settings( array( 'allow_login_codes' => false, 'allow_signups' => false ) );
		$this->assertSame( 403, $this->request_code()->get_status() );
		$this->assertSame( 403, $this->post( '/code/resend' )->get_status() );
		$this->assertSame( 403, $this->post( '/code/verify', array( 'code' => $code ) )->get_status() );
		$html = do_shortcode( '[openbb_login]' );
		$this->assertStringNotContainsString( 'actions.toggleMethod', $html );
		$this->assertStringNotContainsString( 'autocomplete="one-time-code"', $html );
		$this->assertStringContainsString( 'type="password"', $html );
	}

	public function test_old_link_routes_are_removed(): void {
		$this->assertSame( 404, $this->post( '/link' )->get_status() );
		$this->assertSame( 404, $this->post( '/link/verify', array( 'token' => 'old' ) )->get_status() );
	}

	public function test_signed_in_users_see_who_they_are(): void {
		wp_set_current_user( $this->user_id );
		$html = do_shortcode( '[openbb_login]' );
		$this->assertStringContainsString( 'jane@example.com', $html );
		$this->assertStringNotContainsString( 'data-panel', $html );
	}
}
