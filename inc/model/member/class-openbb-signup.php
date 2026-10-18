<?php
/**
 * Creates WordPress accounts for new co-op members. Staff use it from the Members screen
 * (`staff_create`); the frontend signup form (roadmap 003) will add the `signup` context.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Signup {

	const ACCOUNT_FIELDS = array( 'email', 'first_name', 'last_name', 'phone', 'language' );

	/**
	 * Creates a member account. Everything is validated before the user is created, and a
	 * failure afterwards deletes the user again, so no half-created account is left behind.
	 *
	 * Staff never set or see a password: the account gets a random one, and the member sets
	 * their own through WordPress's set-your-password email or "Lost your password?".
	 *
	 * @param array  $account email (required), first_name (required), last_name, phone, language.
	 * @param array  $profile Profile field key => value.
	 * @param string $context OPENBB_Profile_Fields::CONTEXT_STAFF_CREATE.
	 * @param array  $options coop_role (default member), send_email (default true).
	 * @return array{user_id: int, email_sent: bool}|WP_Error
	 */
	public static function create_member( array $account, array $profile, string $context, array $options = array() ) {
		if ( OPENBB_Profile_Fields::CONTEXT_STAFF_CREATE !== $context ) {
			return new WP_Error( 'openbb_signup_context', __( 'Accounts can\'t be created here yet.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}

		$role = OPENBB_Access::sanitize_role( $options['coop_role'] ?? OPENBB_Access::ROLE_MEMBER );
		if ( ! current_user_can( 'openbb_create_members' ) ) {
			return self::forbidden( __( 'You are not allowed to create accounts.', 'open-bulk-buying' ) );
		}
		if ( '' === $role || ! current_user_can( 'openbb_set_coop_role', 0, $role ) ) {
			return self::forbidden( __( 'You are not allowed to give new members that co-op role.', 'open-bulk-buying' ) );
		}

		$account = self::validate_account( $account );
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		$profile = OPENBB_Profile_Fields::validate_values( 0, $profile, $context );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$user_id = wp_insert_user(
			wp_slash(
				array(
					'user_login'   => self::unique_login( $account['email'] ),
					'user_email'   => $account['email'],
					'user_pass'    => wp_generate_password( 32, true, true ),
					'first_name'   => $account['first_name'],
					'last_name'    => $account['last_name'],
					'display_name' => trim( $account['first_name'] . ' ' . $account['last_name'] ),
					// Never default_role (access spec §5.6).
					'role'         => 'subscriber',
				)
			)
		);
		if ( is_wp_error( $user_id ) ) {
			$user_id->add_data( array( 'status' => 500 ) );
			return $user_id;
		}

		$failed = static function ( WP_Error $error ) use ( $user_id ): WP_Error {
			self::delete_user( $user_id );
			if ( ! isset( ( (array) $error->get_error_data() )['status'] ) ) {
				$error->add_data( array( 'status' => 'openbb_forbidden' === $error->get_error_code() ? 403 : 500 ) );
			}
			return $error;
		};

		foreach ( array( 'phone', 'language' ) as $field ) {
			if ( '' !== $account[ $field ] && ! openbb_update_member_field( $user_id, $field, $account[ $field ] ) ) {
				return $failed( new WP_Error( 'openbb_member_save_failed', __( 'The member could not be saved.', 'open-bulk-buying' ) ) );
			}
		}

		$result = OPENBB_Access::set_coop_role( $user_id, $role );
		if ( is_wp_error( $result ) ) {
			return $failed( $result );
		}

		$result = OPENBB_Profile_Fields::set_values( $user_id, $profile, $context );
		if ( is_wp_error( $result ) ) {
			return $failed( $result );
		}

		/**
		 * @param int    $user_id  The new member.
		 * @param string $context  How the account was created.
		 * @param int    $actor_id User who created it; 0 for self-signup.
		 */
		do_action( 'openbb_member_created', $user_id, $context, get_current_user_id() );

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

		$existing = email_exists( $account['email'] );
		if ( $existing ) {
			// Only reveal accounts that already belong to this site (spec §12.2).
			if ( ! is_multisite() || is_user_member_of_blog( (int) $existing ) ) {
				return new WP_Error(
					'openbb_email_exists',
					'' !== OPENBB_Access::get_coop_role( (int) $existing )
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

		return $account;
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
}
