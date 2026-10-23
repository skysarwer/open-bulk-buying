<?php
/**
 * Login from the [openbb_login] view: password and emailed-link routes, and the views.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Login extends OPENBB_TestCase {

	/** @var array[] Mail sent during the test: to, subject, message. */
	private $mail = array();

	/** @var int */
	private $user_id = 0;

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		$this->mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		add_filter( 'send_auth_cookies', '__return_false' );
		add_filter( 'openbb_rate_limit', '__return_zero' );
		unset( $_GET[ OPENBB_Login_Link::QUERY_ARG ] );
		wp_set_current_user( 0 );

		$this->user_id = self::factory()->user->create(
			array(
				'user_login' => 'jane',
				'user_email' => 'jane@example.com',
				'user_pass'  => 'correct horse',
			)
		);
		$this->set_signups( true );
		$this->set_links( true );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		remove_filter( 'send_auth_cookies', '__return_false' );
		remove_filter( 'openbb_rate_limit', '__return_zero' );
		unset( $_GET[ OPENBB_Login_Link::QUERY_ARG ] );
		parent::tear_down();
	}

	public function capture_mail( $result, array $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	private function set_signups( bool $open ): void {
		update_option( OPENBB_Settings::GENERAL_OPTION, array_merge( OPENBB_Settings::get_general(), array( 'allow_signups' => $open ) ), false );
	}

	private function set_links( bool $on ): void {
		update_option( OPENBB_Settings::GENERAL_OPTION, array_merge( OPENBB_Settings::get_general(), array( 'allow_login_links' => $on ) ), false );
	}

	private function post( string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/openbb/v1/login' . $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );

		return rest_do_request( $request );
	}

	private function request_link( string $login = 'jane@example.com', string $redirect = '' ): WP_REST_Response {
		return $this->post(
			'/link',
			array(
				'login'       => $login,
				'redirect_to' => '' !== $redirect ? $redirect : home_url( '/shop/' ),
				'page'        => home_url( '/login/' ),
			)
		);
	}

	private function last_token(): string {
		$this->assertNotEmpty( $this->mail );
		$pattern = '#' . preg_quote( home_url( '/login/' ), '#' ) . '\?' . OPENBB_Login_Link::QUERY_ARG . '=([A-Za-z0-9]+)#';
		$this->assertSame( 1, preg_match( $pattern, end( $this->mail )['message'], $match ) );

		return $match[1];
	}

	/**
	 * The opening tag of the auth panel named $name, or '' if there is none.
	 */
	private function panel_tag( string $html, string $name ): string {
		return preg_match( '/<div[^>]*data-panel="' . $name . '"[^>]*>/', $html, $match ) ? $match[0] : '';
	}

	// ------------------------------------------------------------------
	// Password login.
	// ------------------------------------------------------------------

	public function test_password_login_logs_in_and_returns_the_redirect(): void {
		$response = $this->post(
			'',
			array(
				'login'       => 'jane@example.com',
				'password'    => 'correct horse',
				'redirect_to' => home_url( '/shop/' ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( home_url( '/shop/' ), $response->get_data()['redirect'] );
		$this->assertSame( $this->user_id, get_current_user_id() );
	}

	public function test_password_login_accepts_a_username(): void {
		$response = $this->post(
			'',
			array(
				'login'    => 'jane',
				'password' => 'correct horse',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->user_id, get_current_user_id() );
	}

	public function test_off_site_redirect_falls_back_to_the_home_page(): void {
		$response = $this->post(
			'',
			array(
				'login'       => 'jane',
				'password'    => 'correct horse',
				'redirect_to' => 'https://evil.example/',
			)
		);

		$this->assertSame( home_url( '/' ), $response->get_data()['redirect'] );
	}

	public function test_wrong_password_and_unknown_user_get_the_same_error(): void {
		$wrong   = $this->post(
			'',
			array(
				'login'    => 'jane@example.com',
				'password' => 'nope',
			)
		);
		$unknown = $this->post(
			'',
			array(
				'login'    => 'nobody@example.com',
				'password' => 'nope',
			)
		);

		foreach ( array( $wrong, $unknown ) as $response ) {
			$this->assertSame( 401, $response->get_status() );
			$this->assertSame( 'openbb_login_failed', $response->get_data()['code'] );
		}
		$this->assertSame( $wrong->get_data()['message'], $unknown->get_data()['message'] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_missing_fields_return_field_errors(): void {
		$response = $this->post( '' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'login', 'password' ), array_keys( $response->get_data()['data']['errors'] ) );
	}

	public function test_signed_in_users_cannot_log_in_again(): void {
		wp_set_current_user( $this->user_id );

		$this->assertSame( 409, $this->post( '', array( 'login' => 'jane', 'password' => 'correct horse' ) )->get_status() );
		$this->assertSame( 409, $this->request_link()->get_status() );
	}

	public function test_core_login_redirect_filter_applies(): void {
		$filter = static function () {
			return home_url( '/account/' );
		};
		add_filter( 'login_redirect', $filter );

		$response = $this->post( '', array( 'login' => 'jane', 'password' => 'correct horse' ) );

		remove_filter( 'login_redirect', $filter );
		$this->assertSame( home_url( '/account/' ), $response->get_data()['redirect'] );
	}

	// ------------------------------------------------------------------
	// Login links.
	// ------------------------------------------------------------------

	public function test_link_flow_logs_in_once(): void {
		$response = $this->request_link();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'sent', $response->get_data()['step'] );
		$this->assertSame( array( 'jane@example.com' ), wp_list_pluck( $this->mail, 'to' ) );
		$token = $this->last_token();
		$this->assertSame( $this->user_id, OPENBB_Login_Link::peek( $token )['user_id'] );
		$this->assertSame( 0, get_current_user_id() );

		$verified = $this->post( '/link/verify', array( 'token' => $token ) );

		$this->assertSame( 200, $verified->get_status() );
		$this->assertSame( home_url( '/shop/' ), $verified->get_data()['redirect'] );
		$this->assertSame( $this->user_id, get_current_user_id() );

		wp_set_current_user( 0 );
		$again = $this->post( '/link/verify', array( 'token' => $token ) );
		$this->assertSame( 410, $again->get_status() );
		$this->assertSame( 'openbb_login_link_expired', $again->get_data()['code'] );
	}

	public function test_link_by_username(): void {
		$this->request_link( 'jane' );

		$this->assertSame( array( 'jane@example.com' ), wp_list_pluck( $this->mail, 'to' ) );
	}

	public function test_unknown_account_gets_the_same_response_and_no_email(): void {
		$known   = $this->request_link();
		$unknown = $this->request_link( 'nobody@example.com' );

		$this->assertSame( 200, $unknown->get_status() );
		$this->assertSame( $known->get_data(), $unknown->get_data() );
		$this->assertCount( 1, $this->mail );
	}

	public function test_asking_again_within_the_cooldown_sends_nothing(): void {
		$this->request_link();
		$response = $this->request_link();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->mail );
	}

	public function test_a_new_link_replaces_the_old_one(): void {
		$old = OPENBB_Login_Link::issue( $this->user_id, '', home_url( '/' ) );
		$new = OPENBB_Login_Link::issue( $this->user_id, '', home_url( '/' ) );

		$this->assertNull( OPENBB_Login_Link::peek( $old['token'] ) );
		$this->assertNotNull( OPENBB_Login_Link::peek( $new['token'] ) );
	}

	public function test_expired_link_is_refused(): void {
		$issued = OPENBB_Login_Link::issue( $this->user_id, '', home_url( '/' ) );
		$key    = 'openbb_ll_' . hash( 'sha256', $issued['token'] );
		$link   = get_transient( $key );
		$link['expires_at'] = time() - 1;
		set_transient( $key, $link, 60 );

		$response = $this->post( '/link/verify', array( 'token' => $issued['token'] ) );

		$this->assertSame( 410, $response->get_status() );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_links_can_be_turned_off_per_user(): void {
		add_filter( 'openbb_login_link_allowed', '__return_false' );

		$response = $this->request_link();
		$issued   = OPENBB_Login_Link::issue( $this->user_id, '', home_url( '/' ) );
		$verify   = $this->post( '/link/verify', array( 'token' => $issued['token'] ) );

		remove_filter( 'openbb_login_link_allowed', '__return_false' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->mail );
		$this->assertStringNotContainsString( OPENBB_Login_Link::QUERY_ARG . '=', $this->mail[0]['message'] );
		$this->assertSame( 410, $verify->get_status() );
	}

	/**
	 * @dataProvider data_privileged_roles
	 */
	public function test_privileged_users_are_told_to_use_their_password( string $role ): void {
		$user = self::factory()->user->create(
			array(
				'role'       => $role,
				'user_email' => $role . '@example.com',
			)
		);

		$response = $this->request_link( $role . '@example.com' );
		$again    = $this->request_link( $role . '@example.com' );

		$this->assertFalse( OPENBB_Login_Link::allowed( $user ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->request_link()->get_data()['step'], $response->get_data()['step'] );
		$this->assertSame( 200, $again->get_status() );
		$this->assertCount( 2, $this->mail, 'One password email (cooldown applies), then the member link.' );
		$this->assertSame( $role . '@example.com', $this->mail[0]['to'] );
		$this->assertStringContainsString( 'password', $this->mail[0]['subject'] );
		$this->assertStringContainsString( home_url( '/login/' ), $this->mail[0]['message'] );
		$this->assertStringNotContainsString( OPENBB_Login_Link::QUERY_ARG . '=', $this->mail[0]['message'] );
		$this->assertStringContainsString( OPENBB_Login_Link::QUERY_ARG . '=', $this->mail[1]['message'] );
	}

	public function data_privileged_roles(): array {
		return array(
			'administrator' => array( 'administrator' ),
			'editor'        => array( 'editor' ),
		);
	}

	public function test_privileged_users_cannot_use_an_existing_link(): void {
		$issued = OPENBB_Login_Link::issue( $this->user_id, '', home_url( '/' ) );
		get_userdata( $this->user_id )->set_role( 'editor' );

		$verify = $this->post( '/link/verify', array( 'token' => $issued['token'] ) );

		$this->assertSame( 410, $verify->get_status() );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_authors_and_subscribers_may_use_links(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->assertTrue( OPENBB_Login_Link::allowed( $author ) );
		$this->assertTrue( OPENBB_Login_Link::allowed( $this->user_id ) );
	}

	public function test_privileged_users_can_be_allowed_by_filter(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_filter( 'openbb_login_link_allowed', '__return_true' );

		$allowed = OPENBB_Login_Link::allowed( $admin );

		remove_filter( 'openbb_login_link_allowed', '__return_true' );
		$this->assertTrue( $allowed );
	}

	public function test_failed_mail_revokes_the_link(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		add_filter( 'pre_wp_mail', '__return_false' );

		$response = $this->request_link();

		remove_filter( 'pre_wp_mail', '__return_false' );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 0, OPENBB_Login_Link::cooldown( $this->user_id ) );
	}

	public function test_link_page_must_be_on_this_site(): void {
		$this->post(
			'/link',
			array(
				'login' => 'jane',
				'page'  => 'https://evil.example/',
			)
		);

		$this->assertStringContainsString( home_url( '/?' . OPENBB_Login_Link::QUERY_ARG . '=' ), $this->mail[0]['message'] );
		$this->assertStringNotContainsString( 'evil.example', $this->mail[0]['message'] );
	}

	// ------------------------------------------------------------------
	// Views.
	// ------------------------------------------------------------------

	public function test_login_view_starts_on_login_with_signup_hidden(): void {
		$html = do_shortcode( '[openbb_login login_redirect="' . home_url( '/shop/' ) . '"]' );

		$this->assertStringNotContainsString( ' hidden', $this->panel_tag( $html, 'login' ) );
		$this->assertStringContainsString( ' hidden', $this->panel_tag( $html, 'signup' ) );
		$this->assertStringContainsString( 'openbb/auth::actions.showSignup', $html );
		$this->assertStringContainsString( 'openbb/auth::actions.showLogin', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( wp_json_encode( home_url( '/shop/' ) ), $html );
	}

	public function test_login_view_leaves_out_signup_when_closed(): void {
		$this->set_signups( false );

		$html = do_shortcode( '[openbb_login]' );

		$this->assertNotSame( '', $this->panel_tag( $html, 'login' ) );
		$this->assertSame( '', $this->panel_tag( $html, 'signup' ) );
		$this->assertStringNotContainsString( 'actions.showSignup', $html );
	}

	public function test_signup_view_starts_on_signup_with_login_hidden(): void {
		$html = do_shortcode( '[openbb_signup login_redirect="' . home_url( '/shop/' ) . '"]' );

		$this->assertStringContainsString( ' hidden', $this->panel_tag( $html, 'login' ) );
		$this->assertStringNotContainsString( ' hidden', $this->panel_tag( $html, 'signup' ) );
		$this->assertStringContainsString( 'openbb/auth::actions.showLogin', $html );
		$this->assertStringContainsString( 'href="' . home_url( '/shop/' ) . '"', $html );
	}

	public function test_signup_view_keeps_redirect_to_as_a_fallback(): void {
		$html = do_shortcode( '[openbb_signup redirect_to="' . home_url( '/old/' ) . '"]' );

		$this->assertStringContainsString( 'href="' . home_url( '/old/' ) . '"', $html );
	}

	public function test_a_login_link_opens_the_confirm_step_on_the_login_panel(): void {
		$issued = OPENBB_Login_Link::issue( $this->user_id, '', home_url( '/' ) );
		$_GET[ OPENBB_Login_Link::QUERY_ARG ] = $issued['token'];

		$html = do_shortcode( '[openbb_signup]' );

		$this->assertStringNotContainsString( ' hidden', $this->panel_tag( $html, 'login' ) );
		$this->assertStringContainsString( ' hidden', $this->panel_tag( $html, 'signup' ) );
		$this->assertStringContainsString( 'jane@example.com', $html );
		$this->assertNotNull( OPENBB_Login_Link::peek( $issued['token'] ), 'Showing the link must not use it.' );
	}

	public function test_login_links_are_off_by_default(): void {
		delete_option( OPENBB_Settings::GENERAL_OPTION );

		$this->assertFalse( OPENBB_Login_Link::enabled() );
	}

	public function test_turning_links_off_hides_the_option_and_refuses_links(): void {
		$issued = OPENBB_Login_Link::issue( $this->user_id, '', home_url( '/' ) );
		$this->assertStringContainsString( 'actions.toggleMethod', do_shortcode( '[openbb_login]' ) );
		$this->set_links( false );

		$send   = $this->request_link();
		$verify = $this->post( '/link/verify', array( 'token' => $issued['token'] ) );
		$_GET[ OPENBB_Login_Link::QUERY_ARG ] = $issued['token'];
		$html   = do_shortcode( '[openbb_signup]' );

		$this->assertSame( 403, $send->get_status() );
		$this->assertSame( 'openbb_login_links_disabled', $send->get_data()['code'] );
		$this->assertSame( 403, $verify->get_status() );
		$this->assertSame( array(), $this->mail );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertStringNotContainsString( 'actions.toggleMethod', $html );
		$this->assertStringNotContainsString( 'jane@example.com', $html );
		$this->assertStringContainsString( ' hidden', $this->panel_tag( $html, 'login' ), 'A link in the address bar no longer switches to login.' );
		$this->assertStringContainsString( 'type="password"', $html );
	}

	public function test_an_expired_login_link_shows_a_notice(): void {
		$_GET[ OPENBB_Login_Link::QUERY_ARG ] = str_repeat( 'a', 43 );

		$html = do_shortcode( '[openbb_login]' );

		$this->assertStringContainsString( 'This login link has expired', $html );
	}

	public function test_signed_in_users_see_who_they_are(): void {
		wp_set_current_user( $this->user_id );

		$html = do_shortcode( '[openbb_login]' );

		$this->assertStringContainsString( 'jane@example.com', $html );
		$this->assertStringNotContainsString( 'data-panel', $html );
	}
}
