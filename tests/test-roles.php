<?php
/**
 * Configurable co-op roles: defaults in code, only changes stored.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Roles extends OPENBB_TestCase {

	/** @var int */
	private $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = $this->make_user( '', 'administrator' );
		wp_set_current_user( $this->admin );
	}

	private function api( string $method, string $route, array $params = array() ): WP_REST_Response {
		rest_get_server();
		$request = new WP_REST_Request( $method, '/openbb/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_do_request( $request );
	}

	private function role( string $key ): ?array {
		foreach ( OPENBB_Roles::describe()['roles'] as $role ) {
			if ( $key === $role['key'] ) {
				return $role;
			}
		}

		return null;
	}

	private function save( string $key, array $caps, ?string $label = null ) {
		return OPENBB_Roles::save_role(
			$key,
			array(
				'label' => $label ?? OPENBB_Roles::labels()[ $key ],
				'caps'  => $caps,
			),
			OPENBB_Roles::revision()
		);
	}

	public function test_defaults_are_shown_without_storing_anything(): void {
		$described = OPENBB_Roles::describe();

		$this->assertFalse( get_option( OPENBB_Roles::OPTION ) );
		$this->assertTrue( $described['is_default'] );
		$this->assertSame( '', $described['revision'] );
		$this->assertSame(
			array( 'coordinator', 'manager', 'aid_reviewer', 'distribution_helper', 'rules_editor' ),
			wp_list_pluck( $described['roles'], 'key' )
		);
		$this->assertSame( 'Coordinator', $this->role( 'coordinator' )['label'] );
		$this->assertNotContains( 'openbb_delete_cycles_orders', $this->role( 'coordinator' )['caps'] );
		$this->assertContains( 'openbb_delete_cycles_orders', $this->role( 'manager' )['caps'] );
	}

	public function test_managers_have_cycle_and_order_delete_permission(): void {
		$manager = $this->make_user( 'manager' );
		$this->assertTrue( user_can( $manager, 'openbb_delete_cycles_orders' ) );

		$coordinator = $this->make_user( 'coordinator' );
		$this->assertFalse( user_can( $coordinator, 'openbb_delete_cycles_orders' ) );
		$this->assertTrue( user_can( $this->admin, 'openbb_delete_cycles_orders' ), 'Administrators always have the delete capability.' );
	}

	public function test_only_differences_are_stored_and_returning_to_defaults_deletes_them(): void {
		$caps = $this->role( 'rules_editor' )['caps'];

		$this->save( 'rules_editor', array_merge( $caps, array( 'openbb_manage_settings' ) ) );
		$this->assertSame(
			array(
				'roles' => array(
					'rules_editor' => array(
						'label' => '',
						'caps'  => array(
							'openbb_manage_settings'       => true,
							'openbb_manage_profile_fields' => false,
						),
					),
				),
			),
			get_option( OPENBB_Roles::OPTION )
		);
		$this->assertTrue( $this->role( 'rules_editor' )['modified'] );
		$this->assertFalse( OPENBB_Roles::describe()['is_default'] );

		$this->save( 'rules_editor', $caps );
		$this->assertFalse( get_option( OPENBB_Roles::OPTION ) );
		$this->assertTrue( OPENBB_Roles::describe()['is_default'] );
	}

	public function test_unmodified_roles_pick_up_new_default_permissions(): void {
		$this->save( 'coordinator', array( 'openbb_view_orders' ) );

		add_filter(
			'openbb_default_roles',
			static function ( array $roles ): array {
				$roles['rules_editor'][] = 'openbb_view_members';
				$roles['coordinator'][]  = 'openbb_view_aid';
				return $roles;
			}
		);
		OPENBB_Roles::flush();

		$this->assertContains( 'openbb_view_members', OPENBB_Roles::role_caps( 'rules_editor' ) );
		$this->assertNotContains( 'openbb_view_members', OPENBB_Roles::role_caps( 'coordinator' ), 'An explicit change is kept.' );
		$this->assertContains( 'openbb_view_aid', OPENBB_Roles::role_caps( 'coordinator' ), 'Untouched permissions follow the default.' );
	}

	public function test_child_permissions_follow_their_parent_unless_set(): void {
		$this->assertContains( 'openbb_create_members', OPENBB_Roles::role_caps( 'coordinator' ) );

		$caps = array_diff( OPENBB_Roles::role_caps( 'coordinator' ), array( 'openbb_create_members' ) );
		$this->save( 'coordinator', $caps );
		$this->assertNotContains( 'openbb_create_members', OPENBB_Roles::role_caps( 'coordinator' ) );
		$this->assertSame( array( 'openbb_create_members' => false ), get_option( OPENBB_Roles::OPTION )['roles']['coordinator']['caps'] );
	}

	public function test_permissions_need_their_prerequisites(): void {
		$result = $this->save( 'rules_editor', array( 'openbb_manage_rules', 'openbb_decide_aid' ) );

		$this->assertSame( 'openbb_role_missing_requirement', $result->get_error_code() );
		$this->assertFalse( get_option( OPENBB_Roles::OPTION ) );
	}

	public function test_every_active_member_orders_and_team_roles_give_admin_access(): void {
		$member = $this->make_user( 'member' );
		$this->assertTrue( user_can( $member, 'openbb_place_orders' ) );
		$this->assertFalse( user_can( $member, 'openbb_access_admin' ) );
		$this->assertArrayNotHasKey( 'openbb_place_orders', OPENBB_Roles::capabilities() );

		$created = OPENBB_Roles::create_role( array( 'label' => 'Nothing yet', 'caps' => array() ), '' );
		$this->assertTrue( OPENBB_Access::set_roles( $member, array( $created['key'] ) ) );
		$this->assertFalse( user_can( $member, 'openbb_access_admin' ), 'A role without permissions gives nothing.' );

		$this->assertWPError( OPENBB_Roles::create_role( array( 'label' => 'Orderer', 'caps' => array( 'openbb_place_orders' ) ), OPENBB_Roles::revision() ) );
	}

	public function test_custom_roles_get_unique_prefixed_keys_and_valid_labels(): void {
		$first = OPENBB_Roles::create_role(
			array(
				'label' => 'Bulk Buyer!',
				'caps'  => array(),
			),
			''
		);
		$this->assertSame( 'custom_bulk_buyer', $first['key'] );

		$this->assertSame( 'openbb_role_label_taken', OPENBB_Roles::create_role( array( 'label' => 'bulk buyer!', 'caps' => array() ), $first['revision'] )->get_error_code() );
		$this->assertSame( 'openbb_role_label_taken', OPENBB_Roles::create_role( array( 'label' => 'manager', 'caps' => array() ), $first['revision'] )->get_error_code() );
		$this->assertSame( 'openbb_role_label_required', OPENBB_Roles::create_role( array( 'label' => ' ', 'caps' => array() ), $first['revision'] )->get_error_code() );
		$this->assertSame( 'openbb_role_label_too_long', OPENBB_Roles::create_role( array( 'label' => str_repeat( 'a', 61 ), 'caps' => array() ), $first['revision'] )->get_error_code() );
		$this->assertWPError( OPENBB_Roles::create_role( array( 'label' => 'Root', 'caps' => array( 'manage_options' ) ), $first['revision'] ) );

		$second = OPENBB_Roles::create_role( array( 'label' => 'Bulk buyer?', 'caps' => array() ), $first['revision'] );
		$this->assertSame( 'custom_bulk_buyer_2', $second['key'] );
	}

	public function test_deleting_a_role_takes_it_from_members_and_reset_restores_defaults(): void {
		$user_id = $this->make_user( 'member', '', array( 'aid_reviewer', 'rules_editor' ) );
		$this->assertSame( 1, $this->role( 'aid_reviewer' )['members'] );

		$result = OPENBB_Roles::delete_role( 'aid_reviewer', '' );
		$this->assertSame( array( 'aid_reviewer' ), wp_list_pluck( $result['deleted'], 'key' ) );
		$this->assertSame( array( 'rules_editor' ), get_user_meta( $user_id, openbb_member_meta_key( 'roles' ) ) );
		$this->assertFalse( user_can( $user_id, 'openbb_view_aid' ) );

		$result = OPENBB_Roles::reset_role( 'aid_reviewer', $result['revision'] );
		$this->assertSame( array(), $result['deleted'] );
		$this->assertTrue( $result['is_default'] );
		$this->assertFalse( user_can( $user_id, 'openbb_view_aid' ), 'Restoring a role does not give it back.' );
	}

	public function test_reset_all_removes_custom_roles(): void {
		$created = OPENBB_Roles::create_role( array( 'label' => 'Helper', 'caps' => array( 'openbb_view_orders' ) ), '' );
		$user_id = $this->make_user( 'member', '', array( $created['key'] ) );

		$result = OPENBB_Roles::reset_all( $created['revision'] );

		$this->assertTrue( $result['is_default'] );
		$this->assertFalse( get_option( OPENBB_Roles::OPTION ) );
		$this->assertSame( array(), get_user_meta( $user_id, openbb_member_meta_key( 'roles' ) ) );
	}

	public function test_saves_based_on_an_old_revision_are_refused(): void {
		$loaded = OPENBB_Roles::revision();
		$this->save( 'rules_editor', array( 'openbb_manage_rules', 'openbb_view_members' ) );

		$result = OPENBB_Roles::save_role( 'coordinator', array( 'label' => 'Coordinator', 'caps' => array() ), $loaded );
		$this->assertSame( 'openbb_roles_stale', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );

		// Going back to the defaults doesn't make the very first copy current again.
		$stale = OPENBB_Roles::revision();
		OPENBB_Roles::reset_all( $stale );
		$this->assertSame( 'openbb_roles_stale', OPENBB_Roles::reset_all( $stale )->get_error_code() );
	}

	public function test_access_managers_cannot_escalate(): void {
		$created = OPENBB_Roles::create_role(
			array(
				'label' => 'Gatekeeper',
				'caps'  => array( 'openbb_view_members', 'openbb_manage_access', 'openbb_manage_rules' ),
			),
			''
		);
		wp_set_current_user( $this->make_user( 'member', '', array( $created['key'] ) ) );

		$this->assertSame( 'openbb_forbidden', $this->save( 'rules_editor', array( 'openbb_manage_rules', 'openbb_view_aid' ) )->get_error_code(), 'Permissions they lack' );
		$this->assertSame( 'openbb_forbidden', $this->save( $created['key'], array( 'openbb_view_members', 'openbb_manage_access' ) )->get_error_code(), 'A role they hold' );
		$this->assertSame( 'openbb_forbidden', $this->save( 'manager', array( 'openbb_view_members' ) )->get_error_code(), 'A role that manages access' );
		$this->assertSame( 'openbb_forbidden', OPENBB_Roles::delete_role( 'manager', OPENBB_Roles::revision() )->get_error_code() );
		$this->assertFalse( $this->role( 'manager' )['editable'] );

		$this->assertNotWPError( $this->save( 'rules_editor', array( 'openbb_manage_rules', 'openbb_view_members' ) ) );
		$this->assertNotWPError( $this->save( 'aid_reviewer', array( 'openbb_view_aid' ) ), 'Taking away is fine.' );
	}

	public function test_rest_routes_need_manage_access(): void {
		wp_set_current_user( $this->make_user( 'coordinator' ) );
		$this->assertSame( 403, $this->api( 'GET', '/settings/roles' )->get_status() );
		$this->assertSame( 403, $this->api( 'POST', '/settings/roles', array( 'label' => 'X', 'caps' => array(), 'revision' => '' ) )->get_status() );
	}

	public function test_rest_round_trip(): void {
		$response = $this->api( 'GET', '/settings/roles' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['is_default'] );

		$response = $this->api( 'POST', '/settings/roles', array( 'label' => 'Packer', 'caps' => array( 'openbb_process_orders' ), 'revision' => '' ) );
		$this->assertSame( 201, $response->get_status() );
		$key      = $response->get_data()['key'];
		$revision = $response->get_data()['revision'];

		$response = $this->api( 'PUT', '/settings/roles/' . $key, array( 'label' => 'Packers', 'caps' => array(), 'revision' => $revision ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Packers', $this->role( $key )['label'] );

		$this->assertSame( 409, $this->api( 'DELETE', '/settings/roles/' . $key, array( 'revision' => $revision ) )->get_status() );
		$revision = $response->get_data()['revision'];

		$this->assertSame( 404, $this->api( 'POST', '/settings/roles/coordinator/default', array( 'revision' => $revision ) )->get_status() );
		$response = $this->api( 'DELETE', '/settings/roles/' . $key, array( 'revision' => $revision ) );
		$this->assertSame( 200, $response->get_status() );
		$response = $this->api( 'DELETE', '/settings/roles/coordinator', array( 'revision' => $response->get_data()['revision'] ) );
		$response = $this->api( 'POST', '/settings/roles/coordinator/reset', array( 'revision' => $response->get_data()['revision'] ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['is_default'] );
		$this->assertSame( 404, $this->api( 'PUT', '/settings/roles/nope', array( 'label' => 'X', 'caps' => array(), 'revision' => '' ) )->get_status() );
		$this->assertSame( 200, $this->api( 'POST', '/settings/roles/reset-all', array( 'revision' => '' ) )->get_status() );
	}
}
