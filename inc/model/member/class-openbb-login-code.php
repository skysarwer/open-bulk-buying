<?php
/**
 * Account policy and email delivery for passwordless login.
 * Challenge generation, storage and verification live in OPENBB_Email_Code.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Login_Code {

	public static function enabled(): bool {
		return (bool) OPENBB_Settings::get_general()['allow_login_codes'];
	}

	public static function allowed( int $user_id ): bool {
		$user = self::account( $user_id );
		if ( ! $user ) {
			return false;
		}

		// Keep existing site-specific authentication restrictions during the transition.
		$allowed = (bool) apply_filters( 'openbb_login_link_allowed', ! self::privileged( $user ), $user );
		/**
		 * Filters whether a user may log in with an emailed code. Sites with additional
		 * authentication requirements must exclude affected accounts here.
		 *
		 * @param bool    $allowed Defaults to false for editors, administrators and super admins.
		 * @param WP_User $user
		 */
		return (bool) apply_filters( 'openbb_login_code_allowed', $allowed, $user );
	}

	public static function usable( int $user_id ): bool {
		return null !== self::account( $user_id );
	}

	public static function privileged( WP_User $user ): bool {
		return user_can( $user, 'edit_others_posts' ) || is_super_admin( $user->ID );
	}

	public static function cooldown( int $user_id ): int {
		return max( 0, (int) get_transient( self::user_key( $user_id ) ) + OPENBB_Email_Code::RESEND_COOLDOWN - time() );
	}

	/** @return true|WP_Error */
	public static function mark_sent( int $user_id ) {
		if ( ! set_transient( self::user_key( $user_id ), time(), OPENBB_Email_Code::RESEND_COOLDOWN ) ) {
			return new WP_Error( 'openbb_code_failed', __( 'The code could not be created. Please try again.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}
		return true;
	}

	public static function send( WP_User $user, string $code ): bool {
		$template = OPENBB_Emails::render(
			'login-code',
			array(
				'code'    => OPENBB_Email_Code::format( $code ),
				'minutes' => (int) ( OPENBB_Email_Code::TTL / MINUTE_IN_SECONDS ),
			)
		);

		return self::mail( $user, $template, $code, true );
	}

	public static function send_password_required( WP_User $user, string $page ): bool {
		return self::mail( $user, OPENBB_Emails::render( 'password-required', array( 'page' => $page ) ), $page );
	}

	private static function mail( WP_User $user, array $template, string $code_or_page, bool $is_code = false ): bool {
		/**
		 * Filters the login email.
		 *
		 * @param array   $email        to, subject, message, headers.
		 * @param WP_User $user
		 * @param string  $code_or_page The code, or login page for password-required notices.
		 */
		$email = apply_filters(
			'openbb_login_email',
			array(
				'to'      => $user->user_email,
				'subject' => $template['subject'],
				'message' => $template['message'],
				'headers' => $is_code ? 'Content-Type: text/html; charset=UTF-8' : '',
			),
			$user,
			$code_or_page
		);

		return wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}

	private static function account( int $user_id ): ?WP_User {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user && ! ( is_multisite() && ! empty( $user->spam ) ) ? $user : null;
	}

	private static function user_key( int $user_id ): string {
		return 'openbb_login_code_user_' . $user_id;
	}
}
