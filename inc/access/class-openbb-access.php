<?php
/**
 * Co-op access control (see ref/access-control-spec.md).
 *
 * Co-op roles and grants are stored per site beside the user's WordPress role, and
 * resolved into capabilities at check time by the 'user_has_cap' filter. Nothing is
 * written to wp_user_roles, so saving a user's WordPress role can never remove their
 * co-op access, and deactivating the plugin removes every co-op capability at once.
 *
 * Code checks capabilities (current_user_can( 'openbb_...' )), never co-op role names.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Access {

	const ROLE_APPLICANT   = 'applicant';
	const ROLE_MEMBER      = 'member';
	const ROLE_COORDINATOR = 'coordinator';
	const ROLE_MANAGER     = 'manager';
	const ROLE_INACTIVE    = 'inactive';

	/** Roles that can order and whose grants apply. */
	const ACTIVE_ROLES = array( self::ROLE_MEMBER, self::ROLE_COORDINATOR, self::ROLE_MANAGER );

	/** Roles that only openbb_manage_access can assign or take away. */
	const STAFF_ROLES = array( self::ROLE_COORDINATOR, self::ROLE_MANAGER );

	/** No grant may include this, so handing out access stays with Managers and Administrators. */
	const UNGRANTABLE_CAP = 'openbb_manage_access';

	/**
	 * Role => capabilities. Filterable with 'openbb_coop_role_caps'; roles can't be added that way.
	 */
	public static function coop_roles(): array {
		$member      = array( 'read', 'openbb_place_orders' );
		$coordinator = array_merge(
			$member,
			array(
				'upload_files',
				'openbb_access_admin',
				'openbb_manage_products',
				'openbb_manage_inventory',
				'openbb_manage_cycles',
				'openbb_view_orders',
				'openbb_edit_orders',
				'openbb_process_orders',
				'openbb_distribute_orders',
				'openbb_record_payments',
				'openbb_view_members',
				'openbb_edit_members',
				'openbb_export_data',
			)
		);

		$roles = array(
			self::ROLE_APPLICANT   => array( 'read' ),
			self::ROLE_MEMBER      => $member,
			self::ROLE_COORDINATOR => $coordinator,
			self::ROLE_MANAGER     => array_merge(
				$coordinator,
				array(
					'openbb_view_aid',
					'openbb_decide_aid',
					'openbb_manage_rules',
					'openbb_manage_settings',
					'openbb_manage_access',
				)
			),
			self::ROLE_INACTIVE    => array( 'read' ),
		);

		$filtered = apply_filters( 'openbb_coop_role_caps', $roles );

		return array_map(
			static function ( $caps ): array {
				return array_values( array_unique( array_filter( (array) $caps, 'is_string' ) ) );
			},
			array_intersect_key( is_array( $filtered ) ? $filtered : array(), $roles ) + $roles
		);
	}

	/**
	 * Grant => capabilities. Grants add capabilities on top of an active co-op role.
	 *
	 * Filterable with 'openbb_grants'. A grant may only use capabilities some co-op role
	 * has, and never UNGRANTABLE_CAP; anything else is dropped.
	 */
	public static function grants(): array {
		$grants = apply_filters(
			'openbb_grants',
			array(
				'aid_reviewer'        => array( 'openbb_view_aid', 'openbb_decide_aid' ),
				'distribution_helper' => array( 'openbb_access_admin', 'openbb_distribute_orders', 'openbb_record_payments' ),
				'rules_editor'        => array( 'openbb_access_admin', 'openbb_manage_rules' ),
			)
		);

		$grantable = array_diff( self::role_caps(), array( self::UNGRANTABLE_CAP ) );
		$valid     = array();
		foreach ( is_array( $grants ) ? $grants : array() as $grant => $caps ) {
			if ( is_string( $grant ) && sanitize_key( $grant ) === $grant ) {
				$valid[ $grant ] = array_values( array_intersect( array_unique( (array) $caps ), $grantable ) );
			}
		}

		return $valid;
	}

	/**
	 * Translated role labels. Only call from UI code; translations aren't available before 'init'.
	 */
	public static function role_labels(): array {
		return array(
			self::ROLE_APPLICANT   => __( 'Applicant', 'open-bulk-buying' ),
			self::ROLE_MEMBER      => __( 'Member', 'open-bulk-buying' ),
			self::ROLE_COORDINATOR => __( 'Coordinator', 'open-bulk-buying' ),
			self::ROLE_MANAGER     => __( 'Manager', 'open-bulk-buying' ),
			self::ROLE_INACTIVE    => __( 'Inactive', 'open-bulk-buying' ),
		);
	}

	/**
	 * Translated grant labels; grants added by filter fall back to their key.
	 */
	public static function grant_labels(): array {
		$labels = array(
			'aid_reviewer'        => __( 'Aid reviewer', 'open-bulk-buying' ),
			'distribution_helper' => __( 'Distribution helper', 'open-bulk-buying' ),
			'rules_editor'        => __( 'Rules editor', 'open-bulk-buying' ),
		);

		$all = array();
		foreach ( array_keys( self::grants() ) as $grant ) {
			$all[ $grant ] = $labels[ $grant ] ?? $grant;
		}

		return apply_filters( 'openbb_grant_labels', $all );
	}

	/**
	 * Every capability any co-op role has.
	 */
	public static function role_caps(): array {
		return array_values( array_unique( array_merge( ...array_values( self::coop_roles() ) ) ) );
	}

	/**
	 * What users with manage_options get: every co-op capability except ordering, which
	 * needs a co-op role so that site admins who aren't members don't show up as members.
	 */
	public static function admin_caps(): array {
		return array_values( array_diff( array_merge( self::role_caps(), ...array_values( self::grants() ) ), array( 'openbb_place_orders' ) ) );
	}

	public static function sanitize_role( $role ): string {
		return is_string( $role ) && isset( self::coop_roles()[ $role ] ) ? $role : '';
	}

	/**
	 * Known grants only, unique and sorted so equal sets compare equal.
	 */
	public static function sanitize_grants( $grants ): array {
		$grants = array_values( array_unique( array_intersect( array_filter( (array) $grants, 'is_string' ), array_keys( self::grants() ) ) ) );
		sort( $grants );

		return $grants;
	}

	public static function get_coop_role( int $user_id ): string {
		return self::sanitize_role( openbb_get_member_field( $user_id, 'coop_role' ) );
	}

	public static function get_grants( int $user_id ): array {
		return self::sanitize_grants( openbb_get_member_field( $user_id, 'grants' ) );
	}

	/**
	 * Co-op capabilities for a user, on top of whatever their WordPress role gives them.
	 * Grants only count while the co-op role is active.
	 */
	public static function get_coop_caps( int $user_id, bool $is_admin = false ): array {
		$role = self::get_coop_role( $user_id );
		$caps = self::coop_roles()[ $role ] ?? array();

		if ( in_array( $role, self::ACTIVE_ROLES, true ) ) {
			$grants = self::grants();
			foreach ( self::get_grants( $user_id ) as $grant ) {
				$caps = array_merge( $caps, $grants[ $grant ] );
			}
		}

		if ( $is_admin ) {
			$caps = array_merge( $caps, self::admin_caps() );
		}

		return array_values( array_unique( $caps ) );
	}

	/**
	 * Changes a user's co-op role on behalf of the current user. The first active role
	 * sets joined_at. Fires 'openbb_coop_role_changed'.
	 *
	 * @return true|WP_Error
	 */
	public static function set_coop_role( int $user_id, string $role ) {
		$role = self::sanitize_role( $role );
		if ( '' === $role || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'openbb_invalid_coop_role', __( 'That co-op role or user does not exist.', 'open-bulk-buying' ) );
		}

		if ( ! current_user_can( 'openbb_set_coop_role', $user_id, $role ) ) {
			return new WP_Error( 'openbb_forbidden', __( 'You are not allowed to give this person that co-op role.', 'open-bulk-buying' ) );
		}

		$old_role = self::get_coop_role( $user_id );
		if ( $old_role === $role ) {
			return true;
		}

		if ( self::ROLE_INACTIVE === $old_role && in_array( $role, self::STAFF_ROLES, true ) ) {
			return new WP_Error( 'openbb_reactivate_first', __( 'Reactivate this person as a member before giving them a staff role.', 'open-bulk-buying' ) );
		}

		if ( ! openbb_update_member_field( $user_id, 'coop_role', $role ) ) {
			return new WP_Error( 'openbb_save_failed', __( 'The co-op role could not be saved.', 'open-bulk-buying' ) );
		}

		if ( in_array( $role, self::ACTIVE_ROLES, true ) && '' === (string) openbb_get_member_field( $user_id, 'joined_at' ) ) {
			if ( ! openbb_update_member_field( $user_id, 'joined_at', current_time( 'mysql', true ) ) ) {
				$restored = openbb_update_member_field( $user_id, 'coop_role', $old_role );
				return new WP_Error(
					'openbb_save_failed',
					$restored
						? __( 'The joining date could not be saved. The co-op role was not changed.', 'open-bulk-buying' )
						: __( 'The co-op role changed, but the joining date could not be saved or the role restored. Please correct this person\'s membership before continuing.', 'open-bulk-buying' )
				);
			}
		}

		/**
		 * @param int    $user_id  User whose role changed.
		 * @param string $role     New co-op role.
		 * @param string $old_role Previous co-op role; '' if none.
		 * @param int    $actor_id User who made the change.
		 */
		do_action( 'openbb_coop_role_changed', $user_id, $role, $old_role, get_current_user_id() );

		return true;
	}

	/**
	 * Replaces a user's grants on behalf of the current user. Fires 'openbb_grants_changed'.
	 *
	 * @param string[] $grants
	 * @return true|WP_Error
	 */
	public static function set_grants( int $user_id, array $grants ) {
		if ( count( array_filter( $grants, 'is_string' ) ) !== count( $grants ) ) {
			return new WP_Error( 'openbb_invalid_grant', __( 'Grants must be a list of grant names.', 'open-bulk-buying' ) );
		}

		$sanitized = self::sanitize_grants( $grants );
		if ( count( $sanitized ) !== count( array_unique( $grants ) ) || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'openbb_invalid_grant', __( 'That grant or user does not exist.', 'open-bulk-buying' ) );
		}

		if ( ! current_user_can( 'openbb_set_grants', $user_id ) ) {
			return new WP_Error( 'openbb_forbidden', __( 'You are not allowed to change this person\'s grants.', 'open-bulk-buying' ) );
		}

		$old_grants = self::get_grants( $user_id );
		if ( $old_grants === $sanitized ) {
			return true;
		}

		if ( ! openbb_update_member_field( $user_id, 'grants', $sanitized ) ) {
			return new WP_Error( 'openbb_save_failed', __( 'The grants could not be saved.', 'open-bulk-buying' ) );
		}

		/**
		 * @param int      $user_id    User whose grants changed.
		 * @param string[] $grants     New grants.
		 * @param string[] $old_grants Previous grants.
		 * @param int      $actor_id   User who made the change.
		 */
		do_action( 'openbb_grants_changed', $user_id, $sanitized, $old_grants, get_current_user_id() );

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
	 * Narrow capabilities that code checks by name but that resolve to a broader primitive
	 * capability, so a future per-role matrix can split them without changing any checks.
	 */
	const NAMED_CAPS = array(
		'openbb_manage_profile_fields' => 'openbb_manage_settings',
	);

	/**
	 * Resolves the object-level co-op capabilities (spec §4.2) and NAMED_CAPS into primitive ones.
	 *
	 * Hooked to 'map_meta_cap'.
	 */
	public static function filter_map_meta_cap( $caps, $cap, $user_id, $args ) {
		$user_id = (int) $user_id;

		if ( isset( self::NAMED_CAPS[ $cap ] ) ) {
			return array( self::NAMED_CAPS[ $cap ] );
		}

		switch ( $cap ) {
			case 'openbb_view_order':
			case 'openbb_edit_own_order':
			case 'openbb_edit_order':
			case 'openbb_view_order_aid':
			case 'openbb_decide_order_aid':
				$order = isset( $args[0] ) ? OPENBB_Orders::get( (int) $args[0] ) : null;
				return $order ? self::map_order_cap( $cap, $order, $user_id ) : array( 'do_not_allow' );

			case 'openbb_view_member':
			case 'openbb_edit_member':
			case 'openbb_set_coop_role':
			case 'openbb_set_grants':
				$target_id = isset( $args[0] ) ? (int) $args[0] : 0;
				if ( ! $target_id || ! get_userdata( $target_id ) ) {
					return array( 'do_not_allow' );
				}
				return self::map_member_cap( $cap, $target_id, $user_id, $args[1] ?? null );
		}

		return $caps;
	}

	private static function map_order_cap( string $cap, OPENBB_Order $order, int $user_id ): array {
		$is_owner = $user_id && $order->user_id === $user_id;

		switch ( $cap ) {
			case 'openbb_view_order':
				if ( $is_owner ) {
					return array( 'read' );
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
				return $is_owner ? array( 'read' ) : array( 'openbb_view_aid' );

			case 'openbb_decide_order_aid':
				// Nobody decides aid on their own order.
				return $is_owner ? array( 'do_not_allow' ) : array( 'openbb_decide_aid' );
		}

		return array( 'do_not_allow' );
	}

	private static function map_member_cap( string $cap, int $target_id, int $user_id, $new_role ): array {
		$is_self = $user_id && $target_id === $user_id;

		switch ( $cap ) {
			case 'openbb_view_member':
				return $is_self ? array( 'read' ) : array( 'openbb_view_members' );

			case 'openbb_edit_member':
				if ( $is_self ) {
					return array( 'read' );
				}
				// Never let someone edit a person with powers they don't have themselves.
				foreach ( array( 'manage_options', 'openbb_manage_access' ) as $guarded ) {
					if ( user_can( $target_id, $guarded ) && ! user_can( $user_id, $guarded ) ) {
						return array( 'do_not_allow' );
					}
				}
				return array( 'openbb_edit_members' );

			case 'openbb_set_coop_role':
			case 'openbb_set_grants':
				// Administrators may set their own co-op access: their admin powers don't come from it.
				if ( ( $is_self && ! user_can( $user_id, 'manage_options' ) ) || self::is_protected_from( $target_id, $user_id ) ) {
					return array( 'do_not_allow' );
				}
				if ( 'openbb_set_grants' === $cap ) {
					return array( 'openbb_manage_access' );
				}
				$new_role = self::sanitize_role( $new_role );
				if ( '' === $new_role ) {
					return array( 'do_not_allow' );
				}
				$touches_staff = in_array( $new_role, self::STAFF_ROLES, true ) || in_array( self::get_coop_role( $target_id ), self::STAFF_ROLES, true );
				return array( $touches_staff ? 'openbb_manage_access' : 'openbb_edit_members' );
		}

		return array( 'do_not_allow' );
	}

	/**
	 * Only Administrators may change the co-op access of people who can manage access or the site.
	 */
	private static function is_protected_from( int $target_id, int $user_id ): bool {
		return ( user_can( $target_id, 'openbb_manage_access' ) || user_can( $target_id, 'manage_options' ) )
			&& ! user_can( $user_id, 'manage_options' );
	}
}
