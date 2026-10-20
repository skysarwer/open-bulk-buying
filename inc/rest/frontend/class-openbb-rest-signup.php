<?php
/**
 * Signup routes for the [openbb_signup] view.
 *
 * POST /signup         Validate the form. Signed-in users without a co-op role join right
 *                      away. Visitors get an emailed code (OPENBB_Email_Code), bound to
 *                      this browser by an HttpOnly cookie; no account exists until it's verified.
 * POST /signup/resend  A new code, after a cooldown.
 * POST /signup/verify  Check the code, create the account and log the person in.
 *
 * Visitors may call these, so instead of a capability the permission callback requires
 * signups to be open, and every route is rate-limited per IP. Responses to a visitor never
 * reveal whether an email already has an account.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_REST_Signup extends OPENBB_REST_Controller {

	const COOKIE = 'openbb_signup';

	/** Name of the honeypot field; people never see or fill it. */
	const HONEYPOT = 'openbb_website';

	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/signup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( __CLASS__, 'can_sign_up' ),
				'args'                => array(
					'account'       => array(
						'type'    => 'object',
						'default' => array(),
					),
					'profile'       => array(
						'type'    => 'object',
						'default' => array(),
					),
					'rules_version' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					self::HONEYPOT  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/signup/resend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resend' ),
				'permission_callback' => array( __CLASS__, 'can_sign_up' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/signup/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => array( __CLASS__, 'can_sign_up' ),
				'args'                => array(
					'code' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 20,
					),
				),
			)
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function can_sign_up() {
		if ( ! OPENBB_Signup::is_open() ) {
			return new WP_Error( 'openbb_signup_closed', __( 'Signups are closed right now.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function start( WP_REST_Request $request ) {
		$limited = OPENBB_Rate_Limit::hit( 'signup_start', 10, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$account       = (array) $request['account'];
		$profile       = (array) $request['profile'];
		$rules_version = (int) $request['rules_version'];

		if ( is_user_logged_in() ) {
			$joined = OPENBB_Signup::join( get_current_user_id(), $account, $profile, $rules_version );
			if ( is_wp_error( $joined ) ) {
				return $joined;
			}

			return rest_ensure_response( self::done( $joined['coop_role'] ) );
		}

		$signup = OPENBB_Signup::validate_signup( $account, $profile, $rules_version );
		if ( is_wp_error( $signup ) ) {
			return $signup;
		}
		$email = $signup['account']['email'];

		// Bots that fill every field get the normal response, and nothing is sent.
		if ( '' !== trim( (string) $request[ self::HONEYPOT ] ) ) {
			return rest_ensure_response( self::code_sent( $email, OPENBB_Email_Code::RESEND_COOLDOWN ) );
		}

		// Existing accounts get the same response, but the email tells them to log in instead.
		$deliverable = ! email_exists( $email );
		$issued      = OPENBB_Email_Code::issue(
			OPENBB_Email_Code::PURPOSE_SIGNUP,
			$email,
			array(
				'account'       => $account,
				'profile'       => $profile,
				'rules_version' => $rules_version,
			),
			$deliverable
		);
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$sent = $deliverable ? OPENBB_Signup::send_code( $email, $issued['code'] ) : OPENBB_Signup::send_account_exists( $email );
		if ( ! $sent ) {
			OPENBB_Email_Code::discard( $issued['token'] );
			return self::mail_failed();
		}

		self::set_cookie( $issued['token'], OPENBB_Email_Code::TTL );

		return rest_ensure_response( self::code_sent( $email, $issued['resend_after'] ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend( WP_REST_Request $request ) {
		$limited = OPENBB_Rate_Limit::hit( 'signup_resend', 10, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$resent = OPENBB_Email_Code::resend( OPENBB_Email_Code::PURPOSE_SIGNUP, self::token( $request ) );
		if ( is_wp_error( $resent ) ) {
			return $resent;
		}

		$sent = $resent['deliverable'] ? OPENBB_Signup::send_code( $resent['email'], $resent['code'] ) : OPENBB_Signup::send_account_exists( $resent['email'] );
		if ( ! $sent ) {
			return self::mail_failed();
		}

		return rest_ensure_response( self::code_sent( $resent['email'], $resent['resend_after'] ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify( WP_REST_Request $request ) {
		$limited = OPENBB_Rate_Limit::hit( 'signup_verify', 30, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		if ( is_user_logged_in() ) {
			return new WP_Error( 'openbb_signup_logged_in', __( 'You are already logged in. Reload the page to continue.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		$token    = self::token( $request );
		$verified = OPENBB_Email_Code::verify( OPENBB_Email_Code::PURPOSE_SIGNUP, $token, (string) $request['code'] );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		self::set_cookie( '', -1 );

		$payload    = $verified['payload'];
		$registered = OPENBB_Signup::register(
			array( 'email' => $verified['email'] ) + (array) ( $payload['account'] ?? array() ),
			(array) ( $payload['profile'] ?? array() ),
			(int) ( $payload['rules_version'] ?? 0 )
		);
		if ( is_wp_error( $registered ) ) {
			return $registered;
		}

		self::log_in( $registered['user_id'] );

		return rest_ensure_response( self::done( $registered['coop_role'] ) + array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * The email of the challenge this browser is waiting on, for re-opening the code step
	 * after a reload; null if there's none.
	 */
	public static function pending(): ?array {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		return '' === $token ? null : OPENBB_Email_Code::get( OPENBB_Email_Code::PURPOSE_SIGNUP, $token );
	}

	private static function token( WP_REST_Request $request ): string {
		$cookies = $request->get_header( 'cookie' );
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}

		// Internal requests (e.g. tests) have no superglobal cookie.
		if ( $cookies && preg_match( '/(?:^|;\s*)' . self::COOKIE . '=([A-Za-z0-9]+)/', $cookies, $match ) ) {
			return $match[1];
		}

		return '';
	}

	private static function set_cookie( string $token, int $ttl ): void {
		// Mirrored for the rest of this request (and for tests, where headers are already sent).
		if ( '' === $token ) {
			unset( $_COOKIE[ self::COOKIE ] );
		} else {
			$_COOKIE[ self::COOKIE ] = $token;
		}
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => $ttl > 0 ? time() + $ttl : 1,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Logs the new member in. The nonce in the response must belong to the new session,
	 * which wp_create_nonce() reads from the logged-in cookie, so it's mirrored into $_COOKIE.
	 */
	private static function log_in( int $user_id ): void {
		$mirror = static function ( string $cookie ): void {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		};
		add_action( 'set_logged_in_cookie', $mirror );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		remove_action( 'set_logged_in_cookie', $mirror );
	}

	private static function code_sent( string $email, int $resend_after ): array {
		return array(
			'step'         => 'code',
			'email'        => $email,
			'resend_after' => $resend_after,
		);
	}

	private static function done( string $role ): array {
		return array(
			'step'      => 'done',
			'coop_role' => $role,
		);
	}

	private static function mail_failed(): WP_Error {
		return new WP_Error( 'openbb_mail_failed', __( 'We couldn\'t send the email. Please try again later, or contact the co-op.', 'open-bulk-buying' ), array( 'status' => 500 ) );
	}
}
