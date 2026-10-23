<?php
/**
 * Co-op membership column on the core Users list.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_User_Profile extends OPENBB_TestCase {

	public function test_column_shows_status_and_roles(): void {
		$this->assertArrayHasKey( 'openbb_membership', openbb_user_access_column( array() ) );

		$this->assertSame( '', openbb_user_access_column_value( '', 'openbb_membership', $this->make_user() ) );
		$this->assertSame( 'Applicant', openbb_user_access_column_value( '', 'openbb_membership', $this->make_user( 'applicant' ) ) );
		$this->assertSame( 'Active (Coordinator, Aid reviewer)', openbb_user_access_column_value( '', 'openbb_membership', $this->make_user( 'coordinator', 'subscriber', array( 'aid_reviewer' ) ) ) );
		$this->assertSame( 'kept', openbb_user_access_column_value( 'kept', 'other', $this->make_user( 'member' ) ) );
	}

	public function test_core_profile_has_no_access_fields(): void {
		$this->assertFalse( has_action( 'edit_user_profile', 'openbb_user_access_fields' ) );
		$this->assertFalse( has_action( 'user_profile_update_errors', 'openbb_save_user_access_fields' ) );
	}
}
