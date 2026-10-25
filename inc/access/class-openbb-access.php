<?php
/**
 * Co-op access control.
 *
 * Each person has a membership status on each site (applicant, active, inactive or
 * declined) and any number of co-op roles (OPENBB_Roles). Both are stored per site beside
 * the user's WordPress role and resolved into capabilities at check time by the
 * 'user_has_cap' filter. Nothing is written to wp_user_roles, so saving a user's WordPress
 * role can never remove their co-op access, and deactivating the plugin removes every
 * co-op capability at once.
 *
 * Active members can order, plus whatever their team roles allow. Team roles only count
 * while the person is active.
 *
 * Code checks capabilities (current_user_can( 'openbb_...' )), never statuses or roles.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Access {

	const STATUS_APPLICANT = 'applicant';
	const STATUS_ACTIVE    = 'active';
	const STATUS_INACTIVE  = 'inactive';
	const STATUS_DECLINED  = 'declined';

	const STATUSES = array( self::STATUS_APPLICANT, self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_DECLINED );

	/** Status => statuses it may change to. '' is someone who isn't a member yet. */
	const TRANSITIONS = array(
		''                     => array( self::STATUS_APPLICANT, self::STATUS_ACTIVE ),
		self::STATUS_APPLICANT => array( self::STATUS_ACTIVE, self::STATUS_DECLINED ),
		self::STATUS_DECLINED  => array( self::STATUS_ACTIVE ),
		self::STATUS_ACTIVE    => array( self::STATUS_INACTIVE ),
		self::STATUS_INACTIVE  => array( self::STATUS_ACTIVE ),
	);

	/** Given to anyone with a status: the person's own orders and member record. */
	const MEMBER_CAP = 'openbb_member';

	/** @var array<string, string[]> Resolved capabilities per site and user. */
	private static $caps_cache = array();

	/**
	 * Translated status labels. Only call from UI code; translations aren't available before 'init'.
	 */
	public static function status_labels(): array {
		return array(
			self::STATUS_APPLICANT => __( 'Applicant', 'open-bulk-buying' ),
			self::STATUS_ACTIVE    => __( 'Active', 'open-bulk-buying' ),
			self::STATUS_INACTIVE  => __( 'Inactive', 'open-bulk-buying' ),
			self::STATUS_DECLINED  => __( 'Declined', 'open-bulk-buying' ),
		);
	}

	public static function sanitize_status( $status ): string {
		return is_string( $status ) && in_array( $status, self::STATUSES, true ) ? $status : '';
	}

	public static function get_status( int $user_id ): string {
		return self::sanitize_status( openbb_get_member_field( $user_id, 'status' ) );
	}

	/**
	 * Team roles the user holds, in display order. Roles that no longer exist are ignored.
	 */
	public static function get_roles( int $user_id ): array {
		$held = (array) get_user_meta( $user_id, openbb_member_meta_key( 'roles' ), false );

		return self::sanitize_roles( $held );
	}

	/**
	 * Existing roles, unique and in display order.
	 */
	public static function sanitize_roles( $roles ): array {
		$roles = array_filter( (array) $roles, 'is_string' );
		$keys  = array_keys( OPENBB_Roles::get_config()['roles'] );

		return array_values( array_intersect( $keys, $roles ) );
	}

	/**
	 * Co-op capabilities for a user, on top of whatever their WordPress role gives them.
	 */
	public static function get_coop_caps( int $user_id, bool $is_admin = false ): array {
		$cache_key = get_current_blog_id() . ':' . $user_id . ':' . (int) $is_admin;
		if ( isset( self::$caps_cache[ $cache_key ] ) ) {
			return self::$caps_cache[ $cache_key ];
		}

		$caps   = array();
		$status = self::get_status( $user_id );
		if ( '' !== $status ) {
			$caps[] = self::MEMBER_CAP;
		}
		if ( self::STATUS_ACTIVE === $status ) {
			$caps[] = 'openbb_place_orders';
			foreach ( self::get_roles( $user_id ) as $role ) {
				$caps = array_merge( $caps, OPENBB_Roles::role_caps( $role ) );
			}
		}

		// Administrators get everything but ordering, which needs membership so that site
		// admins who aren't members don't show up as members.
		if ( $is_admin ) {
			$caps = array_merge( $caps, array_keys( OPENBB_Roles::capabilities() ) );
		}

		$registry = OPENBB_Roles::capabilities();
		foreach ( $caps as $cap ) {
			$caps = array_merge( $caps, $registry[ $cap ]['wp_caps'] ?? array() );
		}
		if ( array_intersect( $caps, array_keys( $registry ) ) ) {
			$caps[] = 'openbb_access_admin';
			$caps[] = 'read';
		}

		self::$caps_cache[ $cache_key ] = array_values( array_unique( $caps ) );

		return self::$caps_cache[ $cache_key ];
	}

	public static function flush_cache(): void {
		self::$caps_cache = array();
	}

	/**
	 * Clears resolved capabilities when user meta changes. Hooked to the user meta actions.
	 */
	public static function on_user_meta_change(): void {
		self::flush_cache();
	}

	/**
	 * Changes a user's status on behalf of the current user. Becoming active for the first
	 * time sets joined_at. Fires 'openbb_member_status_changed'.
	 *
	 * @return true|WP_Error
	 */
	public static function set_status( int $user_id, string $status ) {
		$status = self::sanitize_status( $status );
		if ( '' === $status || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'openbb_invalid_status', __( 'That membership status or user does not exist.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}

		$old = self::get_status( $user_id );
		if ( $old === $status ) {
			return true;
		}

		if ( ! in_array( $status, self::TRANSITIONS[ $old ] ?? array(), true ) ) {
			return new WP_Error( 'openbb_invalid_status_change', __( 'A membership can\'t change to that status from its current one.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}

		if ( ! current_user_can( 'openbb_set_member_status', $user_id, $status ) ) {
			return new WP_Error( 'openbb_forbidden', __( 'You are not allowed to change this person\'s membership status.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}

		return self::write_status( $user_id, $status, $old );
	}

	/**
	 * The signup transition: a user who isn't a member becomes an applicant or active
	 * member. There's no capability check because the person joining is the actor, often a
	 * visitor; only OPENBB_Signup calls this, after proving they own the account or email.
	 *
	 * @return true|WP_Error
	 */
	public static function start_membership( int $user_id, string $status ) {
		if ( ! in_array( $status, self::TRANSITIONS[''], true ) || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'openbb_invalid_status', __( 'That membership status or user does not exist.', 'open-bulk-buying' ) );
		}
		if ( '' !== self::get_status( $user_id ) ) {
			return new WP_Error( 'openbb_already_joined', __( 'This person already has a co-op membership.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		return self::write_status( $user_id, $status, '' );
	}

	/**
	 * @return true|WP_Error
	 */
	private static function write_status( int $user_id, string $status, string $old ) {
		if ( ! openbb_update_member_field( $user_id, 'status', $status ) ) {
			return new WP_Error( 'openbb_save_failed', __( 'The membership status could not be saved.', 'open-bulk-buying' ) );
		}

		if ( self::STATUS_ACTIVE === $status && '' === (string) openbb_get_member_field( $user_id, 'joined_at' ) ) {
			if ( ! openbb_update_member_field( $user_id, 'joined_at', current_time( 'mysql', true ) ) ) {
				$restored = '' === $old
					? delete_user_meta( $user_id, openbb_member_meta_key( 'status' ) )
					: openbb_update_member_field( $user_id, 'status', $old );
				return new WP_Error(
					'openbb_save_failed',
					$restored
						? __( 'The joining date could not be saved. The membership status was not changed.', 'open-bulk-buying' )
						: __( 'The membership status changed, but the joining date could not be saved or the status restored. Please correct this person\'s membership before continuing.', 'open-bulk-buying' )
				);
			}
		}

		self::flush_cache();

		/**
		 * @param int    $user_id  User whose status changed.
		 * @param string $status   New status.
		 * @param string $old      Previous status; '' if they weren't a member.
		 * @param int    $actor_id User who made the change.
		 */
		do_action( 'openbb_member_status_changed', $user_id, $status, $old, get_current_user_id() );

		return true;
	}

	/**
	 * Replaces the team roles a member holds on behalf of the current user.
	 * Fires 'openbb_member_roles_changed'.
	 *
	 * @param string[] $roles
	 * @return true|WP_Error
	 */
	public static function set_roles( int $user_id, array $roles ) {
		$roles = self::valid_role_list( $roles );
		if ( null === $roles || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'openbb_invalid_role', __( 'That role or user does not exist.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}
		if ( '' === self::get_status( $user_id ) ) {
			return new WP_Error( 'openbb_not_member', __( 'Only members can be given roles.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}

		$roles = self::sanitize_roles( $roles );
		$old   = self::get_roles( $user_id );
		if ( $old === $roles ) {
			return true;
		}

		if ( ! current_user_can( 'openbb_set_member_roles', $user_id, $roles ) ) {
			return new WP_Error( 'openbb_forbidden', __( 'You are not allowed to give or take away these roles.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}

		$key = openbb_member_meta_key( 'roles' );
		foreach ( array_diff( $old, $roles ) as $role ) {
			if ( ! delete_user_meta( $user_id, $key, $role ) ) {
				return new WP_Error( 'openbb_save_failed', __( 'The roles could not be saved.', 'open-bulk-buying' ) );
			}
		}
		foreach ( array_diff( $roles, $old ) as $role ) {
			if ( ! add_user_meta( $user_id, $key, $role ) ) {
				return new WP_Error( 'openbb_save_failed', __( 'The roles could not be saved.', 'open-bulk-buying' ) );
			}
		}

		self::flush_cache();

		/**
		 * @param int      $user_id  User whose roles changed.
		 * @param string[] $roles    Roles they now hold.
		 * @param string[] $old      Roles they held before.
		 * @param int      $actor_id User who made the change.
		 */
		do_action( 'openbb_member_roles_changed', $user_id, $roles, $old, get_current_user_id() );

		return true;
	}

	/**
	 * Adds co-op capabilities to the user's WordPress capabilities. Only ever adds.
	 *
	 * Hooked to 'user_has_cap'.
	 */
	public static function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return $allcaps;
		}

		foreach ( self::get_coop_caps( $user->ID, ! empty( $allcaps['manage_options'] ) ) as $cap ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * Resolves the object-level co-op capabilities into primitive ones.
	 *
	 * Hooked to 'map_meta_cap'.
	 */
	public static function filter_map_meta_cap( $caps, $cap, $user_id, $args ) {
		$user_id = (int) $user_id;

		switch ( $cap ) {
			case 'openbb_access_cycles':
				foreach ( array( 'openbb_manage_cycles', 'openbb_view_aid' ) as $cycle_cap ) {
					if ( user_can( $user_id, $cycle_cap ) ) {
						return array( $cycle_cap );
					}
				}
				return array( 'openbb_manage_cycles' );

			case 'openbb_access_settings':
				// The Settings screen has a tab for each of these.
				foreach ( array( 'openbb_manage_settings', 'openbb_manage_rules', 'openbb_manage_profile_fields', 'openbb_manage_access' ) as $tab_cap ) {
					if ( user_can( $user_id, $tab_cap ) ) {
						return array( $tab_cap );
					}
				}
				return array( 'openbb_manage_settings' );

			case 'openbb_view_order':
			case 'openbb_edit_own_order':
			case 'openbb_edit_order':
			case 'openbb_view_order_aid':
			case 'openbb_decide_order_aid':
				$order = isset( $args[0] ) ? OPENBB_Orders::get( (int) $args[0] ) : null;
				return $order ? self::map_order_cap( $cap, $order, $user_id ) : array( 'do_not_allow' );

			case 'openbb_view_member':
			case 'openbb_edit_member':
			case 'openbb_set_member_status':
			case 'openbb_set_member_roles':
				// An explicit 0 is a user about to be created (OPENBB_Signup::create_member).
				if ( array_key_exists( 0, $args ) && 0 === $args[0] ) {
					return self::map_new_user_cap( $cap, $user_id, $args[1] ?? null );
				}
				$target_id = isset( $args[0] ) ? (int) $args[0] : 0;
				if ( ! $target_id || ! get_userdata( $target_id ) ) {
					return array( 'do_not_allow' );
				}
				return self::map_member_cap( $cap, $target_id, $user_id, $args[1] ?? null );
		}

		return $caps;
	}

	private static function map_order_cap( string $cap, OPENBB_Order $order, int $user_id ): array {
		$is_owner = $user_id && $order->user_id === $user_id && user_can( $user_id, self::MEMBER_CAP );

		switch ( $cap ) {
			case 'openbb_view_order':
				if ( $is_owner ) {
					return array( self::MEMBER_CAP );
				}
				if ( user_can( $user_id, 'openbb_view_orders' ) ) {
					return array( 'openbb_view_orders' );
				}
				// Processing and distribution access only reaches orders in the cycle being worked on.
				$cycle = OPENBB_Cycles::get( $order->cycle_id );
				$stage = $cycle ? $cycle->status : '';
				if ( in_array( $stage, array( OPENBB_Cycle::STATUS_PROCESSING, OPENBB_Cycle::STATUS_DISTRIBUTION ), true ) && user_can( $user_id, 'openbb_process_orders' ) ) {
					return array( 'openbb_process_orders' );
				}
				if ( OPENBB_Cycle::STATUS_DISTRIBUTION === $stage && user_can( $user_id, 'openbb_distribute_orders' ) ) {
					return array( 'openbb_distribute_orders' );
				}
				return array( 'openbb_view_orders' );

			case 'openbb_edit_own_order':
				return $is_owner ? array( 'openbb_place_orders' ) : array( 'do_not_allow' );

			case 'openbb_edit_order':
				return array( 'openbb_edit_orders' );

			case 'openbb_view_order_aid':
				return $is_owner ? array( self::MEMBER_CAP ) : array( 'openbb_view_aid' );

			case 'openbb_decide_order_aid':
				// Nobody decides aid on their own order.
				return $user_id && $order->user_id === $user_id ? array( 'do_not_allow' ) : array( 'openbb_decide_aid' );
		}

		return array( 'do_not_allow' );
	}

	private static function map_member_cap( string $cap, int $target_id, int $user_id, $value ): array {
		$is_self   = $user_id && $target_id === $user_id;
		$is_member = $is_self && user_can( $user_id, self::MEMBER_CAP );

		switch ( $cap ) {
			case 'openbb_view_member':
				return $is_member ? array( self::MEMBER_CAP ) : array( 'openbb_view_members' );

			case 'openbb_edit_member':
				if ( $is_member ) {
					return array( self::MEMBER_CAP );
				}
				// Never let someone edit a person with powers they don't have themselves.
				foreach ( array( 'manage_options', 'openbb_manage_access' ) as $guarded ) {
					if ( user_can( $target_id, $guarded ) && ! user_can( $user_id, $guarded ) ) {
						return array( 'do_not_allow' );
					}
				}
				return array( 'openbb_edit_members' );

			case 'openbb_set_member_status':
				$status = self::sanitize_status( $value );
				$old    = self::get_status( $target_id );
				// Nobody changes their own status, and it never goes back to "not a member".
				if ( $is_self || '' === $status || ! in_array( $status, self::TRANSITIONS[ $old ] ?? array(), true ) || self::is_protected_from( $target_id, $user_id ) ) {
					return array( 'do_not_allow' );
				}
				if ( '' === $old ) {
					return array( 'openbb_create_members' );
				}
				// Deactivating or reactivating someone with roles changes their access, not just their membership.
				return self::get_roles( $target_id ) ? array( 'openbb_edit_members', 'openbb_manage_access' ) : array( 'openbb_edit_members' );

			case 'openbb_set_member_roles':
				// Administrators may set their own roles: their admin powers don't come from them.
				if ( ( $is_self && ! user_can( $user_id, 'manage_options' ) ) || self::is_protected_from( $target_id, $user_id ) ) {
					return array( 'do_not_allow' );
				}
				$old = self::get_roles( $target_id );
				$new = self::valid_role_list( $value );
				return null === $new ? array( 'do_not_allow' ) : self::role_change_caps( $user_id, array_merge( array_diff( $old, $new ), array_diff( $new, $old ) ) );
		}

		return array( 'do_not_allow' );
	}

	/**
	 * Setting the access of an account that is about to be created (target 0): never self,
	 * never protected, so it's judged only on the requested status or roles.
	 */
	private static function map_new_user_cap( string $cap, int $user_id, $value ): array {
		switch ( $cap ) {
			case 'openbb_set_member_status':
				return in_array( $value, self::TRANSITIONS[''], true ) ? array( 'openbb_create_members' ) : array( 'do_not_allow' );

			case 'openbb_set_member_roles':
				$roles = self::valid_role_list( $value );
				return null === $roles ? array( 'do_not_allow' ) : self::role_change_caps( $user_id, $roles );
		}

		return array( 'do_not_allow' );
	}

	/**
	 * @return string[]|null The roles, or null if any of them can't be given.
	 */
	private static function valid_role_list( $roles ): ?array {
		if ( ! is_array( $roles ) || count( array_filter( $roles, 'is_string' ) ) !== count( $roles ) ) {
			return null;
		}
		$roles = array_values( array_unique( $roles ) );

		return count( self::sanitize_roles( $roles ) ) === count( $roles ) ? $roles : null;
	}

	/**
	 * Giving or taking away roles needs openbb_manage_access and, except for administrators,
	 * every capability of each role involved, so nobody hands out more than they have.
	 */
	private static function role_change_caps( int $user_id, array $changed ): array {
		$caps = array( 'openbb_manage_access' );
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			foreach ( $changed as $role ) {
				$caps = array_merge( $caps, OPENBB_Roles::role_caps( $role ) );
			}
		}

		return array_values( array_unique( $caps ) );
	}

	/**
	 * Only Administrators may change the access of people who can manage access or the site.
	 */
	private static function is_protected_from( int $target_id, int $user_id ): bool {
		return ( user_can( $target_id, 'openbb_manage_access' ) || user_can( $target_id, 'manage_options' ) )
			&& ! user_can( $user_id, 'manage_options' );
	}
}
