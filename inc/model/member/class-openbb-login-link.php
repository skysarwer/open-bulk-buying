<?php
/**
 * Single-use login links sent by email ("email me a login link").
 *
 * Unlike signup codes (OPENBB_Email_Code), a link isn't bound to the browser that asked for
 * it, so it can be opened on another device. The link leads to the page with the login
 * view, which asks the person to confirm before the token is used; mail scanners that
 * open links can't spend it.
 *
 * Only a hash of the token is stored. Links expire, each user has at most one live link
 * (asking again replaces it), and a link is deleted once used.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Login_Link {

	/** Query argument carrying the token in the emailed link. */
	const QUERY_ARG = 'openbb_login';

	const TTL             = 15 * MINUTE_IN_SECONDS;
	const RESEND_COOLDOWN = 60;

	private const TOKEN_LENGTH = 43;

	/**
	 * Creates a link for $user_id, replacing any earlier one. $redirect is where the person
	 * goes once logged in; $page is the page with the login view the link opens.
	 *
	 * @return array{token: string, url: string}|WP_Error
	 */
	public static function issue( int $user_id, string $redirect, string $page ) {
		self::revoke( $user_id );

		$token = wp_generate_password( self::TOKEN_LENGTH, false, false );
		$link  = array(
			'user_id'    => $user_id,
			'redirect'   => $redirect,
			'expires_at' => time() + self::TTL,
		);
		if ( ! set_transient( self::key( $token ), $link, self::TTL ) || ! set_transient( self::user_key( $user_id ), array( 'key' => self::key( $token ), 'sent_at' => time() ), self::TTL ) ) {
			delete_transient( self::key( $token ) );
			return new WP_Error( 'openbb_login_link_failed', __( 'The login link could not be created. Please try again.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}

		return array(
			'token' => $token,
			'url'   => add_query_arg( self::QUERY_ARG, $token, $page ),
		);
	}

	/**
	 * Seconds until $user_id may be sent another link; 0 if now.
	 */
	public static function cooldown( int $user_id ): int {
		$current = get_transient( self::user_key( $user_id ) );

		return is_array( $current ) ? max( 0, (int) ( $current['sent_at'] ?? 0 ) + self::RESEND_COOLDOWN - time() ) : 0;
	}

	/**
	 * Whether login links are turned on (Co-op > Settings > General).
	 */
	public static function enabled(): bool {
		return (bool) OPENBB_Settings::get_general()['allow_login_links'];
	}

	/**
	 * The live link for $token without using it, or null if it's unknown, expired, replaced
	 * or its user is gone, or links are turned off.
	 *
	 * @return array{user_id: int, redirect: string}|null
	 */
	public static function peek( string $token ): ?array {
		if ( ! self::enabled() || ! preg_match( '/^[A-Za-z0-9]{' . self::TOKEN_LENGTH . '}$/', $token ) ) {
			return null;
		}
		$link = get_transient( self::key( $token ) );
		if ( ! is_array( $link ) || (int) ( $link['expires_at'] ?? 0 ) <= time() ) {
			return null;
		}

		$user_id = (int) ( $link['user_id'] ?? 0 );
		$current = get_transient( self::user_key( $user_id ) );
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['key'] ?? '' ), self::key( $token ) ) || ! self::allowed( $user_id ) ) {
			return null;
		}

		return array(
			'user_id'  => $user_id,
			'redirect' => (string) ( $link['redirect'] ?? '' ),
		);
	}

	/**
	 * Uses the link: it's deleted, and its user and redirect are returned.
	 *
	 * @return array{user_id: int, redirect: string}|WP_Error
	 */
	public static function consume( string $token ) {
		$link = self::peek( $token );
		if ( ! $link ) {
			return self::expired();
		}
		self::revoke( $link['user_id'] );

		return $link;
	}

	public static function revoke( int $user_id ): void {
		$current = get_transient( self::user_key( $user_id ) );
		if ( is_array( $current ) && is_string( $current['key'] ?? null ) && '' !== $current['key'] ) {
			delete_transient( $current['key'] );
		}
		delete_transient( self::user_key( $user_id ) );
	}

	/**
	 * Whether $user_id may log in with an emailed link. Editors and administrators may not
	 * by default: they must use their password (and any two-factor step).
	 */
	public static function allowed( int $user_id ): bool {
		$user = self::account( $user_id );
		if ( ! $user ) {
			return false;
		}

		/**
		 * Filters whether a user may log in with an emailed link. Defaults to false for
		 * editors, administrators and super admins.
		 *
		 * @param bool    $allowed
		 * @param WP_User $user
		 */
		return (bool) apply_filters( 'openbb_login_link_allowed', ! self::privileged( $user ), $user );
	}

	/**
	 * Whether $user_id is an account that can log in at all (so may be emailed about it).
	 */
	public static function usable( int $user_id ): bool {
		return null !== self::account( $user_id );
	}

	/**
	 * Editors and above: anyone who can edit others' posts, and network super admins.
	 */
	public static function privileged( WP_User $user ): bool {
		return user_can( $user, 'edit_others_posts' ) || is_super_admin( $user->ID );
	}

	/**
	 * Records an email sent to $user_id without a usable link, so the resend cooldown
	 * applies to it too.
	 */
	public static function mark_sent( int $user_id ): void {
		self::revoke( $user_id );
		set_transient( self::user_key( $user_id ), array( 'key' => '', 'sent_at' => time() ), self::RESEND_COOLDOWN );
	}

	public static function send( WP_User $user, string $url ): bool {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && 'local' === wp_get_environment_type() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[open-bulk-buying] Login link for %s: %s', $user->user_email, $url ) );
		}

		/**
		 * Filters the login link email.
		 *
		 * @param array   $email to, subject, message, headers.
		 * @param WP_User $user
		 * @param string  $url   The login link.
		 */
		$email = apply_filters(
			'openbb_login_email',
			array(
				'to'      => $user->user_email,
				/* translators: %s: site name. */
				'subject' => sprintf( __( '[%s] Your login link', 'open-bulk-buying' ), $site ),
				'message' => sprintf(
					/* translators: 1: site name, 2: login link, 3: minutes until the link expires. */
					__( "Use this link to log in to %1\$s:\n\n%2\$s\n\nThe link works once and expires in %3\$d minutes.\n\nIf you didn't ask to log in, you can ignore this email.", 'open-bulk-buying' ),
					$site,
					$url,
					(int) ( self::TTL / MINUTE_IN_SECONDS )
				),
				'headers' => '',
			),
			$user,
			$url
		);

		return wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}

	/**
	 * Tells a user who isn't allowed login links (e.g. an administrator) that one was asked
	 * for, and to log in with their password at $page instead.
	 */
	public static function send_password_required( WP_User $user, string $page ): bool {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		/** This filter is documented in send() above; $url is the login page. */
		$email = apply_filters(
			'openbb_login_email',
			array(
				'to'      => $user->user_email,
				/* translators: %s: site name. */
				'subject' => sprintf( __( '[%s] Log in with your password', 'open-bulk-buying' ), $site ),
				'message' => sprintf(
					/* translators: 1: site name, 2: login page, 3: lost password page. */
					__( "Someone asked for a login link for your account on %1\$s.\n\nAccounts that can manage the site can't log in with an emailed link. Please log in with your password instead:\n\n%2\$s\n\nIf you've forgotten your password, you can reset it here:\n\n%3\$s\n\nIf you didn't ask to log in, you can ignore this email.", 'open-bulk-buying' ),
					$site,
					$page,
					wp_lostpassword_url( $page )
				),
				'headers' => '',
			),
			$user,
			$page
		);

		return wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}

	private static function account( int $user_id ): ?WP_User {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user && ! ( is_multisite() && ! empty( $user->spam ) ) ? $user : null;
	}

	private static function key( string $token ): string {
		return 'openbb_ll_' . hash( 'sha256', $token );
	}

	private static function user_key( int $user_id ): string {
		return 'openbb_ll_user_' . $user_id;
	}

	private static function expired(): WP_Error {
		return new WP_Error( 'openbb_login_link_expired', __( 'This login link has expired or was already used. Ask for a new one.', 'open-bulk-buying' ), array( 'status' => 410 ) );
	}
}
