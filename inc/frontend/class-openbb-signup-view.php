<?php
/**
 * The signup view: [openbb_signup] and openbb_render_signup().
 *
 * One template serves every "you need to be a member" screen: pass a title, intro and
 * login_redirect (e.g. the order form shows it to visitors and non-members). What it shows:
 *
 * - Signed-in users with a membership status: their status (applicant, active, inactive, declined).
 * - Signups closed: a notice (with a Settings link for staff).
 * - Everyone else: the form. Steps are Details → Profile & rules → Code → Done; the
 *   profile step is left out when there are no profile fields or rules, and signed-in
 *   users skip the code step. Values a signed-in user already has are shown locked.
 *
 * Visitors can switch to the login form in place (see OPENBB_Login_View::auth()).
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Signup_View {

	const ID = 'openbb-signup';

	/**
	 * login_redirect: where to go after logging in or signing up (URL on this site; default:
	 * this page). redirect_to is its older name, used when login_redirect is empty.
	 */
	public static function defaults(): array {
		return array(
			'title'          => '',
			'intro'          => '',
			'login_redirect' => '',
			'redirect_to'    => '',
		);
	}

	public static function render( array $args = array() ): string {
		$args = array_merge( self::defaults(), array_intersect_key( $args, self::defaults() ) );

		OPENBB_Frontend::no_cache();
		OPENBB_Frontend::enqueue_style();

		$redirect            = '' !== (string) $args['login_redirect'] ? (string) $args['login_redirect'] : (string) $args['redirect_to'];
		$args['redirect_to'] = OPENBB_Frontend::redirect_url( $redirect );

		$user_id = get_current_user_id();
		$status  = $user_id ? OPENBB_Access::get_status( $user_id ) : '';
		if ( '' !== $status ) {
			return self::message( $args, self::status_message( $status ), $args['redirect_to'] );
		}

		$open = OPENBB_Signup::is_open();
		if ( $user_id ) {
			return $open ? OPENBB_Frontend::process( self::form( $args, $user_id ) ) : self::message( $args, self::closed_message() );
		}

		return OPENBB_Login_View::auth(
			'signup',
			array(
				'login'  => OPENBB_Login_View::panel( array( 'login_redirect' => $args['redirect_to'] ), $open ),
				'signup' => $open ? self::form( $args, 0 ) : self::message( $args, self::closed_message(), '', true ),
			)
		);
	}

	/**
	 * The signup form for a visitor, as the second panel of the login view. Unprocessed:
	 * the caller runs the directives over the whole view.
	 *
	 * @param string $redirect     Where the done step's Continue link goes.
	 * @param bool   $auto_continue Go to $redirect right after signing up instead of
	 *                              showing the done step (the order form reloads into
	 *                              the new member's form or application status).
	 */
	public static function panel( string $redirect, bool $auto_continue = false ): string {
		return self::form(
			array_merge(
				self::defaults(),
				array(
					'redirect_to'   => $redirect,
					'auto_continue' => $auto_continue,
				)
			),
			0
		);
	}

	private static function form( array $args, int $user_id ): string {
		OPENBB_Frontend::enqueue( array( OPENBB_Frontend::MODULE_SIGNUP ) );

		return OPENBB_Frontend::template( 'signup', self::form_view( $args, $user_id ) );
	}

	/**
	 * Everything the form template needs.
	 */
	private static function form_view( array $args, int $user_id ): array {
		$pending = $user_id ? null : OPENBB_REST_Signup::pending();
		$saved   = $pending ? $pending['payload'] : array();

		$account_fields = self::account_fields( $user_id, (array) ( $saved['account'] ?? array() ) );
		$profile_fields = self::profile_fields( $user_id, (array) ( $saved['profile'] ?? array() ) );
		$rules          = OPENBB_Rules::get_latest();

		$rules_field = null;
		if ( $rules ) {
			$rules_field = array(
				'name'      => OPENBB_Signup::RULES_FIELD,
				'kind'      => 'checkbox',
				'label'     => __( 'I have read the co-op rules and agree to follow them.', 'open-bulk-buying' ),
				'required'  => true,
				'value'     => (int) ( $saved['rules_version'] ?? 0 ) === $rules->id,
				'id_prefix' => self::ID,
			);
		}

		$form_steps = array( 'details' );
		if ( $profile_fields || $rules ) {
			$form_steps[] = 'profile';
		}
		$steps = array_merge( $form_steps, $user_id ? array() : array( 'code' ), array( 'done' ) );

		$values = array();
		foreach ( array_merge( $account_fields, $profile_fields, $rules_field ? array( $rules_field ) : array() ) as $field ) {
			$values[ $field['name'] ] = $field['value'];
		}
		$language = openbb_current_language();
		if ( ! isset( $values['language'] ) ) {
			$values['language'] = $language;
		}

		$current = $pending ? 'code' : 'details';

		$labels = array(
			'details' => __( 'Your details', 'open-bulk-buying' ),
			'profile' => $profile_fields ? __( 'About you', 'open-bulk-buying' ) : __( 'Co-op rules', 'open-bulk-buying' ),
			'code'    => __( 'Confirm your email', 'open-bulk-buying' ),
		);

		$context = array(
			'current'      => array_fill_keys( $steps, false ),
			'steps'        => $form_steps,
			'busy'         => false,
			'notice'       => '',
			'status'       => '',
			'email'        => (string) ( $pending['email'] ?? '' ),
			'resendIn'     => (int) ( $pending['resend_after'] ?? 0 ),
			'canResend'    => $pending && $pending['resend_after'] <= 0,
			'applicant'    => OPENBB_Access::STATUS_APPLICANT === OPENBB_Signup::signup_status(),
			'focus'        => false,
			'rulesVersion' => $rules ? $rules->id : 0,
			'continueTo'   => empty( $args['auto_continue'] ) ? '' : $args['redirect_to'],
		);
		$context['current'][ $current ] = true;

		OPENBB_Frontend::forms_state();
		wp_interactivity_state(
			'openbb/signup',
			array(
				'restUrl'       => rest_url( OPENBB_REST_Controller::API_NAMESPACE . '/signup' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'accountFields' => OPENBB_Signup::ACCOUNT_FIELDS,
				'rulesField'    => OPENBB_Signup::RULES_FIELD,
				'honeypot'      => OPENBB_REST_Signup::HONEYPOT,
				'codeLength'    => OPENBB_Email_Code::LENGTH,
				'messages'      => array(
					'fixErrors'    => __( 'Some details need attention.', 'open-bulk-buying' ),
					'network'      => __( 'Something went wrong. Check your connection and try again.', 'open-bulk-buying' ),
					'codeRequired' => sprintf(
						/* translators: %d: number of digits in the code. */
						__( 'Enter the %d-digit code from the email.', 'open-bulk-buying' ),
						OPENBB_Email_Code::LENGTH
					),
					'codeResent'   => __( 'We sent you a new code.', 'open-bulk-buying' ),
				),
			)
		);

		return array(
			'id'             => self::ID,
			'title'          => (string) $args['title'],
			'intro'          => (string) $args['intro'],
			'redirect_to'    => $args['redirect_to'],
			'heading_tag'    => '' !== $args['title'] ? 'h3' : 'h2',
			'logged_in_as'   => $user_id ? wp_get_current_user()->user_email : '',
			'has_locked'     => (bool) array_filter( array_column( array_merge( $account_fields, $profile_fields ), 'locked' ) ),
			'can_switch'     => ! $user_id,
			'steps'          => $steps,
			'form_steps'     => $form_steps,
			'labels'         => $labels,
			'account_fields' => $account_fields,
			'profile_fields' => $profile_fields,
			'rules'          => $rules ? $rules->get_content( $language ) : '',
			'rules_field'    => $rules_field,
			'fee'            => self::fee_notice(),
			'submit_label'   => $user_id ? __( 'Join the co-op', 'open-bulk-buying' ) : __( 'Sign up', 'open-bulk-buying' ),
			'code_length'    => OPENBB_Email_Code::LENGTH,
			'code_minutes'   => (int) ( OPENBB_Email_Code::TTL / MINUTE_IN_SECONDS ),
			'honeypot'       => OPENBB_REST_Signup::HONEYPOT,
			// Logged-in people joining don't get an email code, so they skip bot protection.
			'captcha'        => $user_id ? '' : OPENBB_Captcha::field( OPENBB_Captcha::ACTION_SIGNUP ),
			'context'        => $context,
			'form_context'   => array(
				'values' => $values,
				'errors' => new stdClass(),
			),
		);
	}

	private static function account_fields( int $user_id, array $saved ): array {
		$existing = OPENBB_Signup::existing_account( $user_id );

		$fields = array(
			array(
				'name'         => 'first_name',
				'label'        => __( 'First name', 'open-bulk-buying' ),
				'kind'         => 'text',
				'required'     => true,
				'autocomplete' => 'given-name',
				'maxlength'    => 100,
			),
			array(
				'name'         => 'last_name',
				'label'        => __( 'Last name', 'open-bulk-buying' ),
				'kind'         => 'text',
				'autocomplete' => 'family-name',
				'maxlength'    => 100,
			),
			array(
				'name'         => 'email',
				'label'        => __( 'Email', 'open-bulk-buying' ),
				'kind'         => 'email',
				'required'     => true,
				'autocomplete' => 'email',
				'maxlength'    => 100,
				'help'         => $user_id ? '' : __( 'We\'ll send a code to this address to confirm it\'s yours.', 'open-bulk-buying' ),
			),
			array(
				'name'         => 'phone',
				'label'        => __( 'Phone', 'open-bulk-buying' ),
				'kind'         => 'tel',
				'autocomplete' => 'tel',
				'maxlength'    => 30,
			),
		);

		$languages = self::language_options();
		if ( count( $languages ) > 1 ) {
			$fields[] = array(
				'name'   => 'language',
				'label'  => __( 'Preferred language', 'open-bulk-buying' ),
				'kind'   => 'select',
				'groups' => array(
					array(
						'label'   => null,
						'options' => $languages,
					),
				),
				'help'   => __( 'For emails from the co-op.', 'open-bulk-buying' ),
			);
		}

		foreach ( $fields as &$field ) {
			$name               = $field['name'];
			$field['id_prefix'] = self::ID;
			$field['locked']    = isset( $existing[ $name ] );
			$field['value']     = $existing[ $name ] ?? ( is_scalar( $saved[ $name ] ?? null ) ? (string) $saved[ $name ] : '' );
			if ( 'language' === $name && '' === $field['value'] ) {
				$field['value'] = openbb_current_language();
			}
		}
		unset( $field );

		return $fields;
	}

	private static function profile_fields( int $user_id, array $saved ): array {
		$existing = OPENBB_Signup::existing_profile( $user_id );
		$fields   = array();

		foreach ( OPENBB_Profile_Fields::get_fields_for_context( OPENBB_Profile_Fields::CONTEXT_SIGNUP ) as $key => $definition ) {
			$locked   = array_key_exists( $key, $existing );
			$value    = $locked ? $existing[ $key ] : ( $saved[ $key ] ?? null );
			$fields[] = openbb_profile_field_input(
				$definition,
				$value,
				array(
					'locked'    => $locked,
					'id_prefix' => self::ID,
				)
			);
		}

		return $fields;
	}

	/**
	 * Site languages as select options, labelled in their own language when the
	 * multilingual plugin provides names.
	 */
	private static function language_options(): array {
		$details = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$options = array();
		foreach ( openbb_languages() as $code ) {
			$name      = is_array( $details[ $code ] ?? null ) ? ( $details[ $code ]['native_name'] ?? '' ) : '';
			$options[] = array(
				'value' => $code,
				'label' => '' !== $name ? $name : strtoupper( $code ),
			);
		}

		return $options;
	}

	private static function fee_notice(): string {
		$settings = OPENBB_Settings::get_general();
		$amount   = (int) $settings['membership_fee_amount'];
		if ( $amount <= 0 ) {
			return '';
		}

		$money = openbb_format_money( $amount );
		if ( 'recurring' !== $settings['membership_fee_type'] ) {
			/* translators: %s: amount, e.g. $25.00. */
			return sprintf( __( 'Membership has a one-time fee of %s.', 'open-bulk-buying' ), $money );
		}

		$interval = max( 1, (int) $settings['membership_fee_order_interval'] );

		return 1 === $interval
			/* translators: %s: amount, e.g. $25.00. */
			? sprintf( __( 'Membership has a fee of %s, charged with each order.', 'open-bulk-buying' ), $money )
			/* translators: 1: amount, e.g. $25.00; 2: number of orders. */
			: sprintf( _n( 'Membership has a fee of %1$s, charged every %2$d order.', 'Membership has a fee of %1$s, charged every %2$d orders.', $interval, 'open-bulk-buying' ), $money, $interval );
	}

	private static function status_message( string $status ): string {
		switch ( $status ) {
			case OPENBB_Access::STATUS_APPLICANT:
				return __( 'Thanks for signing up! The co-op will review your application and email you once you\'re approved.', 'open-bulk-buying' );
			case OPENBB_Access::STATUS_DECLINED:
				return __( 'Your membership application wasn\'t approved. Contact the co-op if you have questions.', 'open-bulk-buying' );
			case OPENBB_Access::STATUS_INACTIVE:
				return __( 'Your membership is inactive. Contact the co-op if you\'d like to rejoin.', 'open-bulk-buying' );
			default:
				return __( 'You\'re a member of the co-op.', 'open-bulk-buying' );
		}
	}

	private static function closed_message(): string {
		$message = esc_html__( 'Signups are closed right now.', 'open-bulk-buying' );
		if ( current_user_can( 'openbb_access_settings' ) ) {
			$message .= ' ' . sprintf(
				'<a href="%s">%s</a>',
				esc_url( OPENBB_Admin::screen_url( 'settings' ) ),
				esc_html__( 'Open signups in the co-op settings.', 'open-bulk-buying' )
			);
		}

		return $message;
	}

	/**
	 * A static notice in the signup frame. $html must be escaped already. With $can_switch,
	 * visitors get a button to switch to the login form.
	 */
	private static function message( array $args, string $html, string $continue_url = '', bool $can_switch = false ): string {
		return OPENBB_Frontend::template(
			'signup-message',
			array(
				'title'        => (string) $args['title'],
				'message'      => $html,
				'continue_url' => $continue_url !== OPENBB_Frontend::current_url() ? $continue_url : '',
				'can_switch'   => $can_switch,
			)
		);
	}
}

/**
 * Renders the signup view anywhere a member-only screen needs it.
 *
 * @param array $args title, intro (plain text), login_redirect (URL on this site to go to
 *                    after logging in or signing up; default: this page).
 */
function openbb_render_signup( array $args = array() ): string {
	return OPENBB_Signup_View::render( $args );
}
