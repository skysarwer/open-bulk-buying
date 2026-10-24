<?php
/**
 * Short-lived, single-use codes sent by email to prove someone controls an address.
 * Signup and passwordless login share purpose-scoped challenges.
 *
 * A challenge is identified by a random token that the REST layer keeps in an HttpOnly
 * cookie, so a code is only accepted from the browser that requested it. Only an HMAC of
 * the code is stored, codes expire, attempts and resends are capped, and a successful
 * verification deletes the challenge. Callers send the email themselves, so each purpose
 * can word it differently (or send a different email altogether, e.g. "you already have
 * an account", without revealing that to the requester).
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Email_Code {

	const PURPOSE_SIGNUP = 'signup';
	const PURPOSE_LOGIN  = 'login';
	const PURPOSES       = array( self::PURPOSE_SIGNUP, self::PURPOSE_LOGIN );

	const LENGTH          = 6;
	const TTL             = 15 * MINUTE_IN_SECONDS;
	const MAX_ATTEMPTS    = 5;
	const MAX_SENDS       = 5;
	const RESEND_COOLDOWN = 60;

	/**
	 * Starts a challenge for $email. $payload is kept server-side with it (e.g. the
	 * validated signup form) and returned by verify(). With $deliverable false a code is
	 * still generated, but it isn't meant to be sent and cannot be verified.
	 * The response looks the same either way.
	 *
	 * @return array{token: string, code: string, resend_after: int}|WP_Error
	 */
	public static function issue( string $purpose, string $email, array $payload = array(), bool $deliverable = true ) {
		if ( ! in_array( $purpose, self::PURPOSES, true ) ) {
			return new WP_Error( 'openbb_code_purpose', __( 'This code can\'t be used here.', 'open-bulk-buying' ) );
		}

		$token     = wp_generate_password( 40, false, false );
		$code      = self::generate_code();
		$challenge = array(
			'purpose'     => $purpose,
			'email'       => $email,
			'payload'     => $payload,
			'deliverable' => $deliverable,
			'code_hash'   => self::hash_code( $code, $token ),
			'attempts'    => 0,
			'sends'       => 1,
			'sent_at'     => time(),
			'expires_at'  => time() + self::TTL,
		);
		if ( ! self::save( $token, $challenge ) ) {
			return self::failed();
		}

		return array(
			'token'        => $token,
			'code'         => $code,
			'resend_after' => self::RESEND_COOLDOWN,
		);
	}

	/**
	 * A summary of a pending challenge for the UI, or null if it's missing, expired or for another purpose.
	 *
	 * @return array{email: string, deliverable: bool, payload: array, resend_after: int}|null
	 */
	public static function get( string $purpose, string $token ): ?array {
		$challenge = self::load( $purpose, $token );
		if ( ! $challenge ) {
			return null;
		}

		return array(
			'email'        => $challenge['email'],
			'deliverable'  => $challenge['deliverable'],
			'payload'      => $challenge['payload'],
			'resend_after' => self::resend_after( $challenge ),
		);
	}

	/**
	 * A new code for the same challenge, after the cooldown. Resets the attempt count but
	 * not the expiry, so the challenge can't be kept alive forever. Login may recheck
	 * delivery eligibility (e.g. after an account-wide cooldown); signup leaves it unchanged.
	 *
	 * @return array{code: string, email: string, deliverable: bool, payload: array, resend_after: int}|WP_Error
	 */
	public static function resend( string $purpose, string $token, ?bool $deliverable = null ) {
		$challenge = self::load( $purpose, $token );
		if ( ! $challenge ) {
			return self::expired();
		}
		$wait = self::resend_after( $challenge );
		if ( $wait > 0 ) {
			return new WP_Error(
				'openbb_code_cooldown',
				/* translators: %d: number of seconds. */
				sprintf( _n( 'Please wait %d second before asking for another code.', 'Please wait %d seconds before asking for another code.', $wait, 'open-bulk-buying' ), $wait ),
				array(
					'status'       => 429,
					'resend_after' => $wait,
				)
			);
		}
		if ( $challenge['sends'] >= self::MAX_SENDS ) {
			return new WP_Error( 'openbb_code_too_many_sends', __( 'Too many codes were sent. Please start again.', 'open-bulk-buying' ), array( 'status' => 429 ) );
		}

		$code                   = self::generate_code();
		$challenge['code_hash'] = self::hash_code( $code, $token );
		$challenge['attempts']  = 0;
		$challenge['sends']    += 1;
		$challenge['sent_at']   = time();
		if ( null !== $deliverable ) {
			$challenge['deliverable'] = $deliverable;
		}
		if ( ! self::save( $token, $challenge ) ) {
			return self::failed();
		}

		return array(
			'code'         => $code,
			'email'        => $challenge['email'],
			'deliverable'  => $challenge['deliverable'],
			'payload'      => $challenge['payload'],
			'resend_after' => self::RESEND_COOLDOWN,
		);
	}

	/**
	 * Checks $code. On success the challenge is consumed and its email and payload returned.
	 *
	 * @return array{email: string, payload: array}|WP_Error
	 */
	public static function verify( string $purpose, string $token, string $code ) {
		$challenge = self::load( $purpose, $token );
		if ( ! $challenge ) {
			return self::expired();
		}
		if ( $challenge['attempts'] >= self::MAX_ATTEMPTS ) {
			return self::too_many_attempts();
		}

		$code = preg_replace( '/\D/', '', $code );
		if ( ! $challenge['deliverable'] || ! hash_equals( $challenge['code_hash'], self::hash_code( $code, $token ) ) ) {
			$challenge['attempts'] += 1;
			if ( ! self::save( $token, $challenge ) ) {
				return self::failed();
			}
			if ( $challenge['attempts'] >= self::MAX_ATTEMPTS ) {
				return self::too_many_attempts();
			}
			return new WP_Error(
				'openbb_code_invalid',
				__( 'That code isn\'t right. Check the email and try again.', 'open-bulk-buying' ),
				array(
					'status'    => 400,
					'remaining' => self::MAX_ATTEMPTS - $challenge['attempts'],
				)
			);
		}

		self::discard( $token );

		return array(
			'email'   => $challenge['email'],
			'payload' => $challenge['payload'],
		);
	}

	public static function discard( string $token ): void {
		if ( '' !== $token ) {
			delete_transient( self::key( $token ) );
		}
	}

	public static function token( string $purpose, ?WP_REST_Request $request = null ): string {
		$cookie = 'openbb_' . $purpose;
		if ( isset( $_COOKIE[ $cookie ] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) );
		}
		// Internal requests (e.g. tests) have no superglobal cookie.
		$cookies = $request ? $request->get_header( 'cookie' ) : '';
		if ( $cookies && preg_match( '/(?:^|;\s*)' . preg_quote( $cookie, '/' ) . '=([A-Za-z0-9]+)/', $cookies, $match ) ) {
			return $match[1];
		}
		return '';
	}

	public static function set_cookie( string $purpose, string $token, int $ttl ): void {
		$cookie = 'openbb_' . $purpose;
		if ( '' === $token ) {
			unset( $_COOKIE[ $cookie ] );
		} else {
			$_COOKIE[ $cookie ] = $token;
		}
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$cookie,
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
	 * "Your code is 123 456"-style display, easier to read and type.
	 */
	public static function format( string $code ): string {
		return trim( chunk_split( $code, 3, ' ' ) );
	}

	private static function generate_code(): string {
		return str_pad( (string) random_int( 0, 10 ** self::LENGTH - 1 ), self::LENGTH, '0', STR_PAD_LEFT );
	}

	private static function hash_code( string $code, string $token ): string {
		return hash_hmac( 'sha256', $code, wp_salt( 'nonce' ) . $token );
	}

	private static function key( string $token ): string {
		return 'openbb_code_' . hash( 'sha256', $token );
	}

	private static function load( string $purpose, string $token ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9]{40}$/', $token ) ) {
			return null;
		}
		$challenge = get_transient( self::key( $token ) );
		if ( ! is_array( $challenge ) || ( $challenge['purpose'] ?? '' ) !== $purpose || (int) ( $challenge['expires_at'] ?? 0 ) <= time() ) {
			return null;
		}

		return $challenge;
	}

	private static function save( string $token, array $challenge ): bool {
		$ttl = (int) $challenge['expires_at'] - time();

		return $ttl > 0 && set_transient( self::key( $token ), $challenge, $ttl );
	}

	private static function resend_after( array $challenge ): int {
		return max( 0, (int) $challenge['sent_at'] + self::RESEND_COOLDOWN - time() );
	}

	private static function expired(): WP_Error {
		return new WP_Error( 'openbb_code_expired', __( 'This code has expired. Please start again.', 'open-bulk-buying' ), array( 'status' => 410 ) );
	}

	private static function too_many_attempts(): WP_Error {
		return new WP_Error( 'openbb_code_locked', __( 'Too many incorrect codes. Ask for a new code.', 'open-bulk-buying' ), array( 'status' => 429 ) );
	}

	private static function failed(): WP_Error {
		return new WP_Error( 'openbb_code_failed', __( 'The code could not be created. Please try again.', 'open-bulk-buying' ), array( 'status' => 500 ) );
	}
}
