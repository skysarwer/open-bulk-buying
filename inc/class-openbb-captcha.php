<?php
/**
 * Bot protection for the public signup and login forms.
 *
 * Views print field() inside their forms; REST routes call verify() before sending email
 * or checking a password. Cloudflare Turnstile is built in (Co-op > Settings > General).
 * Other providers can plug in with the openbb_captcha_field and openbb_captcha_verify
 * filters.
 *
 * The browser sends every form control inside the widget wrapper as the `captcha` object
 * of the REST body (Turnstile adds a hidden cf-turnstile-response input), and resets the
 * widget after each response by dispatching `openbb:captcha-reset` on the wrapper
 * (assets/js/captcha.js).
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Captcha {

	const PROVIDER_NONE      = 'none';
	const PROVIDER_TURNSTILE = 'turnstile';
	const PROVIDERS          = array( self::PROVIDER_NONE, self::PROVIDER_TURNSTILE );

	/** Option holding the Turnstile secret key (kept out of the general settings). */
	const SECRET_OPTION = 'openbb_turnstile_secret';

	/** wp-config.php constant that, when defined, overrides the stored secret. */
	const SECRET_CONSTANT = 'OPENBB_TURNSTILE_SECRET';

	const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	const TURNSTILE_FIELD      = 'cf-turnstile-response';

	/** Form names, sent to Turnstile as the widget action. */
	const ACTION_SIGNUP = 'signup';
	const ACTION_LOGIN  = 'login';

	/**
	 * Whether the built-in provider is chosen and has both keys.
	 */
	public static function enabled(): bool {
		$general = OPENBB_Settings::get_general();

		return self::PROVIDER_TURNSTILE === $general['captcha_provider']
			&& '' !== (string) $general['turnstile_site_key']
			&& '' !== self::secret();
	}

	public static function secret(): string {
		if ( defined( self::SECRET_CONSTANT ) ) {
			return (string) constant( self::SECRET_CONSTANT );
		}

		return (string) get_option( self::SECRET_OPTION, '' );
	}

	public static function secret_from_constant(): bool {
		return defined( self::SECRET_CONSTANT );
	}

	/**
	 * The widget for the form named $action, wrapped for assets/js/captcha.js; '' when
	 * there's no bot protection. Enqueues what the widget needs.
	 */
	public static function field( string $action ): string {
		$html = '';
		if ( self::enabled() ) {
			OPENBB_Frontend::enqueue( array( OPENBB_Frontend::MODULE_CAPTCHA ) );
			$html = sprintf(
				'<div class="openbb-captcha__turnstile" data-openbb-turnstile data-sitekey="%1$s" data-action="%2$s"></div>',
				esc_attr( (string) OPENBB_Settings::get_general()['turnstile_site_key'] ),
				esc_attr( $action )
			);
		}

		/**
		 * Filters the bot protection widget printed in a public form. Return markup for
		 * another provider's widget; its form controls are sent to the REST route as the
		 * `captcha` object, checked with the openbb_captcha_verify filter.
		 *
		 * @param string $html   The widget; '' when the built-in provider is off.
		 * @param string $action The form: signup or login.
		 */
		$html = (string) apply_filters( 'openbb_captcha_field', $html, $action );

		return '' !== $html
			? '<div class="openbb-captcha" data-openbb-captcha data-wp-interactive="openbb/captcha" data-wp-init="callbacks.init">' . $html . '</div>'
			: '';
	}

	/**
	 * Checks the widget response sent with $request for the form named $action.
	 *
	 * @return true|WP_Error
	 */
	public static function verify( WP_REST_Request $request, string $action ) {
		$fields = array();
		foreach ( (array) $request['captcha'] as $name => $value ) {
			if ( is_string( $name ) && is_scalar( $value ) ) {
				$fields[ $name ] = (string) $value;
			}
		}

		/**
		 * Short-circuits bot protection, e.g. for another provider. Return true to accept,
		 * a WP_Error to refuse, or null to use the built-in provider.
		 *
		 * @param true|WP_Error|null $result
		 * @param array              $fields  The widget's form controls, name => value.
		 * @param string             $action  The form: signup or login.
		 * @param WP_REST_Request    $request
		 */
		$result = apply_filters( 'openbb_captcha_verify', null, $fields, $action, $request );
		if ( null !== $result ) {
			return is_wp_error( $result ) ? $result : ( true === $result ? true : self::failed() );
		}

		if ( ! self::enabled() ) {
			return true;
		}

		return self::verify_turnstile( $fields[ self::TURNSTILE_FIELD ] ?? '', $action );
	}

	/**
	 * @return true|WP_Error
	 */
	private static function verify_turnstile( string $token, string $action ) {
		// Turnstile tokens are at most 2048 characters.
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return self::failed();
		}

		$body = array(
			'secret'   => self::secret(),
			'response' => $token,
		);
		$ip   = OPENBB_Rate_Limit::client_ip();
		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			self::TURNSTILE_VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);
		$code     = wp_remote_retrieve_response_code( $response );
		$data     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_wp_error( $response ) || 200 !== $code || ! is_array( $data ) ) {
			self::log( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code );
			return self::unavailable();
		}

		$errors = array_map( 'strval', (array) ( $data['error-codes'] ?? array() ) );
		if ( empty( $data['success'] ) ) {
			if ( array_intersect( $errors, array( 'missing-input-secret', 'invalid-input-secret' ) ) ) {
				self::log( implode( ', ', $errors ) );
				return self::unavailable();
			}
			if ( in_array( 'timeout-or-duplicate', $errors, true ) ) {
				return new WP_Error( 'openbb_captcha_failed', __( 'The verification expired. Please try again.', 'open-bulk-buying' ), array( 'status' => 403 ) );
			}
			return self::failed();
		}

		// A token from another form's widget isn't accepted. Test keys report no action.
		$reported = (string) ( $data['action'] ?? '' );
		if ( '' !== $reported && $reported !== $action ) {
			return self::failed();
		}

		return true;
	}

	private static function failed(): WP_Error {
		return new WP_Error( 'openbb_captcha_failed', __( 'Please complete the verification and try again.', 'open-bulk-buying' ), array( 'status' => 403 ) );
	}

	private static function unavailable(): WP_Error {
		return new WP_Error( 'openbb_captcha_unavailable', __( 'We couldn\'t check that you\'re not a bot. Please try again later, or contact the co-op.', 'open-bulk-buying' ), array( 'status' => 503 ) );
	}

	private static function log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[open-bulk-buying] Turnstile verification failed: ' . $message );
	}
}
