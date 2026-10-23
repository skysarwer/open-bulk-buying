<?php
/**
 * Team roles: the capability registry, the roles shipped in code, and each site's
 * changes to them. Every active member can order; team roles only add admin permissions.
 *
 * The option stores only what differs from the code defaults, so a co-op that never
 * changed its roles stores nothing and picks up new defaults when the plugin updates.
 * Any save that brings the config back to the defaults deletes the option.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Roles {

	const OPTION        = 'openbb_roles';
	const CUSTOM_PREFIX = 'custom_';
	const LABEL_MAX     = 60;

	/** @var array<int, array> Effective config per site. */
	private static $config = array();

	/** @var array|null */
	private static $registry = null;

	/**
	 * Capability => definition, in display order.
	 *
	 * - group:    Section of the permission matrix.
	 * - parent:   A capability with no value of its own follows its parent, so splitting
	 *             a capability out of a broader one doesn't change existing roles.
	 * - requires: Capabilities a role must also have for this one to be usable.
	 * - wp_caps:  WordPress capabilities that come with it.
	 *
	 * Extendable with 'openbb_capabilities'. Keys must start with 'openbb_'.
	 */
	public static function capabilities(): array {
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		$registry = array(
			'openbb_manage_products'       => array(
				'group'   => 'catalog',
				'wp_caps' => array( 'upload_files' ),
			),
			'openbb_manage_inventory'      => array( 'group' => 'catalog' ),
			'openbb_manage_cycles'         => array( 'group' => 'catalog' ),
			'openbb_view_orders'           => array( 'group' => 'orders' ),
			'openbb_edit_orders'           => array(
				'group'    => 'orders',
				'requires' => array( 'openbb_view_orders' ),
			),
			'openbb_process_orders'        => array( 'group' => 'orders' ),
			'openbb_distribute_orders'     => array( 'group' => 'orders' ),
			'openbb_record_payments'       => array( 'group' => 'orders' ),
			'openbb_view_members'          => array( 'group' => 'members' ),
			'openbb_edit_members'          => array(
				'group'    => 'members',
				'requires' => array( 'openbb_view_members' ),
			),
			'openbb_create_members'        => array(
				'group'    => 'members',
				'parent'   => 'openbb_edit_members',
				'requires' => array( 'openbb_edit_members' ),
			),
			'openbb_export_data'           => array( 'group' => 'members' ),
			'openbb_view_aid'              => array( 'group' => 'aid' ),
			'openbb_decide_aid'            => array(
				'group'    => 'aid',
				'requires' => array( 'openbb_view_aid' ),
			),
			'openbb_manage_rules'          => array( 'group' => 'administration' ),
			'openbb_manage_settings'       => array( 'group' => 'administration' ),
			'openbb_manage_profile_fields' => array(
				'group'  => 'administration',
				'parent' => 'openbb_manage_settings',
			),
			'openbb_manage_access'         => array(
				'group'    => 'administration',
				'requires' => array( 'openbb_view_members' ),
			),
		);

		$filtered = apply_filters( 'openbb_capabilities', $registry );
		$filtered = is_array( $filtered ) ? $filtered : array();

		$valid = array();
		foreach ( $filtered as $cap => $def ) {
			if ( ! is_string( $cap ) || 0 !== strpos( $cap, 'openbb_' ) || sanitize_key( $cap ) !== $cap || ! is_array( $def ) ) {
				continue;
			}
			$valid[ $cap ] = array(
				'group'    => sanitize_key( $def['group'] ?? 'other' ),
				'parent'   => is_string( $def['parent'] ?? null ) ? $def['parent'] : '',
				'requires' => array_values( array_filter( (array) ( $def['requires'] ?? array() ), 'is_string' ) ),
				'wp_caps'  => array_values( array_filter( (array) ( $def['wp_caps'] ?? array() ), 'is_string' ) ),
			);
		}

		// Parents must be top-level capabilities, so values resolve in one pass.
		foreach ( $valid as $cap => $def ) {
			if ( '' !== $def['parent'] && ( ! isset( $valid[ $def['parent'] ] ) || '' !== $valid[ $def['parent'] ]['parent'] ) ) {
				$valid[ $cap ]['parent'] = '';
			}
			$valid[ $cap ]['requires'] = array_values( array_intersect( $def['requires'], array_diff( array_keys( $valid ), array( $cap ) ) ) );
		}

		self::$registry = $valid;

		return $valid;
	}

	/**
	 * Translated capability labels and descriptions, for UI code only.
	 */
	public static function capability_labels(): array {
		$labels = array(
			'openbb_manage_products'       => array( __( 'Manage products', 'open-bulk-buying' ), __( 'Create and edit products, categories and suppliers, and upload images.', 'open-bulk-buying' ) ),
			'openbb_manage_inventory'      => array( __( 'Manage inventory', 'open-bulk-buying' ), __( 'Record stock received, counted and adjusted.', 'open-bulk-buying' ) ),
			'openbb_manage_cycles'         => array( __( 'Manage cycles', 'open-bulk-buying' ), __( 'Create cycles and move them through their stages.', 'open-bulk-buying' ) ),
			'openbb_view_orders'           => array( __( 'View all orders', 'open-bulk-buying' ), __( 'See every member\'s orders.', 'open-bulk-buying' ) ),
			'openbb_edit_orders'           => array( __( 'Edit orders', 'open-bulk-buying' ), __( 'Change, cancel or create orders for members.', 'open-bulk-buying' ) ),
			'openbb_process_orders'        => array( __( 'Process orders', 'open-bulk-buying' ), __( 'Record prepared quantities and shortages for the cycle being processed.', 'open-bulk-buying' ) ),
			'openbb_distribute_orders'     => array( __( 'Distribute orders', 'open-bulk-buying' ), __( 'Hand out orders for the cycle being distributed.', 'open-bulk-buying' ) ),
			'openbb_record_payments'       => array( __( 'Record payments', 'open-bulk-buying' ), __( 'Mark orders as paid.', 'open-bulk-buying' ) ),
			'openbb_view_members'          => array( __( 'View members', 'open-bulk-buying' ), __( 'See the member list and contact details.', 'open-bulk-buying' ) ),
			'openbb_edit_members'          => array( __( 'Edit members', 'open-bulk-buying' ), __( 'Edit member details and approve, decline, deactivate or reactivate members.', 'open-bulk-buying' ) ),
			'openbb_create_members'        => array( __( 'Add members', 'open-bulk-buying' ), __( 'Add people as members without them signing up.', 'open-bulk-buying' ) ),
			'openbb_export_data'           => array( __( 'Export personal data', 'open-bulk-buying' ), __( 'Download files that contain members\' personal data.', 'open-bulk-buying' ) ),
			'openbb_view_aid'              => array( __( 'View aid requests', 'open-bulk-buying' ), __( 'See aid requested on orders.', 'open-bulk-buying' ) ),
			'openbb_decide_aid'            => array( __( 'Decide aid', 'open-bulk-buying' ), __( 'Approve or decline aid requests.', 'open-bulk-buying' ) ),
			'openbb_manage_rules'          => array( __( 'Manage rules', 'open-bulk-buying' ), __( 'Edit the co-op rules members agree to.', 'open-bulk-buying' ) ),
			'openbb_manage_settings'       => array( __( 'Manage settings', 'open-bulk-buying' ), __( 'Change the co-op\'s general settings.', 'open-bulk-buying' ) ),
			'openbb_manage_profile_fields' => array( __( 'Manage profile fields', 'open-bulk-buying' ), __( 'Add and change the questions members answer about themselves.', 'open-bulk-buying' ) ),
			'openbb_manage_access'         => array( __( 'Manage team roles', 'open-bulk-buying' ), __( 'Edit team roles and give them to members.', 'open-bulk-buying' ) ),
		);

		$all = array();
		foreach ( array_keys( self::capabilities() ) as $cap ) {
			$all[ $cap ] = array(
				'label'       => $labels[ $cap ][0] ?? $cap,
				'description' => $labels[ $cap ][1] ?? '',
			);
		}

		return apply_filters( 'openbb_capability_labels', $all );
	}

	/**
	 * Translated matrix section labels, for UI code only.
	 */
	public static function group_labels(): array {
		return array(
			'catalog'        => __( 'Products and cycles', 'open-bulk-buying' ),
			'orders'         => __( 'Orders', 'open-bulk-buying' ),
			'members'        => __( 'Members', 'open-bulk-buying' ),
			'aid'            => __( 'Aid', 'open-bulk-buying' ),
			'administration' => __( 'Administration', 'open-bulk-buying' ),
			'other'          => __( 'Other', 'open-bulk-buying' ),
		);
	}

	/**
	 * Role => capabilities shipped in code. Extendable with 'openbb_default_roles';
	 * keys can't start with CUSTOM_PREFIX.
	 */
	public static function default_roles(): array {
		$coordinator = array(
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
		);

		$roles = apply_filters(
			'openbb_default_roles',
			array(
				'coordinator'         => $coordinator,
				'manager'             => array_merge(
					$coordinator,
					array(
						'openbb_view_aid',
						'openbb_decide_aid',
						'openbb_manage_rules',
						'openbb_manage_settings',
						'openbb_manage_access',
					)
				),
				'aid_reviewer'        => array( 'openbb_view_aid', 'openbb_decide_aid' ),
				'distribution_helper' => array( 'openbb_distribute_orders', 'openbb_record_payments' ),
				'rules_editor'        => array( 'openbb_manage_rules' ),
			)
		);

		$valid = array();
		foreach ( is_array( $roles ) ? $roles : array() as $key => $caps ) {
			if ( is_string( $key ) && sanitize_key( $key ) === $key && 0 !== strpos( $key, self::CUSTOM_PREFIX ) ) {
				$valid[ $key ] = array_values( array_intersect( array_keys( self::capabilities() ), (array) $caps ) );
			}
		}

		return $valid;
	}

	/**
	 * Translated labels of the built-in roles, for UI code only.
	 */
	public static function default_role_labels(): array {
		$labels = array(
			'coordinator'         => __( 'Coordinator', 'open-bulk-buying' ),
			'manager'             => __( 'Manager', 'open-bulk-buying' ),
			'aid_reviewer'        => __( 'Aid reviewer', 'open-bulk-buying' ),
			'distribution_helper' => __( 'Distribution helper', 'open-bulk-buying' ),
			'rules_editor'        => __( 'Rules editor', 'open-bulk-buying' ),
		);

		$all = array();
		foreach ( array_keys( self::default_roles() ) as $key ) {
			$all[ $key ] = $labels[ $key ] ?? $key;
		}

		return apply_filters( 'openbb_default_role_labels', $all );
	}

	/**
	 * The stored changes: array( 'roles' => key => entry ), where an
	 * entry is array( 'label' => string, 'caps' => cap => bool ) or array( 'deleted' => true ).
	 */
	public static function get_stored(): array {
		$stored = get_option( self::OPTION, array() );

		return self::sanitize_stored( is_array( $stored ) ? $stored : array() );
	}

	private static function sanitize_stored( array $stored ): array {
		$clean = array( 'roles' => array() );

		foreach ( (array) ( $stored['roles'] ?? array() ) as $key => $entry ) {
			if ( ! is_string( $key ) || sanitize_key( $key ) !== $key || ! is_array( $entry ) ) {
				continue;
			}
			if ( ! empty( $entry['deleted'] ) ) {
				$clean['roles'][ $key ] = array( 'deleted' => true );
				continue;
			}
			$caps = array();
			foreach ( (array) ( $entry['caps'] ?? array() ) as $cap => $value ) {
				if ( is_string( $cap ) ) {
					$caps[ $cap ] = (bool) $value;
				}
			}
			$clean['roles'][ $key ] = array(
				'label' => is_string( $entry['label'] ?? null ) ? $entry['label'] : '',
				'caps'  => $caps,
			);
		}

		return $clean;
	}

	/**
	 * Identifies the stored config, to detect saves based on an outdated copy. A hash of
	 * the content rather than a counter, so deleting the option (back to defaults) can't
	 * make an old copy look current.
	 */
	public static function revision(): string {
		$stored = get_option( self::OPTION, false );

		return false === $stored ? '' : md5( (string) wp_json_encode( $stored ) );
	}

	/**
	 * The roles in effect on this site:
	 * array( 'roles' => key => array( label, caps, custom ), 'deleted' => keys ).
	 * label is '' for built-in roles that keep their translated label.
	 */
	public static function get_config(): array {
		$blog_id = get_current_blog_id();
		if ( ! isset( self::$config[ $blog_id ] ) ) {
			self::$config[ $blog_id ] = self::build( self::get_stored() );
		}

		return self::$config[ $blog_id ];
	}

	public static function flush(): void {
		self::$config   = array();
		self::$registry = null;
		OPENBB_Access::flush_cache();
	}

	private static function build( array $stored ): array {
		$defaults = self::default_roles();
		$roles    = array();
		$deleted  = array();

		foreach ( $defaults as $key => $caps ) {
			$entry = $stored['roles'][ $key ] ?? null;
			if ( ! empty( $entry['deleted'] ) ) {
				$deleted[] = $key;
				continue;
			}
			$roles[ $key ] = array(
				'label'  => $entry['label'] ?? '',
				'caps'   => self::resolve( $caps, $entry['caps'] ?? array() ),
				'custom' => false,
			);
		}

		foreach ( $stored['roles'] as $key => $entry ) {
			if ( 0 === strpos( $key, self::CUSTOM_PREFIX ) && empty( $entry['deleted'] ) ) {
				$roles[ $key ] = array(
					'label'  => '' !== $entry['label'] ? $entry['label'] : $key,
					'caps'   => self::resolve( array(), $entry['caps'] ),
					'custom' => true,
				);
			}
		}

		return array(
			'roles'   => $roles,
			'deleted' => $deleted,
		);
	}

	/**
	 * Capabilities from a base list plus overrides. A capability without an override
	 * follows its parent unless the base lists it.
	 */
	private static function resolve( array $base, array $overrides ): array {
		$values = array();
		foreach ( self::ordered_caps() as $cap => $def ) {
			if ( array_key_exists( $cap, $overrides ) ) {
				$values[ $cap ] = (bool) $overrides[ $cap ];
			} else {
				$values[ $cap ] = self::inherited( $cap, $def, $base, $values );
			}
		}

		return array_keys( array_filter( $values ) );
	}

	/**
	 * The overrides that turn $base into $caps.
	 */
	private static function diff( array $base, array $caps ): array {
		$values    = array();
		$overrides = array();
		foreach ( self::ordered_caps() as $cap => $def ) {
			$values[ $cap ] = in_array( $cap, $caps, true );
			if ( $values[ $cap ] !== self::inherited( $cap, $def, $base, $values ) ) {
				$overrides[ $cap ] = $values[ $cap ];
			}
		}

		return $overrides;
	}

	private static function inherited( string $cap, array $def, array $base, array $values ): bool {
		return in_array( $cap, $base, true ) || ( '' !== $def['parent'] && ! empty( $values[ $def['parent'] ] ) );
	}

	/**
	 * Top-level capabilities first, so parents resolve before their children.
	 */
	private static function ordered_caps(): array {
		$caps = self::capabilities();

		return array_filter(
			$caps,
			static function ( array $def ): bool {
				return '' === $def['parent'];
			}
		) + $caps;
	}

	public static function exists( string $key ): bool {
		return isset( self::get_config()['roles'][ $key ] );
	}

	public static function role_caps( string $key ): array {
		return self::get_config()['roles'][ $key ]['caps'] ?? array();
	}

	/**
	 * Role => translated label, in display order. UI code only.
	 */
	public static function labels(): array {
		$defaults = self::default_role_labels();
		$labels   = array();
		foreach ( self::get_config()['roles'] as $key => $role ) {
			$labels[ $key ] = '' !== $role['label'] ? $role['label'] : ( $defaults[ $key ] ?? $key );
		}

		return $labels;
	}

	/**
	 * Everything the Roles settings screen needs.
	 */
	public static function describe(): array {
		$config        = self::get_config();
		$labels        = self::labels();
		$stored        = self::get_stored();
		$default_roles = self::default_roles();
		$default_names = self::default_role_labels();
		$counts        = self::member_counts();

		$roles = array();
		foreach ( $config['roles'] as $key => $role ) {
			$roles[] = array(
				'key'      => $key,
				'label'    => $labels[ $key ],
				'caps'     => $role['caps'],
				'custom'   => $role['custom'],
				'modified' => ! $role['custom'] && isset( $stored['roles'][ $key ] ),
				'members'  => $counts[ $key ] ?? 0,
				'editable' => true === self::check_role_change( $key, $role['caps'], $role['caps'] ),
			);
		}

		$deleted = array();
		foreach ( $config['deleted'] as $key ) {
			$deleted[] = array(
				'key'   => $key,
				'label' => $default_names[ $key ] ?? $key,
				'caps'  => self::resolve( $default_roles[ $key ], array() ),
			);
		}

		$cap_labels   = self::capability_labels();
		$capabilities = array();
		foreach ( self::capabilities() as $cap => $def ) {
			$capabilities[] = array(
				'key'         => $cap,
				'label'       => $cap_labels[ $cap ]['label'] ?? $cap,
				'description' => $cap_labels[ $cap ]['description'] ?? '',
				'group'       => $def['group'],
				'parent'      => $def['parent'],
				'requires'    => $def['requires'],
				'held'        => current_user_can( $cap ),
			);
		}

		$groups = array();
		foreach ( self::group_labels() as $group => $label ) {
			$groups[] = array(
				'key'   => $group,
				'label' => $label,
			);
		}

		return array(
			'revision'     => self::revision(),
			'is_default'   => '' === self::revision(),
			'roles'        => $roles,
			'deleted'      => $deleted,
			'capabilities' => $capabilities,
			'groups'       => $groups,
			'is_admin'     => current_user_can( 'manage_options' ),
		);
	}

	/**
	 * Role => number of people on this site who hold it.
	 */
	private static function member_counts(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value, COUNT(*) AS total FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value",
				openbb_member_meta_key( 'roles' )
			)
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row->meta_value ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Saves a role's label and capabilities. Labels of built-in roles are only stored
	 * when they differ from the translated default.
	 *
	 * @param array $data label (string), caps (string[]).
	 * @return array|WP_Error describe() after saving.
	 */
	public static function save_role( string $key, array $data, string $base_revision ) {
		if ( ! self::exists( $key ) ) {
			return self::not_found();
		}

		$input = self::validate_input( $data, $key );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$stored = self::get_stored();
		if ( isset( self::default_roles()[ $key ] ) ) {
			$default_label = self::default_role_labels()[ $key ] ?? $key;
			$entry         = array(
				'label' => $input['label'] === $default_label ? '' : $input['label'],
				'caps'  => self::diff( self::default_roles()[ $key ], $input['caps'] ),
			);
			if ( '' === $entry['label'] && ! $entry['caps'] ) {
				unset( $stored['roles'][ $key ] );
			} else {
				$stored['roles'][ $key ] = $entry;
			}
		} else {
			$stored['roles'][ $key ] = array(
				'label' => $input['label'],
				'caps'  => self::diff( array(), $input['caps'] ),
			);
		}

		return self::commit( $stored, $base_revision );
	}

	/**
	 * Adds a custom role. Its key is made from the label and never changes.
	 *
	 * @return array|WP_Error describe() after saving, plus 'key'.
	 */
	public static function create_role( array $data, string $base_revision ) {
		$input = self::validate_input( $data, '' );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$stored = self::get_stored();
		$slug   = substr( str_replace( '-', '_', sanitize_title( remove_accents( $input['label'] ) ) ), 0, 40 );
		$slug   = '' !== trim( $slug, '_' ) ? trim( $slug, '_' ) : 'role';
		$key    = self::CUSTOM_PREFIX . $slug;
		for ( $i = 2; isset( $stored['roles'][ $key ] ); $i++ ) {
			$key = self::CUSTOM_PREFIX . $slug . '_' . $i;
		}

		$stored['roles'][ $key ] = array(
			'label' => $input['label'],
			'caps'  => self::diff( array(), $input['caps'] ),
		);

		$result = self::commit( $stored, $base_revision );

		return is_wp_error( $result ) ? $result : $result + array( 'key' => $key );
	}

	/**
	 * Deletes a role and takes it away from everyone who holds it. Default roles are
	 * remembered as deleted, so they don't come back on update; reset_role() restores them.
	 *
	 * @return array|WP_Error
	 */
	public static function delete_role( string $key, string $base_revision ) {
		if ( ! self::exists( $key ) ) {
			return self::not_found();
		}

		$stored = self::get_stored();
		if ( isset( self::default_roles()[ $key ] ) ) {
			$stored['roles'][ $key ] = array( 'deleted' => true );
		} else {
			unset( $stored['roles'][ $key ] );
		}

		return self::commit( $stored, $base_revision );
	}

	/**
	 * Puts a built-in role back as shipped, including one that was deleted.
	 *
	 * @return array|WP_Error
	 */
	public static function reset_role( string $key, string $base_revision ) {
		if ( ! isset( self::default_roles()[ $key ] ) ) {
			return self::not_found();
		}

		$stored = self::get_stored();
		unset( $stored['roles'][ $key ] );

		return self::commit( $stored, $base_revision );
	}

	/**
	 * Puts every role back as shipped. Custom roles are deleted.
	 *
	 * @return array|WP_Error
	 */
	public static function reset_all( string $base_revision ) {
		return self::commit( array( 'roles' => array() ), $base_revision );
	}

	/**
	 * @return array{label: string, caps: string[]}|WP_Error
	 */
	private static function validate_input( array $data, string $key ) {
		$label = is_string( $data['label'] ?? null ) ? trim( sanitize_text_field( $data['label'] ) ) : '';
		if ( '' === $label ) {
			return self::error( 'openbb_role_label_required', __( 'Give the role a name.', 'open-bulk-buying' ) );
		}
		if ( mb_strlen( $label ) > self::LABEL_MAX ) {
			/* translators: %d: maximum number of characters. */
			return self::error( 'openbb_role_label_too_long', sprintf( __( 'Role names can be at most %d characters.', 'open-bulk-buying' ), self::LABEL_MAX ) );
		}
		foreach ( self::labels() as $other => $other_label ) {
			if ( $other !== $key && 0 === strcasecmp( $other_label, $label ) ) {
				return self::error( 'openbb_role_label_taken', __( 'Another role already has that name.', 'open-bulk-buying' ) );
			}
		}

		$caps = $data['caps'] ?? array();
		if ( ! is_array( $caps ) || count( array_filter( $caps, 'is_string' ) ) !== count( $caps ) ) {
			return self::error( 'openbb_role_invalid_caps', __( 'Permissions must be a list of permission names.', 'open-bulk-buying' ) );
		}
		$unknown = array_diff( $caps, array_keys( self::capabilities() ) );
		if ( $unknown ) {
			/* translators: %s: permission names. */
			return self::error( 'openbb_role_invalid_caps', sprintf( __( 'Unknown permissions: %s', 'open-bulk-buying' ), implode( ', ', $unknown ) ) );
		}

		return array(
			'label' => $label,
			'caps'  => array_values( array_unique( $caps ) ),
		);
	}

	/**
	 * Validates the result of a change, checks the current user may make it, and saves.
	 *
	 * @return array|WP_Error describe() after saving.
	 */
	private static function commit( array $stored, string $base_revision ) {
		if ( ! current_user_can( 'openbb_manage_access' ) ) {
			return new WP_Error( 'openbb_forbidden', __( 'You are not allowed to change roles.', 'open-bulk-buying' ), array( 'status' => 403 ) );
		}
		if ( self::revision() !== $base_revision ) {
			return new WP_Error(
				'openbb_roles_stale',
				__( 'Someone else changed the team roles. Reload to see the latest version.', 'open-bulk-buying' ),
				array( 'status' => 409 )
			);
		}

		$stored = self::normalize( $stored );
		$old    = self::get_config();
		$new    = self::build( $stored );

		$valid = self::validate_config( $new );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$old_labels = self::labels();
		foreach ( array_unique( array_merge( array_keys( $old['roles'] ), array_keys( $new['roles'] ) ) ) as $key ) {
			$before = $old['roles'][ $key ] ?? null;
			$after  = $new['roles'][ $key ] ?? null;
			if ( $before === $after ) {
				continue;
			}
			$allowed = self::check_role_change( $key, $before['caps'] ?? array(), $after['caps'] ?? array() );
			if ( is_wp_error( $allowed ) ) {
				/* translators: 1: role name, 2: reason. */
				return new WP_Error( $allowed->get_error_code(), sprintf( __( '%1$s: %2$s', 'open-bulk-buying' ), $old_labels[ $key ] ?? $after['label'], $allowed->get_error_message() ), array( 'status' => 403 ) );
			}
		}

		// Back to the defaults: store nothing, so future default changes reach this site.
		$changed = (bool) $stored['roles'];
		if ( $changed ) {
			update_option( self::OPTION, $stored, true );
		} else {
			delete_option( self::OPTION );
		}
		if ( get_option( self::OPTION, false ) !== ( $changed ? $stored : false ) ) {
			return new WP_Error( 'openbb_roles_save_failed', __( 'The roles could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}

		self::flush();

		// Nobody keeps a role that no longer exists.
		foreach ( array_diff( array_keys( $old['roles'] ), array_keys( $new['roles'] ) ) as $removed ) {
			delete_metadata( 'user', 0, openbb_member_meta_key( 'roles' ), $removed, true );
		}
		OPENBB_Access::flush_cache();

		/**
		 * @param array $new      The roles now in effect (OPENBB_Roles::get_config()).
		 * @param array $old      The roles in effect before.
		 * @param int   $actor_id User who made the change.
		 */
		do_action( 'openbb_roles_config_changed', $new, $old, get_current_user_id() );

		return self::describe();
	}

	/**
	 * Drops anything equal to the code defaults so the option only holds real changes.
	 */
	private static function normalize( array $stored ): array {
		$stored   = self::sanitize_stored( $stored );
		$defaults = self::default_roles();

		foreach ( $stored['roles'] as $key => $entry ) {
			$is_default = isset( $defaults[ $key ] );
			if ( ! $is_default && 0 !== strpos( $key, self::CUSTOM_PREFIX ) ) {
				unset( $stored['roles'][ $key ] );
				continue;
			}
			if ( ! empty( $entry['deleted'] ) ) {
				if ( ! $is_default ) {
					unset( $stored['roles'][ $key ] );
				}
				continue;
			}
			$entry['caps'] = array_intersect_key( $entry['caps'], self::capabilities() );
			if ( $is_default && '' === $entry['label'] && ! $entry['caps'] ) {
				unset( $stored['roles'][ $key ] );
				continue;
			}
			$stored['roles'][ $key ] = $entry;
		}

		return $stored;
	}

	/**
	 * @return true|WP_Error
	 */
	private static function validate_config( array $config ) {
		$capabilities = self::capabilities();
		$cap_labels   = self::capability_labels();
		foreach ( $config['roles'] as $role ) {
			foreach ( $role['caps'] as $cap ) {
				$missing = array_diff( $capabilities[ $cap ]['requires'], $role['caps'] );
				if ( $missing ) {
					return self::error(
						'openbb_role_missing_requirement',
						sprintf(
							/* translators: 1: permission, 2: permissions it needs. */
							__( '“%1$s” also needs: %2$s.', 'open-bulk-buying' ),
							$cap_labels[ $cap ]['label'] ?? $cap,
							implode(
								', ',
								array_map(
									static function ( string $needed ) use ( $cap_labels ): string {
										return $cap_labels[ $needed ]['label'] ?? $needed;
									},
									$missing
								)
							)
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * Whether the current user may change a role from $before to $after capabilities.
	 * Administrators may change anything. Others can't change a role they hold, a role that
	 * can manage access, or give permissions they don't have.
	 *
	 * @return true|WP_Error
	 */
	public static function check_role_change( string $key, array $before, array $after ) {
		if ( ! current_user_can( 'openbb_manage_access' ) ) {
			return new WP_Error( 'openbb_forbidden', __( 'You are not allowed to change roles.', 'open-bulk-buying' ) );
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( in_array( $key, OPENBB_Access::get_roles( get_current_user_id() ), true ) ) {
			return new WP_Error( 'openbb_forbidden', __( 'You can\'t change a role you hold. Ask an administrator.', 'open-bulk-buying' ) );
		}
		if ( in_array( 'openbb_manage_access', array_merge( $before, $after ), true ) ) {
			return new WP_Error( 'openbb_forbidden', __( 'Only administrators can change roles that manage access.', 'open-bulk-buying' ) );
		}
		foreach ( array_diff( $after, $before ) as $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return new WP_Error( 'openbb_forbidden', __( 'You can only give permissions you have yourself.', 'open-bulk-buying' ) );
			}
		}

		return true;
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'openbb_role_not_found', __( 'That role doesn\'t exist.', 'open-bulk-buying' ), array( 'status' => 404 ) );
	}

	private static function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
