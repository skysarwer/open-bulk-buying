<?php
/**
 * Member storage, the member model, uninstall and the order schema.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Member extends OPENBB_TestCase {

	public function test_fields_are_stored_under_the_site_prefix(): void {
		global $wpdb;

		$user_id = $this->make_user( 'member' );

		$this->assertSame( $wpdb->get_blog_prefix() . 'openbb_status', openbb_member_meta_key( 'status' ) );
		$this->assertSame( 'active', get_user_meta( $user_id, $wpdb->get_blog_prefix() . 'openbb_status', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'openbb_status', true ) );
	}

	public function test_network_wide_keys_are_ignored(): void {
		$user_id = $this->make_user();
		update_user_meta( $user_id, 'openbb_status', 'active' );
		add_user_meta( $user_id, 'openbb_roles', 'manager' );

		$this->assertSame( '', OPENBB_Access::get_status( $user_id ) );
		$this->assertFalse( user_can( $user_id, 'openbb_manage_access' ) );
	}

	public function test_unknown_stored_values_are_ignored(): void {
		$user_id = $this->make_user();
		update_user_meta( $user_id, openbb_member_meta_key( 'status' ), 'overlord' );
		add_user_meta( $user_id, openbb_member_meta_key( 'roles' ), 'superpowers' );
		add_user_meta( $user_id, openbb_member_meta_key( 'roles' ), 'aid_reviewer' );

		$this->assertSame( '', OPENBB_Access::get_status( $user_id ) );
		$this->assertSame( array( 'aid_reviewer' ), OPENBB_Access::get_roles( $user_id ) );
		$this->assertNull( OPENBB_Member::get( $user_id ) );
	}

	public function test_member_model(): void {
		$this->assertNull( OPENBB_Member::get( $this->make_user() ) );
		$this->assertNull( OPENBB_Member::get( PHP_INT_MAX ) );

		$applicant = OPENBB_Member::get( $this->make_user( 'applicant' ) );
		$this->assertTrue( $applicant->is_applicant() );
		$this->assertFalse( $applicant->is_active() );

		foreach ( array( 'member', 'coordinator', 'manager' ) as $role ) {
			$this->assertTrue( OPENBB_Member::get( $this->make_user( $role ) )->is_active(), $role );
		}

		$former = OPENBB_Member::get( $this->make_user( 'inactive' ) );
		$this->assertFalse( $former->is_active() );
		$this->assertFalse( $former->is_declined() );
		$this->assertTrue( OPENBB_Member::get( $this->make_user( 'declined' ) )->is_declined() );
	}

	/**
	 * @group ms-required
	 */
	public function test_coop_access_is_per_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only; run PHPUnit with phpunit-multisite.xml.dist.' );
		}

		$user_id = $this->make_user( 'manager', 'subscriber', array( 'aid_reviewer' ) );
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		$role     = OPENBB_Access::get_status( $user_id );
		$can      = user_can( $user_id, 'openbb_manage_access' );
		$can_shop = user_can( $user_id, 'openbb_place_orders' );
		restore_current_blog();

		$this->assertSame( '', $role );
		$this->assertFalse( $can );
		$this->assertFalse( $can_shop );
		$this->assertTrue( user_can( $user_id, 'openbb_manage_access' ) );
	}

	public function test_orders_have_split_notes(): void {
		global $wpdb;

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->openbb_orders}" );

		$this->assertContains( 'member_note', $columns );
		$this->assertContains( 'staff_note', $columns );
		$this->assertNotContains( 'notes', $columns );
		$this->assertSame( OPENBB_Installer::DB_VERSION, get_option( OPENBB_Installer::DB_VERSION_OPTION ) );
	}

	public function test_uninstall_removes_coop_data_but_not_wordpress_roles(): void {
		$user_id = $this->make_user( 'coordinator', 'author', array( 'aid_reviewer' ) );
		openbb_update_member_field( $user_id, 'phone', '555-0100' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'open-bulk-buying/open-bulk-buying.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		foreach ( array_keys( openbb_member_meta_fields() ) as $field ) {
			$this->assertFalse( metadata_exists( 'user', $user_id, openbb_member_meta_key( $field ) ), $field );
		}
		$this->assertSame( array( 'author' ), get_userdata( $user_id )->roles );
		$this->assertFalse( user_can( $user_id, 'openbb_place_orders' ) );
	}
}
