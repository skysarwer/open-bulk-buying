<?php
/**
 * Welcome email when an applicant becomes an active member.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Member_Approval_Email extends OPENBB_TestCase {

	private $mail = array();

	public function set_up(): void {
		parent::set_up();
		$this->mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		wp_set_current_user( $this->make_user( 'coordinator' ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		parent::tear_down();
	}

	public function capture_mail( $result, array $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	public function test_approval_sends_one_welcome_email(): void {
		$user_id = $this->make_user( 'applicant' );
		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Jane' ) );

		$this->assertTrue( OPENBB_Access::set_status( $user_id, 'active' ) );
		$this->assertSame( 'active', OPENBB_Access::get_status( $user_id ) );
		$this->assertNotEmpty( openbb_get_member_field( $user_id, 'joined_at' ) );
		$this->assertCount( 1, $this->mail );
		$this->assertSame( get_userdata( $user_id )->user_email, $this->mail[0]['to'] );
		$this->assertStringContainsString( 'Welcome to the co-op', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'Hi Jane,', $this->mail[0]['message'] );
		$this->assertStringContainsString( 'You can place an order', $this->mail[0]['message'] );
		$this->assertStringContainsString( wp_login_url(), $this->mail[0]['message'] );
		$this->assertStringContainsString( wp_lostpassword_url(), $this->mail[0]['message'] );

		$this->assertTrue( OPENBB_Access::set_status( $user_id, 'active' ) );
		$this->assertCount( 1, $this->mail );
	}

	public function test_members_screen_approval_sends_email(): void {
		rest_get_server();
		$user_id = $this->make_user( 'applicant' );
		$request = new WP_REST_Request( 'PUT', '/openbb/v1/members/' . $user_id );
		$request->set_param( 'status', 'active' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->mail );
		$this->assertSame( get_userdata( $user_id )->user_email, $this->mail[0]['to'] );
	}

	public function test_other_status_transitions_do_not_send_approval_email(): void {
		$this->assertTrue( OPENBB_Access::set_status( $this->make_user( 'applicant' ), 'declined' ) );
		$this->assertTrue( OPENBB_Access::set_status( $this->make_user( 'member' ), 'inactive' ) );
		$this->assertTrue( OPENBB_Access::set_status( $this->make_user( 'inactive' ), 'active' ) );
		$this->assertTrue( OPENBB_Access::set_status( $this->make_user( 'declined' ), 'active' ) );
		$this->assertTrue( OPENBB_Access::set_status( $this->make_user(), 'active' ) );
		$this->assertTrue( OPENBB_Access::start_membership( $this->make_user(), 'active' ) );
		$this->assertTrue( OPENBB_Access::start_membership( $this->make_user(), 'applicant' ) );

		$this->assertSame( array(), $this->mail );
	}

	public function test_unauthorized_approval_does_not_send_email(): void {
		$user_id = $this->make_user( 'applicant' );
		wp_set_current_user( $this->make_user( 'member' ) );

		$this->assertWPError( OPENBB_Access::set_status( $user_id, 'active' ) );
		$this->assertSame( 'applicant', OPENBB_Access::get_status( $user_id ) );
		$this->assertSame( array(), $this->mail );
	}

	public function test_rolled_back_approval_does_not_send_email(): void {
		$user_id = $this->make_user( 'applicant' );
		$fail = static function ( $check, $object_id, $meta_key ) use ( $user_id ) {
			return $user_id === $object_id && openbb_member_meta_key( 'joined_at' ) === $meta_key ? false : $check;
		};
		add_filter( 'update_user_metadata', $fail, 10, 3 );
		try {
			$this->assertWPError( OPENBB_Access::set_status( $user_id, 'active' ) );
		} finally {
			remove_filter( 'update_user_metadata', $fail );
		}

		$this->assertSame( 'applicant', OPENBB_Access::get_status( $user_id ) );
		$this->assertSame( array(), $this->mail );
	}

	public function test_placeholder_addresses_and_email_opt_out_are_respected(): void {
		$user_id = $this->make_user( 'applicant' );
		wp_update_user( array( 'ID' => $user_id, 'user_email' => 'no-email@example.invalid' ) );
		$this->mail = array();
		$this->assertTrue( OPENBB_Access::set_status( $user_id, 'active' ) );

		add_filter( 'openbb_can_email_member', '__return_false' );
		try {
			$this->assertTrue( OPENBB_Access::set_status( $this->make_user( 'applicant' ), 'active' ) );
		} finally {
			remove_filter( 'openbb_can_email_member', '__return_false' );
		}

		$this->assertSame( array(), $this->mail );
	}

	public function test_approval_uses_existing_welcome_email_filter(): void {
		$user_id = $this->make_user( 'applicant' );
		$filter = static function ( $email, $type, $member_id ) use ( $user_id ) {
			if ( 'welcome_member' === $type && $user_id === $member_id ) {
				$email['message'] .= "\nCustom ordering instructions.";
			}
			return $email;
		};
		add_filter( 'openbb_signup_email', $filter, 10, 3 );
		try {
			$this->assertTrue( OPENBB_Access::set_status( $user_id, 'active' ) );
		} finally {
			remove_filter( 'openbb_signup_email', $filter );
		}

		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'Custom ordering instructions.', $this->mail[0]['message'] );
	}

	public function test_mail_failure_is_logged_without_reverting_approval(): void {
		$user_id = $this->make_user( 'applicant' );
		$log = wp_tempnam( 'openbb-approval-mail-log' );
		$old_log = ini_set( 'error_log', $log );
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		add_filter( 'pre_wp_mail', '__return_false' );

		try {
			$this->assertTrue( OPENBB_Access::set_status( $user_id, 'active' ) );
			$this->assertSame( 'active', OPENBB_Access::get_status( $user_id ) );
			$this->assertStringContainsString(
				sprintf( 'Membership approval email could not be sent for user %d on site %d.', $user_id, get_current_blog_id() ),
				file_get_contents( $log )
			);
		} finally {
			remove_filter( 'pre_wp_mail', '__return_false' );
			ini_set( 'error_log', $old_log );
			unlink( $log );
		}
	}
}
