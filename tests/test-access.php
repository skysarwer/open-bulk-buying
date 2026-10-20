<?php
/**
 * Co-op roles, grants and capability resolution.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Access extends OPENBB_TestCase {

	const MEMBER_CAPS = array( 'read', 'openbb_place_orders' );

	const COORDINATOR_CAPS = array(
		'read',
		'openbb_place_orders',
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
	);

	const MANAGER_EXTRA_CAPS = array(
		'openbb_view_aid',
		'openbb_decide_aid',
		'openbb_manage_rules',
		'openbb_manage_settings',
		'openbb_manage_access',
	);

	/**
	 * The expected matrix, written out so the code can't drift from it unnoticed.
	 */
	private function expected_matrix(): array {
		return array(
			'applicant'   => array( 'read' ),
			'member'      => self::MEMBER_CAPS,
			'coordinator' => self::COORDINATOR_CAPS,
			'manager'     => array_merge( self::COORDINATOR_CAPS, self::MANAGER_EXTRA_CAPS ),
			'inactive'    => array( 'read' ),
		);
	}

	private function all_caps(): array {
		return array_merge( self::COORDINATOR_CAPS, self::MANAGER_EXTRA_CAPS );
	}

	public function test_role_definitions_match_spec(): void {
		$expected = $this->expected_matrix();
		$actual   = OPENBB_Access::coop_roles();

		$this->assertSame( array_keys( $expected ), array_keys( $actual ) );
		foreach ( $expected as $role => $caps ) {
			$this->assertEqualsCanonicalizing( $caps, $actual[ $role ], "Caps for {$role}" );
		}
	}

	/**
	 * Users with no WordPress role on the site, so every capability comes from the co-op role.
	 */
	public function test_each_role_resolves_exactly_its_caps(): void {
		foreach ( $this->expected_matrix() as $role => $caps ) {
			$user_id = $this->make_user( $role, '' );
			foreach ( $this->all_caps() as $cap ) {
				$this->assertSame( in_array( $cap, $caps, true ), user_can( $user_id, $cap ), "{$role} / {$cap}" );
			}
		}
	}

	public function test_user_without_coop_role_gets_no_coop_caps(): void {
		$user_id = $this->make_user( '', 'editor' );

		foreach ( array_merge( self::MEMBER_CAPS, array_slice( self::COORDINATOR_CAPS, 3 ), self::MANAGER_EXTRA_CAPS ) as $cap ) {
			if ( 'read' !== $cap ) {
				$this->assertFalse( user_can( $user_id, $cap ), $cap );
			}
		}
	}

	public function test_grants_add_caps_to_active_roles(): void {
		$coordinator = $this->make_user( 'coordinator', 'subscriber', array( 'aid_reviewer' ) );
		$helper      = $this->make_user( 'member', 'subscriber', array( 'distribution_helper' ) );
		$editor      = $this->make_user( 'member', 'subscriber', array( 'rules_editor' ) );

		$this->assertTrue( user_can( $coordinator, 'openbb_view_aid' ) );
		$this->assertTrue( user_can( $coordinator, 'openbb_decide_aid' ) );
		$this->assertFalse( user_can( $coordinator, 'openbb_manage_settings' ) );

		$this->assertTrue( user_can( $helper, 'openbb_access_admin' ) );
		$this->assertTrue( user_can( $helper, 'openbb_distribute_orders' ) );
		$this->assertTrue( user_can( $helper, 'openbb_record_payments' ) );
		$this->assertFalse( user_can( $helper, 'openbb_view_orders' ) );
		$this->assertFalse( user_can( $helper, 'openbb_view_members' ) );

		$this->assertTrue( user_can( $editor, 'openbb_manage_rules' ) );
		$this->assertFalse( user_can( $editor, 'openbb_manage_settings' ) );
	}

	public function test_grants_are_ignored_for_applicants_and_inactive_users(): void {
		foreach ( array( 'applicant', 'inactive' ) as $role ) {
			$user_id = $this->make_user( $role, 'subscriber', array( 'aid_reviewer', 'distribution_helper' ) );

			$this->assertFalse( user_can( $user_id, 'openbb_view_aid' ), $role );
			$this->assertFalse( user_can( $user_id, 'openbb_access_admin' ), $role );
			$this->assertFalse( user_can( $user_id, 'openbb_place_orders' ), $role );
		}
	}

	public function test_no_grant_can_include_manage_access_or_core_admin_caps(): void {
		add_filter(
			'openbb_grants',
			static function ( array $grants ): array {
				$grants['sneaky'] = array( 'openbb_manage_access', 'manage_options', 'edit_users', 'openbb_view_aid' );
				return $grants;
			}
		);

		$this->assertSame( array( 'openbb_view_aid' ), OPENBB_Access::grants()['sneaky'] );

		$user_id = $this->make_user( 'member', 'subscriber', array( 'sneaky' ) );
		$this->assertTrue( user_can( $user_id, 'openbb_view_aid' ) );
		$this->assertFalse( user_can( $user_id, 'openbb_manage_access' ) );
		$this->assertFalse( user_can( $user_id, 'manage_options' ) );
		$this->assertFalse( user_can( $user_id, 'edit_users' ) );
	}

	public function test_role_caps_filter_cannot_add_roles(): void {
		add_filter(
			'openbb_coop_role_caps',
			static function ( array $roles ): array {
				$roles['volunteer'] = array( 'openbb_distribute_orders' );
				$roles['member'][]  = 'openbb_export_data';
				return $roles;
			}
		);

		$roles = OPENBB_Access::coop_roles();
		$this->assertArrayNotHasKey( 'volunteer', $roles );
		$this->assertContains( 'openbb_export_data', $roles['member'] );
		$this->assertSame( '', OPENBB_Access::sanitize_role( 'volunteer' ) );
	}

	public function test_administrators_get_every_staff_cap_but_must_join_to_order(): void {
		$admin = $this->make_user( '', 'administrator' );

		foreach ( array_merge( array_slice( self::COORDINATOR_CAPS, 2 ), self::MANAGER_EXTRA_CAPS ) as $cap ) {
			$this->assertTrue( user_can( $admin, $cap ), $cap );
		}
		$this->assertFalse( user_can( $admin, 'openbb_place_orders' ) );

		openbb_update_member_field( $admin, 'coop_role', 'member' );
		$this->assertTrue( user_can( $admin, 'openbb_place_orders' ) );
	}

	public function test_wordpress_role_and_coop_role_combine(): void {
		$author = $this->make_user( 'member', 'author' );

		$this->assertTrue( user_can( $author, 'edit_posts' ) );
		$this->assertTrue( user_can( $author, 'openbb_place_orders' ) );
		$this->assertFalse( user_can( $author, 'openbb_access_admin' ) );
	}

	public function test_changing_wordpress_role_keeps_coop_access(): void {
		$user_id = $this->make_user( 'coordinator', 'author', array( 'aid_reviewer' ) );

		wp_update_user(
			array(
				'ID'   => $user_id,
				'role' => 'editor',
			)
		);

		$this->assertSame( array( 'editor' ), get_userdata( $user_id )->roles );
		$this->assertSame( 'coordinator', OPENBB_Access::get_coop_role( $user_id ) );
		$this->assertSame( array( 'aid_reviewer' ), OPENBB_Access::get_grants( $user_id ) );
		$this->assertTrue( user_can( $user_id, 'openbb_view_aid' ) );
	}

	public function test_removing_the_filter_removes_every_coop_cap(): void {
		$user_id = $this->make_user( 'manager', '' );
		$this->assertTrue( user_can( $user_id, 'openbb_manage_access' ) );

		remove_filter( 'user_has_cap', array( 'OPENBB_Access', 'filter_user_has_cap' ), 10 );

		foreach ( $this->all_caps() as $cap ) {
			$this->assertFalse( user_can( $user_id, $cap ), $cap );
		}
	}

	public function test_set_coop_role_by_coordinator(): void {
		$coordinator = $this->make_user( 'coordinator' );
		$applicant   = $this->make_user( 'applicant' );
		$member      = $this->make_user( 'member' );
		wp_set_current_user( $coordinator );

		$this->assertTrue( OPENBB_Access::set_coop_role( $applicant, 'member' ) );
		$this->assertTrue( OPENBB_Access::set_coop_role( $member, 'inactive' ) );
		$this->assertTrue( OPENBB_Access::set_coop_role( $member, 'member' ) );

		$this->assertWPError( OPENBB_Access::set_coop_role( $member, 'coordinator' ) );
		$this->assertWPError( OPENBB_Access::set_coop_role( $coordinator, 'member' ) );
		$this->assertSame( 'member', OPENBB_Access::get_coop_role( $member ) );
		$this->assertSame( 'coordinator', OPENBB_Access::get_coop_role( $coordinator ) );
	}

	public function test_coordinator_cannot_demote_staff(): void {
		$coordinator = $this->make_user( 'coordinator' );
		$other       = $this->make_user( 'coordinator' );
		wp_set_current_user( $coordinator );

		$result = OPENBB_Access::set_coop_role( $other, 'inactive' );

		$this->assertWPError( $result );
		$this->assertSame( 'openbb_forbidden', $result->get_error_code() );
	}

	public function test_manager_manages_staff_but_not_other_managers_or_admins(): void {
		$manager       = $this->make_user( 'manager' );
		$other_manager = $this->make_user( 'manager' );
		$member        = $this->make_user( 'member' );
		$admin         = $this->make_user( 'member', 'administrator' );
		wp_set_current_user( $manager );

		$this->assertTrue( OPENBB_Access::set_coop_role( $member, 'coordinator' ) );
		$this->assertTrue( OPENBB_Access::set_coop_role( $member, 'manager' ) );
		$this->assertWPError( OPENBB_Access::set_coop_role( $other_manager, 'member' ) );
		$this->assertWPError( OPENBB_Access::set_coop_role( $admin, 'inactive' ) );
		$this->assertWPError( OPENBB_Access::set_grants( $other_manager, array( 'aid_reviewer' ) ) );
	}

	public function test_administrator_can_change_managers(): void {
		$admin   = $this->make_user( '', 'administrator' );
		$manager = $this->make_user( 'manager' );
		wp_set_current_user( $admin );

		$this->assertTrue( OPENBB_Access::set_coop_role( $manager, 'member' ) );
		$this->assertTrue( OPENBB_Access::set_grants( $manager, array( 'rules_editor' ) ) );
	}

	public function test_inactive_users_must_be_reactivated_before_staff_roles(): void {
		wp_set_current_user( $this->make_user( '', 'administrator' ) );
		$user_id = $this->make_user( 'inactive' );

		$result = OPENBB_Access::set_coop_role( $user_id, 'coordinator' );

		$this->assertWPError( $result );
		$this->assertSame( 'openbb_reactivate_first', $result->get_error_code() );
	}

	public function test_invalid_role_and_unknown_user_are_rejected(): void {
		wp_set_current_user( $this->make_user( '', 'administrator' ) );
		$user_id = $this->make_user( 'member' );

		$this->assertWPError( OPENBB_Access::set_coop_role( $user_id, 'volunteer' ) );
		$this->assertWPError( OPENBB_Access::set_coop_role( $user_id, '' ) );
		$this->assertWPError( OPENBB_Access::set_coop_role( PHP_INT_MAX, 'member' ) );
		$this->assertWPError( OPENBB_Access::set_grants( $user_id, array( 'superpowers' ) ) );
	}

	public function test_first_active_role_sets_joined_at_once(): void {
		wp_set_current_user( $this->make_user( 'coordinator' ) );
		$user_id = $this->make_user( 'applicant' );

		OPENBB_Access::set_coop_role( $user_id, 'member' );
		$joined_at = openbb_get_member_field( $user_id, 'joined_at' );
		$this->assertNotEmpty( $joined_at );

		openbb_update_member_field( $user_id, 'joined_at', '2000-01-01 00:00:00' );
		OPENBB_Access::set_coop_role( $user_id, 'inactive' );
		OPENBB_Access::set_coop_role( $user_id, 'member' );
		$this->assertSame( '2000-01-01 00:00:00', openbb_get_member_field( $user_id, 'joined_at' ) );
	}

	public function test_declined_applicant_never_gets_joined_at(): void {
		wp_set_current_user( $this->make_user( 'coordinator' ) );
		$user_id = $this->make_user( 'applicant' );

		OPENBB_Access::set_coop_role( $user_id, 'inactive' );

		$this->assertSame( '', openbb_get_member_field( $user_id, 'joined_at' ) );
		$this->assertTrue( OPENBB_Member::get( $user_id )->is_declined() );
	}

	public function test_changes_fire_actions_with_actor(): void {
		$manager = $this->make_user( 'manager' );
		$user_id = $this->make_user( 'member' );
		wp_set_current_user( $manager );

		$role_action  = new MockAction();
		$grant_action = new MockAction();
		add_action( 'openbb_coop_role_changed', array( $role_action, 'action' ), 10, 4 );
		add_action( 'openbb_grants_changed', array( $grant_action, 'action' ), 10, 4 );

		OPENBB_Access::set_coop_role( $user_id, 'coordinator' );
		OPENBB_Access::set_coop_role( $user_id, 'coordinator' );
		OPENBB_Access::set_grants( $user_id, array( 'aid_reviewer' ) );

		$this->assertSame( 1, $role_action->get_call_count() );
		$this->assertSame( array( $user_id, 'coordinator', 'member', $manager ), $role_action->get_args()[0] );
		$this->assertSame( array( $user_id, array( 'aid_reviewer' ), array(), $manager ), $grant_action->get_args()[0] );
	}

	public function test_staff_cannot_change_their_own_access(): void {
		$manager = $this->make_user( 'manager' );
		wp_set_current_user( $manager );

		$this->assertFalse( current_user_can( 'openbb_set_coop_role', $manager, 'member' ) );
		$this->assertFalse( current_user_can( 'openbb_set_grants', $manager ) );
		$this->assertWPError( OPENBB_Access::set_coop_role( $manager, 'inactive' ) );
	}

	public function test_administrators_can_set_their_own_access(): void {
		$admin = $this->make_user( '', 'administrator' );
		wp_set_current_user( $admin );

		$this->assertTrue( current_user_can( 'openbb_set_coop_role', $admin, 'manager' ) );
		$this->assertTrue( current_user_can( 'openbb_set_grants', $admin ) );
		$this->assertTrue( OPENBB_Access::set_coop_role( $admin, 'member' ) );
		$this->assertSame( 'member', OPENBB_Access::get_coop_role( $admin ) );
	}

	public function test_only_access_managers_set_grants(): void {
		$coordinator = $this->make_user( 'coordinator' );
		$manager     = $this->make_user( 'manager' );
		$target      = $this->make_user( 'coordinator' );

		wp_set_current_user( $coordinator );
		$this->assertWPError( OPENBB_Access::set_grants( $target, array( 'aid_reviewer' ) ) );

		wp_set_current_user( $manager );
		$this->assertTrue( OPENBB_Access::set_grants( $target, array( 'aid_reviewer', 'aid_reviewer' ) ) );
		$this->assertSame( array( 'aid_reviewer' ), OPENBB_Access::get_grants( $target ) );
		$this->assertTrue( OPENBB_Access::set_grants( $target, array() ) );
		$this->assertSame( array(), OPENBB_Access::get_grants( $target ) );
	}
}
