<?php
/**
 * Public login routes: password, browser-bound email code, resend and verification.
 * All routes are rate-limited. Password login and code delivery also require bot
 * protection when enabled. Unknown and password-only accounts get the same code UI.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_REST_Login extends OPENBB_REST_Controller {

	const COOKIE = 'openbb_login';

	public function register_routes(): void {
		$string  = array( 'type' => 'string', 'default' => '' );
		$captcha = array( 'type' => 'object', 'default' => array() );
		$routes  = array(
			'/login'             => array(
				'callback' => 'log_in',
				'args'     => array(
					'login'       => $string,
					'password'    => $string,
					'remember'    => array( 'type' => 'boolean', 'default' => false ),
					'redirect_to' => $string,
					'captcha'     => $captcha,
				),
			),
			'/login/code'        => array(
				'callback' => 'send_code',
				'args'     => array(
					'login'       => $string,
					'redirect_to' => $string,
					'page'        => $string,
					'captcha'     => $captcha,
				),
			),
			'/login/code/resend' => array(
				'callback' => 'resend_code',
				'args'     => array( 'captcha' => $captcha ),
			),
			'/login/code/verify' => array(
				'callback' => 'verify_code',
				'args'     => array( 'code' => array( 'type' => 'string', 'required' => true, 'maxLength' => 20 ) ),
			),
		);
		foreach ( $routes as $route => $definition ) {
			register_rest_route(
				self::API_NAMESPACE,
				$route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $definition['callback'] ),
					'permission_callback' => '__return_true',
					'args'                => $definition['args'],
				)
			);
		}
	}

	/** @return WP_REST_Response|WP_Error */
	public function log_in( WP_REST_Request $request ) {
		$limited = OPENBB_Rate_Limit::hit( 'login', 20, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		if ( is_user_logged_in() ) {
			return self::logged_in();
		}

		$login    = trim( (string) $request['login'] );
		$password = (string) $request['password'];
		$errors   = array();
		if ( '' === $login ) {
			$errors['login'] = __( 'This field is required.', 'open-bulk-buying' );
		}
		if ( '' === $password ) {
			$errors['password'] = __( 'This field is required.', 'open-bulk-buying' );
		}
		if ( $errors ) {
			return new WP_Error( 'openbb_login_invalid', __( 'Some details need attention.', 'open-bulk-buying' ), array( 'status' => 400, 'errors' => $errors ) );
		}

		$human = OPENBB_Captcha::verify( $request, OPENBB_Captcha::ACTION_LOGIN );
		if ( is_wp_error( $human ) ) {
			return $human;
		}

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => (bool) $request['remember'],
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			return self::signon_error( $user );
		}
		wp_set_current_user( $user->ID );

		return rest_ensure_response( array( 'redirect' => self::redirect( (string) $request['redirect_to'], $user ) ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function send_code( WP_REST_Request $request ) {
		$ready = self::code_request( 'login_code', 10 );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$login = trim( (string) $request['login'] );
		if ( '' === $login ) {
			return new WP_Error( 'openbb_login_invalid', __( 'Some details need attention.', 'open-bulk-buying' ), array( 'status' => 400, 'errors' => array( 'login' => __( 'This field is required.', 'open-bulk-buying' ) ) ) );
		}
		$human = OPENBB_Captcha::verify( $request, OPENBB_Captcha::ACTION_LOGIN );
		if ( is_wp_error( $human ) ) {
			return $human;
		}

		$token   = OPENBB_Email_Code::token( OPENBB_Email_Code::PURPOSE_LOGIN, $request );
		$pending = OPENBB_Email_Code::get( OPENBB_Email_Code::PURPOSE_LOGIN, $token );
		// Re-submitting must not replace an emailed code or reset its attempt count.
		if ( $pending && $pending['payload']['login'] === $login ) {
			return self::code_sent( $pending['resend_after'] );
		}

		$user     = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		$can_send = $user && OPENBB_Login_Code::usable( $user->ID ) && 0 === OPENBB_Login_Code::cooldown( $user->ID );
		$issued   = OPENBB_Email_Code::issue(
			OPENBB_Email_Code::PURPOSE_LOGIN,
			$user ? $user->user_email : $login,
			array(
				'user_id'  => $user ? $user->ID : 0,
				'login'    => $login,
				'redirect' => wp_validate_redirect( (string) $request['redirect_to'], '' ),
				'page'     => wp_validate_redirect( (string) $request['page'], home_url( '/' ) ),
			),
			$can_send && OPENBB_Login_Code::allowed( $user->ID )
		);
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}
		if ( $can_send ) {
			$sent = self::deliver( $user, $issued['code'], wp_validate_redirect( (string) $request['page'], home_url( '/' ) ) );
			if ( is_wp_error( $sent ) ) {
				OPENBB_Email_Code::discard( $issued['token'] );
				return $sent;
			}
		}

		OPENBB_Email_Code::discard( $token );
		OPENBB_Email_Code::set_cookie( OPENBB_Email_Code::PURPOSE_LOGIN, $issued['token'], OPENBB_Email_Code::TTL );

		return self::code_sent( $issued['resend_after'] );
	}

	/** @return WP_REST_Response|WP_Error */
	public function resend_code( WP_REST_Request $request ) {
		$ready = self::code_request( 'login_code_resend', 10 );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$human = OPENBB_Captcha::verify( $request, OPENBB_Captcha::ACTION_LOGIN );
		if ( is_wp_error( $human ) ) {
			return $human;
		}
		$token    = OPENBB_Email_Code::token( OPENBB_Email_Code::PURPOSE_LOGIN, $request );
		$pending  = OPENBB_Email_Code::get( OPENBB_Email_Code::PURPOSE_LOGIN, $token );
		$user     = $pending ? get_userdata( $pending['payload']['user_id'] ) : false;
		$can_send = $user && OPENBB_Login_Code::usable( $user->ID ) && $user->user_email === $pending['email'];
		$wait     = $can_send ? OPENBB_Login_Code::cooldown( $user->ID ) : 0;
		if ( $pending && $wait > $pending['resend_after'] ) {
			return new WP_Error( 'openbb_code_cooldown', __( 'Please wait before asking for another code.', 'open-bulk-buying' ), array( 'status' => 429, 'resend_after' => $wait ) );
		}
		$resent = OPENBB_Email_Code::resend( OPENBB_Email_Code::PURPOSE_LOGIN, $token, $can_send && OPENBB_Login_Code::allowed( $user->ID ) );
		if ( is_wp_error( $resent ) ) {
			return $resent;
		}
		if ( $can_send ) {
			$sent = self::deliver( $user, $resent['code'], $resent['payload']['page'] );
			if ( is_wp_error( $sent ) ) {
				OPENBB_Email_Code::discard( $token );
				return $sent;
			}
		}
		return self::code_sent( $resent['resend_after'] );
	}

	/** @return WP_REST_Response|WP_Error */
	public function verify_code( WP_REST_Request $request ) {
		$ready = self::code_request( 'login_code_verify', 30 );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$verified = OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_LOGIN, OPENBB_Email_Code::token( OPENBB_Email_Code::PURPOSE_LOGIN, $request ), (string) $request['code'] );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		OPENBB_Email_Code::set_cookie( OPENBB_Email_Code::PURPOSE_LOGIN, '', -1 );
		$user = get_userdata( $verified['payload']['user_id'] );
		if ( ! $user || $user->user_email !== $verified['email'] || ! OPENBB_Login_Code::allowed( $user->ID ) ) {
			return new WP_Error( 'openbb_login_code_unavailable', __( 'Email-code login is no longer available for this account. Please log in with your password.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}

		OPENBB_REST_Signup::log_in( $user->ID );
		$user = wp_get_current_user();
		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user );

		return rest_ensure_response( array( 'redirect' => self::redirect( $verified['payload']['redirect'], $user ) ) );
	}

	public static function pending(): ?array {
		return OPENBB_Login_Code::enabled() ? OPENBB_Email_Code::get( OPENBB_Email_Code::PURPOSE_LOGIN, OPENBB_Email_Code::token( OPENBB_Email_Code::PURPOSE_LOGIN ) ) : null;
	}

	/** @return true|WP_Error */
	private static function code_request( string $bucket, int $limit ) {
		if ( ! OPENBB_Login_Code::enabled() ) {
			return new WP_Error( 'openbb_login_codes_disabled', __( 'Email-code login is turned off. Please log in with your password.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}
		$limited = OPENBB_Rate_Limit::hit( $bucket, $limit, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		return is_user_logged_in() ? self::logged_in() : true;
	}

	/** @return true|WP_Error */
	private static function deliver( WP_User $user, string $code, string $page ) {
		$sent = OPENBB_Login_Code::allowed( $user->ID )
			? OPENBB_Login_Code::send( $user, $code )
			: OPENBB_Login_Code::send_password_required( $user, $page );
		if ( ! $sent ) {
			return new WP_Error( 'openbb_mail_failed', __( 'We couldn\'t send the email. Please try again later, or contact the co-op.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}
		return OPENBB_Login_Code::mark_sent( $user->ID );
	}

	private static function code_sent( int $resend_after ): WP_REST_Response {
		return rest_ensure_response( array( 'step' => 'code', 'resend_after' => $resend_after ) );
	}

	public static function redirect( string $requested, WP_User $user ): string {
		$fallback  = home_url( '/' );
		$requested = wp_validate_redirect( $requested, $fallback ) ?: $fallback;
		/** This filter is documented in wp-login.php */
		$redirect = (string) apply_filters( 'login_redirect', $requested, $requested, $user );

		return wp_validate_redirect( $redirect, $fallback ) ?: $fallback;
	}

	private static function signon_error( WP_Error $error ): WP_Error {
		$generic = array( 'invalid_username', 'invalid_email', 'incorrect_password', 'empty_username', 'empty_password', 'authentication_failed' );
		if ( in_array( $error->get_error_code(), $generic, true ) ) {
			return new WP_Error( 'openbb_login_failed', __( 'The email, username or password is incorrect.', 'open-bulk-buying' ), array( 'status' => 401 ) );
		}
		$message = wp_strip_all_tags( (string) $error->get_error_message() );
		// Core prefixes its messages with "Error:", which the notice doesn't need.
		$message = trim( (string) preg_replace( '/^\s*' . preg_quote( __( 'Error:' ), '/' ) . '\s*/i', '', $message ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain

		return new WP_Error( $error->get_error_code(), '' !== $message ? $message : __( 'You could not be logged in.', 'open-bulk-buying' ), array( 'status' => 403 ) );
	}

	private static function logged_in(): WP_Error {
		return new WP_Error( 'openbb_login_logged_in', __( 'You are already logged in. Reload the page to continue.', 'open-bulk-buying' ), array( 'status' => 409 ) );
	}
}
