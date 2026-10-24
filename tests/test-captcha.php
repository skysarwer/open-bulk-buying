<?php
/**
 * Bot protection: OPENBB_Captcha, the protected signup and login routes, and the settings.
 *
 * Cloudflare is never contacted; pre_http_request answers the siteverify call.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Captcha extends OPENBB_TestCase {

	const SITE_KEY = '1x00000000000000000000AA';
	const SECRET   = '1x0000000000000000000000000000000AA';
	const TOKEN    = 'XXXX.DUMMY.TOKEN.XXXX';

	/** @var array|WP_Error|null Next siteverify response: decoded body, a WP_Error, or null to fail the test. */
	private $siteverify = null;

	/** @var array[] Bodies posted to siteverify. */
	private $verified = array();

	/** @var int */
	private $rules = 0;

	/** @var string */
	private $error_log = '';

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		$this->siteverify = array( 'success' => true );
		$this->verified   = array();
		add_filter( 'pre_http_request', array( $this, 'fake_siteverify' ), 10, 3 );
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'send_auth_cookies', '__return_false' );
		add_filter( 'openbb_rate_limit', '__return_zero' );
		unset( $_COOKIE[ OPENBB_REST_Signup::COOKIE ], $_COOKIE[ OPENBB_REST_Login::COOKIE ] );
		wp_set_current_user( 0 );
		// Keep the verification failure log out of the test output.
		$this->error_log = (string) ini_set( 'error_log', wp_tempnam( 'openbb-captcha-log' ) );

		self::factory()->user->create(
			array(
				'user_login' => 'jane',
				'user_email' => 'jane@example.com',
				'user_pass'  => 'correct horse',
			)
		);
		OPENBB_Rules::save_draft( array( array( 'default' => '<p>Be kind.</p>' ) ) );
		$this->rules = OPENBB_Rules::publish( true, 1 )->id;

		$this->set_general(
			array(
				'allow_signups'      => true,
				'allow_login_codes'  => true,
				'captcha_provider'   => OPENBB_Captcha::PROVIDER_TURNSTILE,
				'turnstile_site_key' => self::SITE_KEY,
			)
		);
		update_option( OPENBB_Captcha::SECRET_OPTION, self::SECRET, false );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_siteverify' ) );
		remove_filter( 'pre_wp_mail', '__return_true' );
		remove_filter( 'send_auth_cookies', '__return_false' );
		remove_filter( 'openbb_rate_limit', '__return_zero' );
		remove_all_filters( 'openbb_captcha_verify' );
		remove_all_filters( 'openbb_captcha_field' );
		unset( $_COOKIE[ OPENBB_REST_Signup::COOKIE ], $_COOKIE[ OPENBB_REST_Login::COOKIE ] );
		ini_set( 'error_log', $this->error_log );
		parent::tear_down();
	}

	public function fake_siteverify( $pre, array $args, string $url ) {
		if ( OPENBB_Captcha::TURNSTILE_VERIFY_URL !== $url ) {
			return $pre;
		}
		$this->verified[] = $args['body'];
		if ( null === $this->siteverify ) {
			$this->fail( 'Turnstile should not have been asked.' );
		}
		if ( is_wp_error( $this->siteverify ) ) {
			return $this->siteverify;
		}

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $this->siteverify ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	private function set_general( array $values ): void {
		update_option( OPENBB_Settings::GENERAL_OPTION, array_merge( OPENBB_Settings::get_general(), $values ), false );
	}

	private function post( string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/openbb/v1' . $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );

		return rest_do_request( $request );
	}

	private function captcha( string $token = self::TOKEN ): array {
		return array( 'captcha' => array( OPENBB_Captcha::TURNSTILE_FIELD => $token ) );
	}

	private function login( array $extra = array() ): WP_REST_Response {
		return $this->post(
			'/login',
			array_merge(
				array(
					'login'    => 'jane',
					'password' => 'correct horse',
				),
				$extra
			)
		);
	}

	private function send_code( array $extra = array() ): WP_REST_Response {
		return $this->post(
			'/login/code',
			array_merge(
				array(
					'login' => 'jane@example.com',
					'page'  => home_url( '/login/' ),
				),
				$extra
			)
		);
	}

	private function signup( array $extra = array() ): WP_REST_Response {
		return $this->post(
			'/signup',
			array_merge(
				array(
					'account'       => array(
						'email'      => 'sam@example.com',
						'first_name' => 'Sam',
						'last_name'  => 'Lee',
						'phone'      => '555-0101',
					),
					'rules_version' => $this->rules,
				),
				$extra
			)
		);
	}

	private function assertError( string $code, int $status, WP_REST_Response $response ): void {
		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	// ------------------------------------------------------------------
	// Off.
	// ------------------------------------------------------------------

	public function test_off_by_default(): void {
		delete_option( OPENBB_Settings::GENERAL_OPTION );

		$this->assertSame( OPENBB_Captcha::PROVIDER_NONE, OPENBB_Settings::get_general()['captcha_provider'] );
		$this->assertFalse( OPENBB_Captcha::enabled() );
	}

	public function test_needs_both_keys(): void {
		delete_option( OPENBB_Captcha::SECRET_OPTION );
		$this->assertFalse( OPENBB_Captcha::enabled() );

		update_option( OPENBB_Captcha::SECRET_OPTION, self::SECRET, false );
		$this->set_general( array( 'turnstile_site_key' => '' ) );
		$this->assertFalse( OPENBB_Captcha::enabled() );
	}

	public function test_routes_skip_the_check_when_off(): void {
		$this->set_general( array( 'captcha_provider' => OPENBB_Captcha::PROVIDER_NONE ) );
		$this->siteverify = null;

		$this->assertSame( 200, $this->login()->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 200, $this->send_code()->get_status() );
		$this->assertSame( 200, $this->signup()->get_status() );
		$this->assertSame( '', OPENBB_Captcha::field( OPENBB_Captcha::ACTION_LOGIN ) );
	}

	// ------------------------------------------------------------------
	// Routes.
	// ------------------------------------------------------------------

	public function test_routes_refuse_a_missing_token_without_asking_turnstile(): void {
		$this->siteverify = null;

		$this->assertError( 'openbb_captcha_failed', 403, $this->login() );
		$this->assertError( 'openbb_captcha_failed', 403, $this->send_code() );
		$this->assertError( 'openbb_captcha_failed', 403, $this->signup() );
		$this->assertError( 'openbb_captcha_failed', 403, $this->login( $this->captcha( str_repeat( 'a', 2049 ) ) ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertFalse( get_user_by( 'email', 'sam@example.com' ) );
	}

	public function test_login_with_a_valid_token(): void {
		$response = $this->login( $this->captcha() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( get_user_by( 'login', 'jane' )->ID, get_current_user_id() );
		$this->assertCount( 1, $this->verified );
		$this->assertSame( self::SECRET, $this->verified[0]['secret'] );
		$this->assertSame( self::TOKEN, $this->verified[0]['response'] );
	}

	public function test_code_and_signup_with_a_valid_token(): void {
		$this->assertSame( 200, $this->send_code( $this->captcha() )->get_status() );
		$this->assertSame( 200, $this->signup( $this->captcha() )->get_status() );
		$this->assertCount( 2, $this->verified );
	}

	public function test_resending_requires_captcha_but_verification_does_not(): void {
		$this->assertSame( 200, $this->send_code( $this->captcha() )->get_status() );
		$this->siteverify = null;
		$this->assertError( 'openbb_captcha_failed', 403, $this->post( '/login/code/resend' ) );
		$this->assertError( 'openbb_code_invalid', 400, $this->post( '/login/code/verify', array( 'code' => 'not a code' ) ) );
		$this->assertCount( 1, $this->verified );
	}

	public function test_the_token_is_checked_before_the_password(): void {
		$this->siteverify = array(
			'success'     => false,
			'error-codes' => array( 'invalid-input-response' ),
		);

		$response = $this->login( array_merge( $this->captcha(), array( 'password' => 'wrong' ) ) );

		$this->assertError( 'openbb_captcha_failed', 403, $response );
	}

	public function test_an_expired_token_says_so(): void {
		$this->siteverify = array(
			'success'     => false,
			'error-codes' => array( 'timeout-or-duplicate' ),
		);

		$response = $this->login( $this->captcha() );

		$this->assertError( 'openbb_captcha_failed', 403, $response );
		$this->assertStringContainsString( 'expired', $response->get_data()['message'] );
	}

	public function test_a_token_from_the_other_form_is_refused(): void {
		$this->siteverify = array(
			'success' => true,
			'action'  => OPENBB_Captcha::ACTION_SIGNUP,
		);
		$this->assertError( 'openbb_captcha_failed', 403, $this->login( $this->captcha() ) );

		$this->siteverify = array(
			'success' => true,
			'action'  => OPENBB_Captcha::ACTION_LOGIN,
		);
		$this->assertSame( 200, $this->login( $this->captcha() )->get_status() );
	}

	/**
	 * @dataProvider unavailable_responses
	 */
	public function test_turnstile_trouble_is_a_503( $siteverify ): void {
		$this->siteverify = $siteverify;

		$this->assertError( 'openbb_captcha_unavailable', 503, $this->login( $this->captcha() ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function unavailable_responses(): array {
		return array(
			'network error'  => array( new WP_Error( 'http_request_failed', 'Timed out' ) ),
			'bad secret'     => array(
				array(
					'success'     => false,
					'error-codes' => array( 'invalid-input-secret' ),
				),
			),
			'missing secret' => array(
				array(
					'success'     => false,
					'error-codes' => array( 'missing-input-secret' ),
				),
			),
		);
	}

	// ------------------------------------------------------------------
	// Filters.
	// ------------------------------------------------------------------

	public function test_verify_filter_replaces_the_built_in_check(): void {
		$this->set_general( array( 'captcha_provider' => OPENBB_Captcha::PROVIDER_NONE ) );
		$this->siteverify = null;
		$seen             = array();
		add_filter(
			'openbb_captcha_verify',
			function ( $result, $fields, $action ) use ( &$seen ) {
				$seen[] = array( $fields, $action );
				return 'yes' === ( $fields['answer'] ?? '' )
					? true
					: new WP_Error( 'my_captcha', 'Nope', array( 'status' => 403 ) );
			},
			10,
			3
		);

		$this->assertError( 'my_captcha', 403, $this->login( array( 'captcha' => array( 'answer' => 'no' ) ) ) );
		$this->assertSame( 200, $this->login( array( 'captcha' => array( 'answer' => 'yes' ) ) )->get_status() );
		$this->assertSame( array( array( 'answer' => 'yes' ), OPENBB_Captcha::ACTION_LOGIN ), $seen[1] );
	}

	public function test_field_filter_adds_another_widget(): void {
		$this->set_general( array( 'captcha_provider' => OPENBB_Captcha::PROVIDER_NONE ) );
		add_filter(
			'openbb_captcha_field',
			function ( $html, $action ) {
				return '<input name="answer" data-form="' . $action . '">';
			},
			10,
			2
		);

		$html = OPENBB_Captcha::field( OPENBB_Captcha::ACTION_SIGNUP );

		$this->assertStringContainsString( 'data-openbb-captcha', $html );
		$this->assertStringContainsString( '<input name="answer" data-form="signup">', $html );
	}

	// ------------------------------------------------------------------
	// Views.
	// ------------------------------------------------------------------

	public function test_forms_show_the_widget(): void {
		$login  = do_shortcode( '[openbb_login]' );
		$signup = do_shortcode( '[openbb_signup]' );

		$this->assertStringContainsString( 'data-sitekey="' . self::SITE_KEY . '"', $login );
		$this->assertStringContainsString( 'data-action="login"', $login );
		$this->assertStringContainsString( 'data-action="signup"', $signup );
		$this->assertStringContainsString( 'data-wp-interactive="openbb/captcha"', $signup );
	}

	public function test_members_joining_are_not_asked(): void {
		wp_set_current_user( get_user_by( 'login', 'jane' )->ID );

		$this->assertStringNotContainsString( 'data-action="signup"', do_shortcode( '[openbb_signup]' ) );
	}

	// ------------------------------------------------------------------
	// Settings.
	// ------------------------------------------------------------------

	private function save_settings( array $general, array $extra = array() ): WP_REST_Response {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		return $this->post(
			'/settings',
			array_merge( array( 'general' => array_merge( OPENBB_Settings::get_general(), $general ) ), $extra )
		);
	}

	public function test_settings_never_return_the_secret(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/openbb/v1/settings' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'secret_set'           => true,
				'secret_from_constant' => false,
			),
			$response->get_data()['captcha']
		);
		$this->assertStringNotContainsString( self::SECRET, wp_json_encode( $response->get_data() ) );
	}

	public function test_settings_save_the_secret_and_keep_it_when_blank(): void {
		delete_option( OPENBB_Captcha::SECRET_OPTION );

		$this->assertSame( 200, $this->save_settings( array(), array( 'captcha_secret' => 'new-secret' ) )->get_status() );
		$this->assertSame( 'new-secret', OPENBB_Captcha::secret() );

		$this->assertSame( 200, $this->save_settings( array(), array( 'captcha_secret' => '' ) )->get_status() );
		$this->assertSame( 'new-secret', OPENBB_Captcha::secret() );
	}

	public function test_turnstile_needs_both_keys_to_save(): void {
		delete_option( OPENBB_Captcha::SECRET_OPTION );

		$response = $this->save_settings( array( 'turnstile_site_key' => '' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'openbb_captcha_incomplete', $response->get_data()['code'] );
		$this->assertSame(
			array( 'turnstile_site_key', 'captcha_secret' ),
			array_keys( $response->get_data()['data']['errors'] )
		);
	}

	public function test_settings_reject_bad_keys(): void {
		$this->assertSame( 400, $this->save_settings( array( 'captcha_provider' => 'recaptcha' ) )->get_status() );
		$this->assertSame( 400, $this->save_settings( array( 'turnstile_site_key' => '<script>' ) )->get_status() );
		$this->assertSame( 400, $this->save_settings( array(), array( 'captcha_secret' => 'a b' ) )->get_status() );
	}
}
