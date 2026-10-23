<?php
/**
 * The login view: [openbb_login], and the login panel of the signup view.
 *
 * Visitors log in with their email or username and password, or ask for a single-use
 * login link by email (OPENBB_Login_Link). A link opens the page it was asked from, which
 * shows a "Log in" button to confirm. Signed-in users see who they're logged in as.
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
	 * A login link always opens on the login panel. Returns processed markup.
	 *
	 * @param string   $default login or signup.
	 * @param string[] $panels  Unprocessed markup keyed by login and signup; either may be missing.
	 */
	public static function auth( string $default, array $panels ): string {
		$panels = array_filter( array_intersect_key( $panels, array_flip( array( 'login', 'signup' ) ) ) );
		if ( isset( $panels['login'] ) && '' !== self::link_token() ) {
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

		$token = self::link_token();
		$link  = '' !== $token ? OPENBB_Login_Link::peek( $token ) : null;

		$context = array(
			'current'   => array(
				'form'    => ! $link,
				'sent'    => false,
				'confirm' => (bool) $link,
			),
			'link'      => '' !== $token,
			'busy'      => false,
			'notice'    => '' !== $token && ! $link ? __( 'This login link has expired or was already used. Ask for a new one below.', 'open-bulk-buying' ) : '',
			'status'    => '',
			'login'     => '',
			'token'     => $link ? $token : '',
			'resendIn'  => 0,
			'canResend' => true,
			'focus'     => false,
			'redirect'  => (string) $args['login_redirect'],
			'page'      => OPENBB_Frontend::current_url(),
		);

		OPENBB_Frontend::forms_state();
		wp_interactivity_state(
			'openbb/login',
			array(
				'restUrl'   => rest_url( OPENBB_REST_Controller::API_NAMESPACE . '/login' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'queryArg'  => OPENBB_Login_Link::QUERY_ARG,
				'messages'  => array(
					'fixErrors'  => __( 'Some details need attention.', 'open-bulk-buying' ),
					'network'    => __( 'Something went wrong. Check your connection and try again.', 'open-bulk-buying' ),
					'linkResent' => __( 'We sent you a new link.', 'open-bulk-buying' ),
				),
			)
		);

		$link_user = $link ? get_userdata( $link['user_id'] ) : false;

		return OPENBB_Frontend::template(
			'login',
			array(
				'id'           => self::ID,
				'title'        => (string) $args['title'],
				'intro'        => (string) $args['intro'],
				'heading_tag'  => '' !== $args['title'] ? 'h3' : 'h2',
				'can_switch'   => $can_switch,
				'links'        => OPENBB_Login_Link::enabled(),
				'link_email'   => $link_user ? $link_user->user_email : '',
				'link_minutes' => (int) ( OPENBB_Login_Link::TTL / MINUTE_IN_SECONDS ),
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
						'login'    => '',
						'password' => '',
						'remember' => false,
					),
					'errors' => new stdClass(),
				),
			)
		);
	}

	/**
	 * The login link token in the current request, if any (ignored when links are off).
	 */
	private static function link_token(): string {
		if ( ! OPENBB_Login_Link::enabled() ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is its own proof; it's only used after the person confirms.
		$token = isset( $_GET[ OPENBB_Login_Link::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ OPENBB_Login_Link::QUERY_ARG ] ) ) : '';

		return is_string( $token ) ? $token : '';
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
