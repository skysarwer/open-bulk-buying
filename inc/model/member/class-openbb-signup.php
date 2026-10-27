<?php
/**
 * Co-op membership creation. Two callers:
 *
 * - Staff create accounts from the Members screen (`staff_create`, create_member()).
 * - People sign themselves up on the frontend (`signup`): visitors through register()
 *   after proving their email with a code, signed-in users without a membership status through
 *   join(). Signup needs no capability; the person joining is the actor.
 *
 * Everything is validated before anything is written, and a failure after a new user was
 * created deletes the user again, so no half-created account is left behind.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Signup {

	const ACCOUNT_FIELDS = array( 'email', 'first_name', 'last_name', 'phone', 'language' );

	/** Form field name for the rules agreement; its errors use this key. */
	const RULES_FIELD = 'rules_accepted';

	/**
	 * Creates a member account for staff.
	 *
	 * Staff never set or see a password: the account gets a random one, and the member sets
	 * their own through WordPress's set-your-password email or "Lost your password?".
	 *
	 * @param array  $account email (required), first_name (required), last_name, phone, language.
	 * @param array  $profile Profile field key => value.
	 * @param string $context OPENBB_Profile_Fields::CONTEXT_STAFF_CREATE.
	 * @param array  $options status (default active), roles (default none), send_email (default true).
	 * @return array{user_id: int, email_sent: bool}|WP_Error
	 */
	public static function create_member( array $account, array $profile, string $context, array $options = array() ) {
		if ( OPENBB_Profile_Fields::CONTEXT_STAFF_CREATE !== $context ) {
			return new WP_Error( 'openbb_signup_context', __( 'Accounts can\'t be created here.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}

		$status = (string) ( $options['status'] ?? OPENBB_Access::STATUS_ACTIVE );
		$roles  = (array) ( $options['roles'] ?? array() );
		if ( ! current_user_can( 'openbb_create_members' ) ) {
			return self::forbidden( __( 'You are not allowed to create accounts.', 'open-bulk-buying' ) );
		}
		if ( ! current_user_can( 'openbb_set_member_status', 0, $status ) ) {
			return self::forbidden( __( 'You are not allowed to give new members that status.', 'open-bulk-buying' ) );
		}
		if ( $roles && ! current_user_can( 'openbb_set_member_roles', 0, $roles ) ) {
			return self::forbidden( __( 'You are not allowed to give new members these roles.', 'open-bulk-buying' ) );
		}

		$account = self::validate_account( $account );
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		$conflict = self::staff_email_conflict( $account['email'] );
		if ( $conflict ) {
			return $conflict;
		}
		$profile = OPENBB_Profile_Fields::validate_values( 0, $profile, $context );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$user_id = self::insert_user( $account );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$result = self::save_account_meta( $user_id, $account );
		if ( ! is_wp_error( $result ) ) {
			$result = OPENBB_Access::set_status( $user_id, $status );
		}
		if ( ! is_wp_error( $result ) && $roles ) {
			$result = OPENBB_Access::set_roles( $user_id, $roles );
		}
		if ( ! is_wp_error( $result ) ) {
			$result = OPENBB_Profile_Fields::set_values( $user_id, $profile, $context );
		}
		if ( is_wp_error( $result ) ) {
			return self::undo_insert( $user_id, $result );
		}

		self::created( $user_id, $context );

		$email_sent = ( $options['send_email'] ?? true ) && openbb_can_email_member( $user_id );
		if ( $email_sent ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}

		return array(
			'user_id'    => $user_id,
			'email_sent' => $email_sent,
		);
	}

	/**
	 * Whether the co-op accepts self-signups (General settings).
	 */
	public static function is_open(): bool {
		return (bool) OPENBB_Settings::get_general()['allow_signups'];
	}

	/**
	 * The status a self-signup gets: applicant while approval is required.
	 */
	public static function signup_status(): string {
		return OPENBB_Settings::get_general()['require_member_approval'] ? OPENBB_Access::STATUS_APPLICANT : OPENBB_Access::STATUS_ACTIVE;
	}

	/**
	 * Account values a user already has. The signup form shows them locked, and join() keeps them.
	 *
	 * @return array Field => non-empty value.
	 */
	public static function existing_account( int $user_id ): array {
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return array();
		}

		return array_filter(
			array(
				'email'      => (string) $user->user_email,
				'first_name' => (string) $user->first_name,
				'last_name'  => (string) $user->last_name,
				'phone'      => (string) openbb_get_member_field( $user_id, 'phone' ),
				'language'   => (string) openbb_get_member_field( $user_id, 'language' ),
			),
			static function ( string $value ): bool {
				return '' !== trim( $value );
			}
		);
	}

	/**
	 * Profile values a user already has in the signup context; locked like existing_account().
	 */
	public static function existing_profile( int $user_id ): array {
		if ( ! $user_id ) {
			return array();
		}

		return array_filter(
			OPENBB_Profile_Fields::get_values( $user_id, OPENBB_Profile_Fields::CONTEXT_SIGNUP ),
			static function ( $value ): bool {
				return ! in_array( $value, array( '', false, array() ), true );
			}
		);
	}

	/**
	 * Validates a signup form: account fields, profile fields and the rules agreement.
	 * For a signed-in user ($user_id), values they already have replace submitted ones.
	 *
	 * $rules_version is the published rules version the person agreed to (0 = not agreed).
	 * It must be the latest version, so nobody joins under rules they haven't seen.
	 *
	 * @return array{account: array, profile: array, rules_version: int}|WP_Error Errors for
	 *         every field at once in `data.errors` (field name => message).
	 */
	public static function validate_signup( array $account, array $profile, int $rules_version, int $user_id = 0 ) {
		$errors = array();

		$account = array_merge( $account, self::existing_account( $user_id ) );
		$account = self::validate_account( $account );
		if ( is_wp_error( $account ) ) {
			$errors = (array) ( $account->get_error_data()['errors'] ?? array() );
		}

		$profile = array_diff_key( $profile, self::existing_profile( $user_id ) );
		$profile = OPENBB_Profile_Fields::validate_values( $user_id, $profile, OPENBB_Profile_Fields::CONTEXT_SIGNUP );
		if ( is_wp_error( $profile ) ) {
			$errors += (array) ( $profile->get_error_data()['errors'] ?? array() );
		}

		$latest = OPENBB_Rules::get_latest();
		if ( $latest && $rules_version !== $latest->id ) {
			$errors[ self::RULES_FIELD ] = $rules_version > 0
				? __( 'The co-op rules were updated while you were signing up. Reload the page to read the new version.', 'open-bulk-buying' )
				: __( 'You must agree to the co-op rules to join.', 'open-bulk-buying' );
		}

		if ( $errors ) {
			return new WP_Error(
				'openbb_signup_invalid',
				__( 'Some details need attention.', 'open-bulk-buying' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		return array(
			'account'       => $account,
			'profile'       => $profile,
			'rules_version' => $latest ? $latest->id : 0,
		);
	}

	/**
	 * Self-signup of a new account. Call only once the person has proven they own the email
	 * (OPENBB_Email_Code); the input is validated again, as settings may have changed since.
	 * An optional membership fee claim is submitted for staff review, never approved here.
	 *
	 * @return array{user_id: int, status: string}|WP_Error
	 */
	public static function register( array $account, array $profile, int $rules_version, bool $membership_fee_claim = false, string $membership_fee_reference = '' ) {
		if ( ! self::is_open() ) {
			return self::closed();
		}

		$signup = self::validate_signup( $account, $profile, $rules_version );
		if ( is_wp_error( $signup ) ) {
			return $signup;
		}
		if ( email_exists( $signup['account']['email'] ) ) {
			return new WP_Error( 'openbb_email_exists', __( 'You already have an account with this email. Log in to finish joining.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		$user_id = self::insert_user( $signup['account'] );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$status = self::signup_status();
		$result = self::complete_signup( $user_id, $signup, $status );
		if ( ! is_wp_error( $result ) && $membership_fee_claim ) {
			$result = OPENBB_Membership_Fees::claim( $user_id, $membership_fee_reference );
		}
		if ( is_wp_error( $result ) ) {
			return self::undo_insert( $user_id, $result );
		}

		self::created( $user_id, OPENBB_Profile_Fields::CONTEXT_SIGNUP );
		self::send_welcome( $user_id, $status );

		return array(
			'user_id' => $user_id,
			'status'  => $status,
		);
	}

	/**
	 * Self-signup of a signed-in user who isn't a member yet. Only empty account and
	 * profile fields are filled in; the user's email and WordPress role are kept.
	 * An optional membership fee claim is submitted only after membership is saved.
	 *
	 * @return array{user_id: int, status: string}|WP_Error
	 */
	public static function join( int $user_id, array $account, array $profile, int $rules_version, bool $membership_fee_claim = false, string $membership_fee_reference = '' ) {
		if ( ! self::is_open() ) {
			return self::closed();
		}
		if ( ! get_userdata( $user_id ) ) {
			return self::forbidden( __( 'You must be logged in.', 'open-bulk-buying' ) );
		}
		if ( '' !== OPENBB_Access::get_status( $user_id ) ) {
			return new WP_Error( 'openbb_already_joined', __( 'You already have a co-op membership.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		$signup = self::validate_signup( $account, $profile, $rules_version, $user_id );
		if ( is_wp_error( $signup ) ) {
			return $signup;
		}

		$before = self::join_snapshot( $user_id, $signup );

		// Users of another site in the network join this one as subscribers.
		if ( $before['add_to_blog'] && is_wp_error( add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' ) ) ) {
			return self::save_failed();
		}

		$existing = self::existing_account( $user_id );
		$names    = array_diff_key( array_intersect_key( $signup['account'], array_flip( array( 'first_name', 'last_name' ) ) ), $existing );
		if ( $names ) {
			$user = get_userdata( $user_id );
			if ( '' === $user->display_name || $user->display_name === $user->user_login ) {
				$names['display_name'] = trim( ( $existing['first_name'] ?? $signup['account']['first_name'] ) . ' ' . ( $existing['last_name'] ?? $signup['account']['last_name'] ) );
			}
			$updated = wp_update_user( wp_slash( array( 'ID' => $user_id ) + $names ) );
			if ( is_wp_error( $updated ) ) {
				return self::undo_join( $user_id, $before, self::save_failed() );
			}
		}

		$status = self::signup_status();
		$result = self::complete_signup( $user_id, $signup, $status );
		if ( ! is_wp_error( $result ) && $membership_fee_claim ) {
			$result = OPENBB_Membership_Fees::claim( $user_id, $membership_fee_reference );
		}
		if ( is_wp_error( $result ) ) {
			return self::undo_join( $user_id, $before, $result );
		}

		self::created( $user_id, OPENBB_Profile_Fields::CONTEXT_SIGNUP );
		self::send_welcome( $user_id, $status );

		return array(
			'user_id' => $user_id,
			'status'  => $status,
		);
	}

	/**
	 * The "enter this code" email for a visitor signing up.
	 */
	public static function send_code( string $email, string $code ): bool {
		self::maybe_log_code( $email, $code );

		$template = OPENBB_Emails::render(
			'signup-code',
			array(
				'code'    => OPENBB_Email_Code::format( $code ),
				'minutes' => (int) ( OPENBB_Email_Code::TTL / MINUTE_IN_SECONDS ),
			)
		);

		return self::mail( 'code', $email, $template['subject'], $template['message'] );
	}

	/**
	 * Sent instead of a code when the email already has an account, so the signup form
	 * never reveals which addresses are registered.
	 */
	public static function send_account_exists( string $email ): bool {
		$template = OPENBB_Emails::render( 'account-exists' );

		return self::mail( 'account_exists', $email, $template['subject'], $template['message'] );
	}

	/**
	 * Welcome existing members whenever staff activate their membership.
	 */
	public static function on_member_status_changed( int $user_id, string $status, string $old ): void {
		// New memberships have their own signup or staff-created account notification.
		if ( '' === $old || OPENBB_Access::STATUS_ACTIVE === $old || OPENBB_Access::STATUS_ACTIVE !== $status || ! openbb_can_email_member( $user_id ) ) {
			return;
		}

		if ( ! self::send_welcome( $user_id, $status ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[open-bulk-buying] Membership welcome email could not be sent for user %d on site %d.', $user_id, get_current_blog_id() ) );
		}
	}

	/**
	 * "Application received" or "welcome", on signup or subsequent activation.
	 */
	private static function send_welcome( int $user_id, string $status ): bool {
		if ( ! openbb_can_email_member( $user_id ) ) {
			return false;
		}

		$user = get_userdata( $user_id );
		$is_applicant = OPENBB_Access::STATUS_APPLICANT === $status;
		$template = OPENBB_Emails::render(
			$is_applicant ? 'application-pending' : 'welcome',
			array(
				'first_name' => $user->first_name,
			)
		);

		return self::mail( $is_applicant ? 'welcome_applicant' : 'welcome_member', $user->user_email, $template['subject'], $template['message'], $user_id );
	}

	private static function mail( string $type, string $to, string $subject, string $message, int $user_id = 0 ): bool {
		/**
		 * Filters a signup email, e.g. to add ordering and pickup guidance to the welcome email.
		 *
		 * @param array  $email   to, subject, message, headers.
		 * @param string $type    code, account_exists, welcome_applicant or welcome_member.
		 * @param int    $user_id The member signing up or being activated; 0 before the account exists.
		 */
		$email = apply_filters(
			'openbb_signup_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'message' => $message,
				'headers' => 'code' === $type ? 'Content-Type: text/html; charset=UTF-8' : '',
			),
			$type,
			$user_id
		);

		return wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}

	/**
	 * Local development sites often can't send mail, so the code is logged there.
	 */
	private static function maybe_log_code( string $email, string $code ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && 'local' === wp_get_environment_type() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[open-bulk-buying] Sign-up code for %s: %s', $email, $code ) );
		}
	}

	/**
	 * Saves the account details, profile and rules acceptance, then the status, so the person
	 * only becomes an applicant or member once everything else is saved.
	 *
	 * @return true|WP_Error
	 */
	private static function complete_signup( int $user_id, array $signup, string $status ) {
		$existing = self::existing_account( $user_id );
		$result   = self::save_account_meta( $user_id, array_diff_key( $signup['account'], $existing ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = OPENBB_Profile_Fields::set_values( $user_id, $signup['profile'], OPENBB_Profile_Fields::CONTEXT_SIGNUP );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $signup['rules_version'] && ! OPENBB_Rules::record_acceptance( $user_id, $signup['rules_version'] ) ) {
			return self::save_failed();
		}

		$result = OPENBB_Access::start_membership( $user_id, $status );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
		}

		return $result;
	}

	/**
	 * @return true|WP_Error
	 */
	private static function save_account_meta( int $user_id, array $account ) {
		foreach ( array( 'phone', 'language' ) as $field ) {
			if ( '' !== ( $account[ $field ] ?? '' ) && ! openbb_update_member_field( $user_id, $field, $account[ $field ] ) ) {
				return self::save_failed();
			}
		}

		return true;
	}

	/**
	 * @return int|WP_Error
	 */
	private static function insert_user( array $account ) {
		$user_id = wp_insert_user(
			wp_slash(
				array(
					'user_login'   => self::unique_login( $account['email'] ),
					'user_email'   => $account['email'],
					'user_pass'    => wp_generate_password( 32, true, true ),
					'first_name'   => $account['first_name'],
					'last_name'    => $account['last_name'],
					'display_name' => trim( $account['first_name'] . ' ' . $account['last_name'] ),
					// Never default_role.
					'role'         => 'subscriber',
				)
			)
		);
		if ( is_wp_error( $user_id ) ) {
			$user_id->add_data( array( 'status' => 500 ) );
		}

		return $user_id;
	}

	private static function undo_insert( int $user_id, WP_Error $error ): WP_Error {
		self::delete_user( $user_id );
		if ( ! isset( ( (array) $error->get_error_data() )['status'] ) ) {
			$error->add_data( array( 'status' => 'openbb_forbidden' === $error->get_error_code() ? 403 : 500 ) );
		}

		return $error;
	}

	/**
	 * Profile saves own their transaction, so joining uses a compensating rollback.
	 */
	private static function join_snapshot( int $user_id, array $signup ): array {
		$user = get_userdata( $user_id );
		$keys = array( 'first_name', 'last_name' );
		foreach ( array( 'phone', 'language', 'rules_version', 'rules_accepted_at', 'status', 'joined_at' ) as $field ) {
			$keys[] = openbb_member_meta_key( $field );
		}
		foreach ( array_keys( $signup['profile'] ) as $field ) {
			$keys[] = OPENBB_Profile_Fields::meta_key( $field );
		}

		$meta = array();
		foreach ( $keys as $key ) {
			$meta[ $key ] = get_user_meta( $user_id, $key );
		}

		return array(
			'display_name' => $user->display_name,
			'meta'         => $meta,
			'add_to_blog'  => is_multisite() && ! is_user_member_of_blog( $user_id ),
		);
	}

	private static function undo_join( int $user_id, array $before, WP_Error $error ): WP_Error {
		if ( $before['add_to_blog'] ) {
			remove_user_from_blog( $user_id, get_current_blog_id() );
		}
		wp_update_user( wp_slash( array( 'ID' => $user_id, 'display_name' => $before['display_name'] ) ) );
		foreach ( $before['meta'] as $key => $values ) {
			if ( get_user_meta( $user_id, $key ) === $values ) {
				continue;
			}
			delete_user_meta( $user_id, $key );
			foreach ( $values as $value ) {
				add_user_meta( $user_id, $key, wp_slash( $value ) );
			}
		}
		clean_user_cache( $user_id );
		OPENBB_Access::flush_cache();

		return $error;
	}

	private static function created( int $user_id, string $context ): void {
		/**
		 * @param int    $user_id  The new member.
		 * @param string $context  How the membership was created: staff_create or signup.
		 * @param int    $actor_id User who created it; 0 for a visitor's self-signup.
		 */
		do_action( 'openbb_member_created', $user_id, $context, get_current_user_id() );
	}

	/**
	 * @return array|WP_Error Sanitized account fields, or field errors in `data.errors`.
	 */
	private static function validate_account( array $input ) {
		$account = array();
		foreach ( self::ACCOUNT_FIELDS as $field ) {
			$value             = $input[ $field ] ?? '';
			$account[ $field ] = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
		}
		$account['email']    = sanitize_email( $account['email'] );
		$account['language'] = sanitize_key( $account['language'] );

		$errors = array();
		if ( '' === $account['email'] || ! is_email( $account['email'] ) ) {
			$errors['email'] = __( 'Enter a valid email address.', 'open-bulk-buying' );
		}
		if ( '' === $account['first_name'] ) {
			$errors['first_name'] = __( 'Enter a first name.', 'open-bulk-buying' );
		}
		$languages = openbb_languages();
		if ( '' !== $account['language'] && $languages && ! in_array( $account['language'], $languages, true ) ) {
			$errors['language'] = __( 'Choose one of the site\'s languages.', 'open-bulk-buying' );
		}
		if ( $errors ) {
			return new WP_Error(
				'openbb_account_invalid',
				__( 'Some account details need attention.', 'open-bulk-buying' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		return $account;
	}

	/**
	 * Staff learn whether the email belongs to someone on this site, so they can add that
	 * user instead. Never used for self-signup, which must not reveal accounts.
	 */
	private static function staff_email_conflict( string $email ): ?WP_Error {
		$existing = email_exists( $email );
		if ( ! $existing ) {
			return null;
		}

		// Only reveal accounts that already belong to this site.
		if ( ! is_multisite() || is_user_member_of_blog( (int) $existing ) ) {
			return new WP_Error(
				'openbb_email_exists',
				'' !== OPENBB_Access::get_status( (int) $existing )
					? __( 'This person is already a member.', 'open-bulk-buying' )
					: __( 'A user on this site already has this email. Add them with "Existing user" instead.', 'open-bulk-buying' ),
				array(
					'status'  => 409,
					'user_id' => (int) $existing,
				)
			);
		}

		return new WP_Error( 'openbb_email_unavailable', __( 'This email can\'t be used. Ask an Administrator.', 'open-bulk-buying' ), array( 'status' => 409 ) );
	}

	/**
	 * A username from the email's local part (lowercase letters and digits, which also
	 * satisfies multisite), with a number added if it's taken.
	 */
	private static function unique_login( string $email ): string {
		$base = substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) strtok( $email, '@' ) ) ), 0, 50 );
		if ( strlen( $base ) < 4 ) {
			$base = 'member' . $base;
		}

		$illegal = array_map( 'strtolower', (array) apply_filters( 'illegal_user_logins', array() ) );
		$login   = $base;
		for ( $n = 2; username_exists( $login ) || in_array( $login, $illegal, true ); $n++ ) {
			$login = $base . $n;
		}

		return $login;
	}

	private static function delete_user( int $user_id ): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $user_id );
		} else {
			wp_delete_user( $user_id );
		}
	}

	private static function forbidden( string $message ): WP_Error {
		return new WP_Error( 'openbb_forbidden', $message, array( 'status' => 403 ) );
	}

	private static function closed(): WP_Error {
		return new WP_Error( 'openbb_signup_closed', __( 'Signups are closed right now.', 'open-bulk-buying' ), array( 'status' => 403 ) );
	}

	private static function save_failed(): WP_Error {
		return new WP_Error( 'openbb_member_save_failed', __( 'The membership could not be saved. Please try again.', 'open-bulk-buying' ), array( 'status' => 500 ) );
	}
}
