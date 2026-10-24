<?php
/**
 * The login view: [openbb_login], and the login panel of the signup view.
 *
 * Visitors log in with their email or username and password, or enter a single-use
 * emailed code in the requesting browser. Signed-in users see who they're logged in as.
 *
 * Both shortcodes render the same two panels in an `openbb/auth` region (see auth()):
 * [openbb_login] starts on the login form and [openbb_signup] on the signup form, and
 * each has a button to switch to the other. Signup is left out while signups are closed.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Login_View {

	const ID      = 'openbb-login';
	const AUTH_ID = 'openbb-auth';

	/**
	 * login_redirect: where to go after logging in or signing up (URL on this site;
	 * default: this page).
	 */
	public static function defaults(): array {
		return array(
			'title'          => '',
			'intro'          => '',
			'login_redirect' => '',
		);
	}

	public static function render( array $args = array() ): string {
		$args = array_merge( self::defaults(), array_intersect_key( $args, self::defaults() ) );

		OPENBB_Frontend::no_cache();
		OPENBB_Frontend::enqueue_style();

		$args['login_redirect'] = OPENBB_Frontend::redirect_url( (string) $args['login_redirect'] );

		if ( is_user_logged_in() ) {
			return OPENBB_Frontend::template(
				'login-message',
				array(
					'title'        => (string) $args['title'],
					'email'        => wp_get_current_user()->user_email,
					'continue_url' => $args['login_redirect'] !== OPENBB_Frontend::current_url() ? $args['login_redirect'] : '',
					'logout_url'   => wp_logout_url( OPENBB_Frontend::current_url() ),
				)
			);
		}

		$open   = OPENBB_Signup::is_open();
		$panels = array( 'login' => self::panel( $args, $open ) );
		if ( $open ) {
			$panels['signup'] = OPENBB_Signup_View::panel( $args['login_redirect'] );
		}

		return self::auth( 'login', $panels );
	}

	/**
	 * The login and signup panels, $default shown first, with switching between them.
	 * A pending login code reopens on the login panel. Returns processed markup.
	 *
	 * @param string   $default login or signup.
	 * @param string[] $panels  Unprocessed markup keyed by login and signup; either may be missing.
	 */
	public static function auth( string $default, array $panels ): string {
		$panels = array_filter( array_intersect_key( $panels, array_flip( array( 'login', 'signup' ) ) ) );
		if ( isset( $panels['login'] ) && OPENBB_REST_Login::pending() ) {
			$default = 'login';
		}
		if ( ! isset( $panels[ $default ] ) ) {
			$default = (string) key( $panels );
		}

		OPENBB_Frontend::enqueue( array( OPENBB_Frontend::MODULE_AUTH ) );

		$context = array(
			'show'  => array(
				'login'  => 'login' === $default,
				'signup' => 'signup' === $default,
			),
			'focus' => false,
		);

		$html = '';
		foreach ( $panels as $name => $panel ) {
			$html .= sprintf(
				'<div class="openbb-auth__panel" data-panel="%1$s" tabindex="-1" data-wp-bind--hidden="!context.show.%1$s" data-wp-watch="callbacks.focusPanel">%2$s</div>',
				esc_attr( $name ),
				$panel
			);
		}

		return OPENBB_Frontend::process(
			sprintf(
				'<div class="openbb openbb-auth" id="%s" data-wp-interactive="openbb/auth" %s>%s</div>',
				esc_attr( self::AUTH_ID ),
				wp_interactivity_data_wp_context( $context ),
				$html
			)
		);
	}

	/**
	 * The login form for a visitor. Unprocessed: auth() runs the directives.
	 *
	 * @param array $args       title, intro, login_redirect (already validated).
	 * @param bool  $can_switch Whether to offer switching to the signup form.
	 */
	public static function panel( array $args, bool $can_switch ): string {
		$args = array_merge( self::defaults(), $args );

		OPENBB_Frontend::enqueue( array( OPENBB_Frontend::MODULE_LOGIN ) );

		$pending = OPENBB_REST_Login::pending();
		$wait    = $pending ? $pending['resend_after'] : 0;

		$context = array(
			'current'   => array(
				'form' => ! $pending,
				'code' => (bool) $pending,
			),
			'useCode'   => (bool) $pending,
			'busy'      => false,
			'notice'    => '',
			'status'    => '',
			'login'     => $pending ? $pending['payload']['login'] : '',
			'resendIn'  => $wait,
			'canResend' => 0 === $wait,
			'focus'     => false,
			'redirect'  => (string) $args['login_redirect'],
			'page'      => OPENBB_Frontend::current_url(),
		);

		OPENBB_Frontend::forms_state();
		wp_interactivity_state(
			'openbb/login',
			array(
				'restUrl'    => rest_url( OPENBB_REST_Controller::API_NAMESPACE . '/login' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'codeLength' => OPENBB_Email_Code::LENGTH,
				'messages'   => array(
					'fixErrors'    => __( 'Some details need attention.', 'open-bulk-buying' ),
					'network'      => __( 'Something went wrong. Check your connection and try again.', 'open-bulk-buying' ),
					'codeResent'   => __( 'We sent you a new code. Use the newest email.', 'open-bulk-buying' ),
					/* translators: %d: number of digits. */
					'codeRequired' => sprintf( __( 'Enter the %d-digit code from your email.', 'open-bulk-buying' ), OPENBB_Email_Code::LENGTH ),
				),
			)
		);

		return OPENBB_Frontend::template(
			'login',
			array(
				'id'           => self::ID,
				'title'        => (string) $args['title'],
				'intro'        => (string) $args['intro'],
				'heading_tag'  => '' !== $args['title'] ? 'h3' : 'h2',
				'can_switch'   => $can_switch,
				'codes'        => OPENBB_Login_Code::enabled(),
				'captcha'      => OPENBB_Captcha::field( OPENBB_Captcha::ACTION_LOGIN ),
				'captcha_code' => OPENBB_Login_Code::enabled() ? OPENBB_Captcha::field( OPENBB_Captcha::ACTION_LOGIN ) : '',
				'code_length'  => OPENBB_Email_Code::LENGTH,
				'code_minutes' => (int) ( OPENBB_Email_Code::TTL / MINUTE_IN_SECONDS ),
				'lost_url'     => wp_lostpassword_url( (string) $args['login_redirect'] ),
				'context'      => $context,
				'fields'       => array(
					'login'    => array(
						'name'         => 'login',
						'label'        => __( 'Email or username', 'open-bulk-buying' ),
						'kind'         => 'text',
						'required'     => true,
						'autocomplete' => 'username',
						'maxlength'    => 100,
						'value'        => $pending ? $pending['payload']['login'] : '',
						'id_prefix'    => self::ID,
					),
					'password' => array(
						'name'         => 'password',
						'label'        => __( 'Password', 'open-bulk-buying' ),
						'kind'         => 'password',
						'required'     => true,
						'autocomplete' => 'current-password',
						'id_prefix'    => self::ID,
					),
					'remember' => array(
						'name'      => 'remember',
						'label'     => __( 'Keep me logged in', 'open-bulk-buying' ),
						'kind'      => 'checkbox',
						'value'     => false,
						'id_prefix' => self::ID,
					),
				),
				'form_context' => array(
					'values' => array(
						'login'    => $pending ? $pending['payload']['login'] : '',
						'password' => '',
						'remember' => false,
					),
					'errors' => new stdClass(),
				),
			)
		);
	}
}

/**
 * Renders the login view (with switching to signup when signups are open).
 *
 * @param array $args title, intro (plain text), login_redirect (URL on this site; default: this page).
 */
function openbb_render_login( array $args = array() ): string {
	return OPENBB_Login_View::render( $args );
}
