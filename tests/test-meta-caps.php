<?php
/**
 * Object-level capabilities for orders and members.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Meta_Caps extends OPENBB_TestCase {

	public function test_members_only_see_their_own_orders(): void {
		$owner = $this->make_user( 'member' );
		$other = $this->make_user( 'member' );
		$order = $this->make_order( $this->make_cycle( OPENBB_Cycle::STATUS_ORDERING ), $owner );

		$this->assertTrue( user_can( $owner, 'openbb_view_order', $order ) );
		$this->assertTrue( user_can( $owner, 'openbb_edit_own_order', $order ) );
		$this->assertFalse( user_can( $other, 'openbb_view_order', $order ) );
		$this->assertFalse( user_can( $other, 'openbb_edit_own_order', $order ) );
		$this->assertFalse( user_can( 0, 'openbb_view_order', $order ) );
	}

	public function test_inactive_owner_can_view_but_not_edit(): void {
		$owner = $this->make_user( 'inactive' );
		$order = $this->make_order( $this->make_cycle( OPENBB_Cycle::STATUS_COMPLETE ), $owner );

		$this->assertTrue( user_can( $owner, 'openbb_view_order', $order ) );
		$this->assertFalse( user_can( $owner, 'openbb_edit_own_order', $order ) );
	}

	public function test_staff_use_staff_paths_for_others_orders(): void {
		$coordinator = $this->make_user( 'coordinator' );
		$order       = $this->make_order( $this->make_cycle( OPENBB_Cycle::STATUS_ORDERING ), $this->make_user( 'member' ) );

		$this->assertTrue( user_can( $coordinator, 'openbb_view_order', $order ) );
		$this->assertTrue( user_can( $coordinator, 'openbb_edit_order', $order ) );
		$this->assertFalse( user_can( $coordinator, 'openbb_edit_own_order', $order ) );
	}

	public function test_distribution_helpers_only_see_the_cycle_in_distribution(): void {
		$helper   = $this->make_user( 'member', 'subscriber', array( 'distribution_helper' ) );
		$customer = $this->make_user( 'member' );

		$statuses = array(
			OPENBB_Cycle::STATUS_ORDERING     => false,
			OPENBB_Cycle::STATUS_PROCESSING   => false,
			OPENBB_Cycle::STATUS_DISTRIBUTION => true,
			OPENBB_Cycle::STATUS_COMPLETE     => false,
		);
		foreach ( $statuses as $status => $expected ) {
			$order = $this->make_order( $this->make_cycle( $status ), $customer );
			$this->assertSame( $expected, user_can( $helper, 'openbb_view_order', $order ), $status );
			$this->assertFalse( user_can( $helper, 'openbb_edit_order', $order ), $status );
		}
	}

	public function test_aid_details_need_aid_access(): void {
		$owner       = $this->make_user( 'member' );
		$coordinator = $this->make_user( 'coordinator' );
		$reviewer    = $this->make_user( 'coordinator', 'subscriber', array( 'aid_reviewer' ) );
		$manager     = $this->make_user( 'manager' );
		$order       = $this->make_order( $this->make_cycle( OPENBB_Cycle::STATUS_PROCESSING ), $owner );

		$this->assertTrue( user_can( $owner, 'openbb_view_order_aid', $order ) );
		$this->assertFalse( user_can( $coordinator, 'openbb_view_order_aid', $order ) );
		$this->assertTrue( user_can( $reviewer, 'openbb_view_order_aid', $order ) );
		$this->assertTrue( user_can( $manager, 'openbb_view_order_aid', $order ) );

		$this->assertFalse( user_can( $owner, 'openbb_decide_order_aid', $order ) );
		$this->assertFalse( user_can( $coordinator, 'openbb_decide_order_aid', $order ) );
		$this->assertTrue( user_can( $reviewer, 'openbb_decide_order_aid', $order ) );
	}

	public function test_nobody_decides_aid_on_their_own_order(): void {
		$reviewer = $this->make_user( 'manager' );
		$admin    = $this->make_user( 'member', 'administrator' );
		$cycle    = $this->make_cycle( OPENBB_Cycle::STATUS_PROCESSING );

		$this->assertFalse( user_can( $reviewer, 'openbb_decide_order_aid', $this->make_order( $cycle, $reviewer ) ) );
		$this->assertFalse( user_can( $admin, 'openbb_decide_order_aid', $this->make_order( $cycle, $admin ) ) );
	}

	public function test_missing_objects_are_denied(): void {
		$admin = $this->make_user( '', 'administrator' );

		$this->assertFalse( user_can( $admin, 'openbb_view_order', PHP_INT_MAX ) );
		$this->assertFalse( user_can( $admin, 'openbb_view_order' ) );
		$this->assertFalse( user_can( $admin, 'openbb_edit_member', PHP_INT_MAX ) );
	}

	public function test_members_view_and_edit_themselves_only(): void {
		$member = $this->make_user( 'member' );
		$other  = $this->make_user( 'member' );

		$this->assertTrue( user_can( $member, 'openbb_view_member', $member ) );
		$this->assertTrue( user_can( $member, 'openbb_edit_member', $member ) );
		$this->assertFalse( user_can( $member, 'openbb_view_member', $other ) );
		$this->assertFalse( user_can( $member, 'openbb_edit_member', $other ) );
	}

	public function test_coordinators_cannot_edit_people_with_more_power(): void {
		$coordinator = $this->make_user( 'coordinator' );

		$this->assertTrue( user_can( $coordinator, 'openbb_edit_member', $this->make_user( 'member', 'editor' ) ) );
		$this->assertTrue( user_can( $coordinator, 'openbb_edit_member', $this->make_user( 'coordinator' ) ) );
		$this->assertFalse( user_can( $coordinator, 'openbb_edit_member', $this->make_user( 'manager' ) ) );
		$this->assertFalse( user_can( $coordinator, 'openbb_edit_member', $this->make_user( 'member', 'administrator' ) ) );
	}

	public function test_member_meta_api_only_allows_editable_fields(): void {
		$member = $this->make_user( 'member' );
		$admin  = $this->make_user( '', 'administrator' );
		if ( is_multisite() ) {
			// Core only lets network admins edit other users on multisite.
			grant_super_admin( $admin );
		}

		$this->assertTrue( user_can( $member, 'edit_user_meta', $member, openbb_member_meta_key( 'phone' ) ) );
		$this->assertTrue( user_can( $member, 'edit_user_meta', $member, openbb_member_meta_key( 'language' ) ) );
		foreach ( array( 'coop_role', 'grants', 'joined_at', 'rules_version', 'rules_accepted_at' ) as $field ) {
			$this->assertFalse( user_can( $member, 'edit_user_meta', $member, openbb_member_meta_key( $field ) ), $field );
			$this->assertFalse( user_can( $admin, 'edit_user_meta', $member, openbb_member_meta_key( $field ) ), $field );
		}
		$this->assertTrue( user_can( $admin, 'edit_user_meta', $member, openbb_member_meta_key( 'phone' ) ) );
	}
}
