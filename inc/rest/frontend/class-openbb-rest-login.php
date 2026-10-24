<?php
/**
 * Login routes for the login view (OPENBB_Login_View).
 *
 * POST /login              Log in with a username or email and password (wp_signon(), so
 *                          core and plugin authentication hooks apply).
 * POST /login/link         Email a single-use login link (OPENBB_Login_Link). The response is
 *                          the same whether or not the account exists.
 * POST /login/link/verify  Use a link's token and log in.
 *
 * Visitors call these, so every route is rate-limited per IP. Logging in and asking for a
 * link also pass bot protection (OPENBB_Captcha) when it's on.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_REST_Login extends OPENBB_REST_Controller {

	public function register_routes(): void {
		$redirect = array(
			'type'    => 'string',
			'default' => '',
		);
		// Bot protection widget response (OPENBB_Captcha).
		$captcha = array(
			'type'    => 'object',
			'default' => array(),
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/login',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'log_in' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'login'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'password'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'remember'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'redirect_to' => $redirect,
					'captcha'     => $captcha,
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/login/link',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_link' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'login'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'redirect_to' => $redirect,
					'page'        => $redirect,
					'captcha'     => $captcha,
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/login/link/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify_link' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 100,
					),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
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
			return new WP_Error(
				'openbb_login_invalid',
				__( 'Some details need attention.', 'open-bulk-buying' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
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

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_link( WP_REST_Request $request ) {
		if ( ! OPENBB_Login_Link::enabled() ) {
			return self::links_disabled();
		}
		$limited = OPENBB_Rate_Limit::hit( 'login_link', 10, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		if ( is_user_logged_in() ) {
			return self::logged_in();
		}

		$login = trim( (string) $request['login'] );
		if ( '' === $login ) {
			return new WP_Error(
				'openbb_login_invalid',
				__( 'Some details need attention.', 'open-bulk-buying' ),
				array(
					'status' => 400,
					'errors' => array( 'login' => __( 'This field is required.', 'open-bulk-buying' ) ),
				)
			);
		}

		$human = OPENBB_Captcha::verify( $request, OPENBB_Captcha::ACTION_LOGIN );
		if ( is_wp_error( $human ) ) {
			return $human;
		}

		$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		// Unknown accounts, accounts asked for again within the cooldown, and accounts that
		// must use a password all get the same response; only the email differs.
		if ( $user && OPENBB_Login_Link::usable( $user->ID ) && 0 === OPENBB_Login_Link::cooldown( $user->ID ) ) {
			$redirect = wp_validate_redirect( (string) $request['redirect_to'], '' );
			$page     = wp_validate_redirect( (string) $request['page'], home_url( '/' ) );

			if ( OPENBB_Login_Link::allowed( $user->ID ) ) {
				$issued = OPENBB_Login_Link::issue( $user->ID, $redirect, $page );
				if ( is_wp_error( $issued ) ) {
					return $issued;
				}
				$sent = OPENBB_Login_Link::send( $user, $issued['url'] );
			} else {
				OPENBB_Login_Link::mark_sent( $user->ID );
				$sent = OPENBB_Login_Link::send_password_required( $user, $page );
			}

			if ( ! $sent ) {
				OPENBB_Login_Link::revoke( $user->ID );
				return new WP_Error( 'openbb_mail_failed', __( 'We couldn\'t send the email. Please try again later, or contact the co-op.', 'open-bulk-buying' ), array( 'status' => 500 ) );
			}
		}

		return rest_ensure_response(
			array(
				'step'         => 'sent',
				'resend_after' => OPENBB_Login_Link::RESEND_COOLDOWN,
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_link( WP_REST_Request $request ) {
		if ( ! OPENBB_Login_Link::enabled() ) {
			return self::links_disabled();
		}
		$limited = OPENBB_Rate_Limit::hit( 'login_link_verify', 30, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		if ( is_user_logged_in() ) {
			return self::logged_in();
		}

		$link = OPENBB_Login_Link::consume( (string) $request['token'] );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		OPENBB_REST_Signup::log_in( $link['user_id'] );
		$user = wp_get_current_user();
		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user );

		return rest_ensure_response( array( 'redirect' => self::redirect( $link['redirect'], $user ) ) );
	}

	/**
	 * Where to go after logging in: the requested URL when it's on this site (else the home
	 * page), passed through core's login_redirect filter.
	 */
	public static function redirect( string $requested, WP_User $user ): string {
		$fallback  = home_url( '/' );
		$requested = wp_validate_redirect( $requested, $fallback );

		/** This filter is documented in wp-login.php */
		$redirect = (string) apply_filters( 'login_redirect', $requested, $requested, $user );

		return wp_validate_redirect( $redirect, $fallback );
	}

	/**
	 * Wrong username, email or password all get the same message; other errors (e.g. from
	 * security plugins) keep theirs.
	 */
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

	private static function links_disabled(): WP_Error {
		return new WP_Error( 'openbb_login_links_disabled', __( 'Login links are turned off. Please log in with your password.', 'open-bulk-buying' ), array( 'status' => 403 ) );
	}
}
