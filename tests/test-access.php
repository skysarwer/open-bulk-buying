<?php
/**
 * Membership status, held roles and capability resolution.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Access extends OPENBB_TestCase {

	const COORDINATOR_CAPS = array(
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
		'openbb_create_members',
		'openbb_export_data',
	);

	const MANAGER_EXTRA_CAPS = array(
		'openbb_view_aid',
		'openbb_decide_aid',
		'openbb_manage_rules',
		'openbb_manage_settings',
		'openbb_manage_profile_fields',
		'openbb_manage_access',
	);

	private function all_caps(): array {
		return array_merge( array( 'read', 'upload_files', 'openbb_member', 'openbb_access_admin', 'openbb_place_orders' ), self::COORDINATOR_CAPS, self::MANAGER_EXTRA_CAPS );
	}

	/**
	 * The expected capabilities of active members holding each default role, written out
	 * so the code can't drift from them unnoticed.
	 */
	private function expected_matrix(): array {
		$member = array( 'openbb_member', 'openbb_place_orders' );
		$staff  = array_merge( $member, array( 'read', 'openbb_access_admin' ) );

		return array(
			'member'              => $member,
			'coordinator'         => array_merge( $staff, array( 'upload_files' ), self::COORDINATOR_CAPS ),
			'manager'             => array_merge( $staff, array( 'upload_files' ), self::COORDINATOR_CAPS, self::MANAGER_EXTRA_CAPS ),
			'aid_reviewer'        => array_merge( $staff, array( 'openbb_view_aid', 'openbb_decide_aid' ) ),
			'distribution_helper' => array_merge( $staff, array( 'openbb_distribute_orders', 'openbb_record_payments' ) ),
			'rules_editor'        => array_merge( $staff, array( 'openbb_manage_rules' ) ),
		);
	}

	/**
	 * Users with no WordPress role on the site, so every capability comes from the co-op.
	 */
	public function test_each_default_role_resolves_exactly_its_caps(): void {
		foreach ( $this->expected_matrix() as $role => $caps ) {
			$user_id = $this->make_user( $role, '' );
			foreach ( $this->all_caps() as $cap ) {
				$this->assertSame( in_array( $cap, $caps, true ), user_can( $user_id, $cap ), "{$role} / {$cap}" );
			}
		}
	}

	public function test_non_active_statuses_only_get_the_member_cap(): void {
		foreach ( array( 'applicant', 'inactive', 'declined' ) as $status ) {
			$user_id = $this->make_user( $status, '', array( 'manager' ) );
			foreach ( $this->all_caps() as $cap ) {
				$this->assertSame( 'openbb_member' === $cap, user_can( $user_id, $cap ), "{$status} / {$cap}" );
			}
		}
	}

	public function test_user_without_a_status_gets_no_coop_caps(): void {
		$user_id = $this->make_user( '', 'editor' );
		add_user_meta( $user_id, openbb_member_meta_key( 'roles' ), 'manager' );

		foreach ( array_diff( $this->all_caps(), array( 'read', 'upload_files' ) ) as $cap ) {
			$this->assertFalse( user_can( $user_id, $cap ), $cap );
		}
	}

	public function test_roles_combine(): void {
		$user_id = $this->make_user( 'member', 'subscriber', array( 'aid_reviewer', 'rules_editor' ) );

		$this->assertSame( array( 'aid_reviewer', 'rules_editor' ), OPENBB_Access::get_roles( $user_id ) );
		$this->assertTrue( user_can( $user_id, 'openbb_decide_aid' ) );
		$this->assertTrue( user_can( $user_id, 'openbb_manage_rules' ) );
		$this->assertFalse( user_can( $user_id, 'openbb_view_orders' ) );
	}

	public function test_unknown_and_default_roles_are_ignored_in_meta(): void {
		$user_id = $this->make_user( 'member', '', array( 'overlord', 'member', 'rules_editor' ) );

		$this->assertSame( array( 'rules_editor' ), OPENBB_Access::get_roles( $user_id ) );
	}

	public function test_administrators_get_every_cap_but_must_join_to_order(): void {
		$admin = $this->make_user( '', 'administrator' );

		foreach ( array_merge( self::COORDINATOR_CAPS, self::MANAGER_EXTRA_CAPS, array( 'openbb_access_admin' ) ) as $cap ) {
			$this->assertTrue( user_can( $admin, $cap ), $cap );
		}
		$this->assertFalse( user_can( $admin, 'openbb_place_orders' ) );
		$this->assertFalse( user_can( $admin, 'openbb_member' ) );

		openbb_update_member_field( $admin, 'status', 'active' );
		$this->assertTrue( user_can( $admin, 'openbb_place_orders' ) );
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
		$this->assertSame( 'active', OPENBB_Access::get_status( $user_id ) );
		$this->assertSame( array( 'coordinator', 'aid_reviewer' ), OPENBB_Access::get_roles( $user_id ) );
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

	public function test_settings_screen_opens_for_any_settings_tab(): void {
		$this->assertTrue( user_can( $this->make_user( 'rules_editor' ), 'openbb_access_settings' ) );
		$this->assertTrue( user_can( $this->make_user( 'manager' ), 'openbb_access_settings' ) );
		$this->assertFalse( user_can( $this->make_user( 'coordinator' ), 'openbb_access_settings' ) );
	}

	public function test_coordinator_moves_members_through_statuses(): void {
		$coordinator = $this->make_user( 'coordinator' );
		$applicant   = $this->make_user( 'applicant' );
		$member      = $this->make_user( 'member' );
		wp_set_current_user( $coordinator );

		$this->assertTrue( OPENBB_Access::set_status( $applicant, 'active' ) );
		$this->assertTrue( OPENBB_Access::set_status( $member, 'inactive' ) );
		$this->assertTrue( OPENBB_Access::set_status( $member, 'active' ) );
		$this->assertSame( 'active', OPENBB_Access::get_status( $applicant ) );
	}

	public function test_invalid_status_changes_are_rejected(): void {
		wp_set_current_user( $this->make_user( '', 'administrator' ) );
		$member    = $this->make_user( 'member' );
		$applicant = $this->make_user( 'applicant' );
		$outsider  = $this->make_user();

		$this->assertSame( 'openbb_invalid_status_change', OPENBB_Access::set_status( $member, 'applicant' )->get_error_code() );
		$this->assertSame( 'openbb_invalid_status_change', OPENBB_Access::set_status( $applicant, 'inactive' )->get_error_code() );
		$this->assertSame( 'openbb_invalid_status_change', OPENBB_Access::set_status( $outsider, 'inactive' )->get_error_code() );
		$this->assertSame( 'openbb_invalid_status', OPENBB_Access::set_status( $member, 'overlord' )->get_error_code() );
		$this->assertSame( 'openbb_invalid_status', OPENBB_Access::set_status( PHP_INT_MAX, 'active' )->get_error_code() );
		$this->assertTrue( OPENBB_Access::set_status( $outsider, 'active' ) );
	}

	public function test_nobody_changes_their_own_status(): void {
		$manager = $this->make_user( 'manager' );
		$admin   = $this->make_user( 'member', 'administrator' );

		wp_set_current_user( $manager );
		$this->assertWPError( OPENBB_Access::set_status( $manager, 'inactive' ) );
		wp_set_current_user( $admin );
		$this->assertWPError( OPENBB_Access::set_status( $admin, 'inactive' ) );
	}

	public function test_deactivating_someone_with_roles_needs_manage_access(): void {
		$coordinator = $this->make_user( 'coordinator' );
		$peer        = $this->make_user( 'coordinator' );
		$manager     = $this->make_user( 'manager' );
		wp_set_current_user( $coordinator );

		$result = OPENBB_Access::set_status( $peer, 'inactive' );
		$this->assertSame( 'openbb_forbidden', $result->get_error_code() );

		wp_set_current_user( $manager );
		$this->assertTrue( OPENBB_Access::set_status( $peer, 'inactive' ) );
		$this->assertFalse( user_can( $peer, 'openbb_access_admin' ) );
		$this->assertSame( array( 'coordinator' ), OPENBB_Access::get_roles( $peer ), 'Roles are kept for reactivation.' );
	}

	public function test_only_administrators_change_access_managers_or_admins(): void {
		$manager       = $this->make_user( 'manager' );
		$other_manager = $this->make_user( 'manager' );
		$admin         = $this->make_user( 'member', 'administrator' );
		wp_set_current_user( $manager );

		$this->assertWPError( OPENBB_Access::set_status( $other_manager, 'inactive' ) );
		$this->assertWPError( OPENBB_Access::set_status( $admin, 'inactive' ) );
		$this->assertWPError( OPENBB_Access::set_roles( $other_manager, array() ) );

		wp_set_current_user( $this->make_user( '', 'administrator' ) );
		$this->assertTrue( OPENBB_Access::set_status( $other_manager, 'inactive' ) );
		$this->assertTrue( OPENBB_Access::set_roles( $other_manager, array( 'rules_editor' ) ) );
	}

	public function test_first_activation_sets_joined_at_once(): void {
		wp_set_current_user( $this->make_user( 'coordinator' ) );
		$user_id = $this->make_user( 'applicant' );

		OPENBB_Access::set_status( $user_id, 'active' );
		$this->assertNotEmpty( openbb_get_member_field( $user_id, 'joined_at' ) );

		openbb_update_member_field( $user_id, 'joined_at', '2000-01-01 00:00:00' );
		OPENBB_Access::set_status( $user_id, 'inactive' );
		OPENBB_Access::set_status( $user_id, 'active' );
		$this->assertSame( '2000-01-01 00:00:00', openbb_get_member_field( $user_id, 'joined_at' ) );
	}

	public function test_declined_applicant_never_gets_joined_at(): void {
		wp_set_current_user( $this->make_user( 'coordinator' ) );
		$user_id = $this->make_user( 'applicant' );

		$this->assertTrue( OPENBB_Access::set_status( $user_id, 'declined' ) );

		$this->assertSame( '', openbb_get_member_field( $user_id, 'joined_at' ) );
		$this->assertTrue( OPENBB_Member::get( $user_id )->is_declined() );
	}

	public function test_manager_gives_roles_it_holds(): void {
		$manager = $this->make_user( 'manager' );
		$member  = $this->make_user( 'member' );
		wp_set_current_user( $manager );

		$this->assertTrue( OPENBB_Access::set_roles( $member, array( 'coordinator', 'aid_reviewer', 'aid_reviewer' ) ) );
		$this->assertSame( array( 'coordinator', 'aid_reviewer' ), OPENBB_Access::get_roles( $member ) );
		$this->assertTrue( OPENBB_Access::set_roles( $member, array( 'aid_reviewer' ) ) );
		$this->assertSame( array( 'aid_reviewer' ), OPENBB_Access::get_roles( $member ) );
		$this->assertTrue( OPENBB_Access::set_roles( $member, array() ) );
		$this->assertSame( array(), OPENBB_Access::get_roles( $member ) );
	}

	public function test_roles_need_manage_access_and_every_permission_given(): void {
		$coordinator = $this->make_user( 'coordinator' );
		$member      = $this->make_user( 'member' );

		wp_set_current_user( $coordinator );
		$this->assertSame( 'openbb_forbidden', OPENBB_Access::set_roles( $member, array( 'distribution_helper' ) )->get_error_code() );

		// An access manager without aid permissions.
		wp_set_current_user( $this->make_user( '', 'administrator' ) );
		$created = OPENBB_Roles::create_role(
			array(
				'label' => 'Gatekeeper',
				'caps'  => array( 'openbb_view_members', 'openbb_manage_access' ),
			),
			OPENBB_Roles::revision()
		);
		$gatekeeper = $this->make_user( 'member', '', array( $created['key'] ) );

		wp_set_current_user( $gatekeeper );
		$this->assertWPError( OPENBB_Access::set_roles( $member, array( 'aid_reviewer' ) ) );
		$this->assertTrue( OPENBB_Access::set_roles( $member, array( $created['key'] ) ) );
	}

	public function test_invalid_role_lists_and_non_members_are_rejected(): void {
		wp_set_current_user( $this->make_user( '', 'administrator' ) );
		$member   = $this->make_user( 'member' );
		$outsider = $this->make_user();

		$this->assertWPError( OPENBB_Access::set_roles( $member, array( 'overlord' ) ) );
		$this->assertWPError( OPENBB_Access::set_roles( $member, array( 'member' ) ) );
		$this->assertWPError( OPENBB_Access::set_roles( $member, array( array( 'coordinator' ) ) ) );
		$this->assertSame( 'openbb_not_member', OPENBB_Access::set_roles( $outsider, array( 'coordinator' ) )->get_error_code() );
	}

	public function test_only_administrators_set_their_own_roles(): void {
		$manager = $this->make_user( 'manager' );
		wp_set_current_user( $manager );
		$this->assertFalse( current_user_can( 'openbb_set_member_roles', $manager, array( 'manager', 'aid_reviewer' ) ) );
		$this->assertWPError( OPENBB_Access::set_roles( $manager, array() ) );

		$admin = $this->make_user( 'member', 'administrator' );
		wp_set_current_user( $admin );
		$this->assertTrue( OPENBB_Access::set_roles( $admin, array( 'coordinator' ) ) );
		$this->assertSame( array( 'coordinator' ), OPENBB_Access::get_roles( $admin ) );
	}

	public function test_changes_fire_actions_with_actor(): void {
		$manager = $this->make_user( 'manager' );
		$user_id = $this->make_user( 'applicant' );
		wp_set_current_user( $manager );

		$status_action = new MockAction();
		$roles_action  = new MockAction();
		add_action( 'openbb_member_status_changed', array( $status_action, 'action' ), 10, 4 );
		add_action( 'openbb_member_roles_changed', array( $roles_action, 'action' ), 10, 4 );

		OPENBB_Access::set_status( $user_id, 'active' );
		OPENBB_Access::set_status( $user_id, 'active' );
		OPENBB_Access::set_roles( $user_id, array( 'coordinator' ) );
		OPENBB_Access::set_roles( $user_id, array( 'coordinator' ) );

		$this->assertSame( 1, $status_action->get_call_count() );
		$this->assertSame( array( $user_id, 'active', 'applicant', $manager ), $status_action->get_args()[0] );
		$this->assertSame( 1, $roles_action->get_call_count() );
		$this->assertSame( array( $user_id, array( 'coordinator' ), array(), $manager ), $roles_action->get_args()[0] );
	}

	public function test_start_membership_only_for_non_members(): void {
		$user_id = $this->make_user();

		$this->assertTrue( OPENBB_Access::start_membership( $user_id, 'applicant' ) );
		$this->assertSame( 'openbb_already_joined', OPENBB_Access::start_membership( $user_id, 'active' )->get_error_code() );
		$this->assertWPError( OPENBB_Access::start_membership( $this->make_user(), 'inactive' ) );
	}
}
