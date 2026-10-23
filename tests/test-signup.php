<?php
/**
 * Creating new member accounts from the Members screen.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Signup extends OPENBB_TestCase {

	/** @var string[] Recipients of mail sent during the test. */
	private $mail = array();

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		$this->mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		wp_set_current_user( $this->make_user( 'manager' ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		parent::tear_down();
	}

	public function capture_mail( $result, array $atts ) {
		$this->mail[] = $atts['to'];
		return true;
	}

	private function create( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/openbb/v1/members' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );

		return rest_do_request( $request );
	}

	private function account( array $overrides = array() ): array {
		return array_merge(
			array(
				'email'      => 'jane.doe@example.com',
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'phone'      => '555-0100',
			),
			$overrides
		);
	}

	public function test_creates_member_with_random_password_and_sends_email(): void {
		$response = $this->create( array( 'account' => $this->account() ) );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['email_sent'] );
		$this->assertSame( array( 'jane.doe@example.com' ), $this->mail );

		$user = get_userdata( $data['id'] );
		$this->assertSame( 'janedoe', $user->user_login );
		$this->assertSame( array( 'subscriber' ), $user->roles );
		$this->assertSame( 'Jane Doe', $user->display_name );
		$this->assertSame( 'active', OPENBB_Access::get_status( $user->ID ) );
		$this->assertSame( array(), OPENBB_Access::get_roles( $user->ID ) );
		$this->assertSame( '555-0100', openbb_get_member_field( $user->ID, 'phone' ) );
		$this->assertNotSame( '', (string) openbb_get_member_field( $user->ID, 'joined_at' ) );
	}

	public function test_send_email_can_be_turned_off(): void {
		$response = $this->create(
			array(
				'account'    => $this->account(),
				'send_email' => false,
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertFalse( $response->get_data()['email_sent'] );
		$this->assertSame( array(), $this->mail );
	}

	public function test_placeholder_email_is_never_mailed(): void {
		$response = $this->create( array( 'account' => $this->account( array( 'email' => 'jane.4821@noemail.invalid' ) ) ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertFalse( $response->get_data()['email_sent'] );
		$this->assertSame( array(), $this->mail );
		$this->assertFalse( openbb_can_email_member( $response->get_data()['id'] ) );
	}

	public function test_username_gets_a_suffix_when_taken(): void {
		self::factory()->user->create( array( 'user_login' => 'janedoe' ) );

		$response = $this->create( array( 'account' => $this->account() ) );

		$this->assertSame( 'janedoe2', get_userdata( $response->get_data()['id'] )->user_login );
	}

	public function test_validates_account_fields(): void {
		$response = $this->create(
			array(
				'account' => array(
					'email'      => 'not-an-email',
					'first_name' => ' ',
				),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'email', 'first_name' ), array_keys( $response->get_data()['data']['errors'] ) );
	}

	public function test_existing_email_points_to_existing_user(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'jane.doe@example.com' ) );
		$before  = count_users()['total_users'];

		$response = $this->create( array( 'account' => $this->account() ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'openbb_email_exists', $response->get_data()['code'] );
		$this->assertSame( $user_id, $response->get_data()['data']['user_id'] );
		$this->assertSame( $before, count_users()['total_users'] );
	}

	public function test_requires_user_id_or_account(): void {
		$this->assertSame( 400, $this->create( array() )->get_status() );
	}

	public function test_coordinator_can_create_members_but_not_staff(): void {
		wp_set_current_user( $this->make_user( 'coordinator' ) );

		$staff = $this->create(
			array(
				'account' => $this->account(),
				'roles'   => array( 'distribution_helper' ),
			)
		);
		$this->assertSame( 403, $staff->get_status() );
		$this->assertFalse( email_exists( 'jane.doe@example.com' ) );

		$applicant = $this->create(
			array(
				'account' => $this->account(),
				'status'  => 'applicant',
			)
		);
		$this->assertSame( 201, $applicant->get_status() );
		$this->assertSame( 'applicant', OPENBB_Access::get_status( $applicant->get_data()['id'] ) );
	}

	public function test_access_managers_create_members_with_roles(): void {
		$response = $this->create(
			array(
				'account' => $this->account(),
				'roles'   => array( 'coordinator' ),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( array( 'coordinator' ), OPENBB_Access::get_roles( $response->get_data()['id'] ) );
		$this->assertSame( 400, $this->create( array( 'account' => $this->account( array( 'email' => 'x@example.com' ) ), 'status' => 'inactive' ) )->get_status() );
	}

	public function test_members_cannot_create_accounts(): void {
		wp_set_current_user( $this->make_user( 'member' ) );

		$this->assertSame( 403, $this->create( array( 'account' => $this->account() ) )->get_status() );
		$this->assertFalse( email_exists( 'jane.doe@example.com' ) );
	}

	public function test_required_profile_fields_are_checked_before_creating(): void {
		$schema = OPENBB_Profile_Fields::save_schema(
			array(
				array(
					'key'      => 'household',
					'label'    => array( 'default' => 'Household' ),
					'type'     => 'text',
					'required' => true,
				),
			),
			OPENBB_Profile_Fields::get_schema()['revision']
		);
		$this->assertNotWPError( $schema );

		$response = $this->create( array( 'account' => $this->account() ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( email_exists( 'jane.doe@example.com' ) );

		$response = $this->create(
			array(
				'account' => $this->account(),
				'profile' => array( 'household' => 'Three' ),
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Three', ( (array) $response->get_data()['profile'] )['household'] );
	}

	public function test_rolls_back_when_saving_fails_after_insert(): void {
		$fail = static function ( $check, $object_id, $meta_key ) {
			return str_ends_with( $meta_key, 'openbb_status' ) ? false : $check;
		};
		add_filter( 'add_user_metadata', $fail, 10, 3 );
		add_filter( 'update_user_metadata', $fail, 10, 3 );
		$before = count_users()['total_users'];

		$response = $this->create( array( 'account' => $this->account() ) );

		remove_filter( 'add_user_metadata', $fail );
		remove_filter( 'update_user_metadata', $fail );
		$this->assertSame( 500, $response->get_status() );
		$this->assertFalse( email_exists( 'jane.doe@example.com' ) );
		$this->assertSame( $before, count_users()['total_users'] );
		$this->assertSame( array(), $this->mail );
	}

	public function test_new_user_caps(): void {
		$coordinator = $this->make_user( 'coordinator' );

		$this->assertTrue( user_can( $coordinator, 'openbb_create_members' ) );
		$this->assertTrue( user_can( $coordinator, 'openbb_set_member_status', 0, 'active' ) );
		$this->assertTrue( user_can( $coordinator, 'openbb_set_member_status', 0, 'applicant' ) );
		$this->assertFalse( user_can( $coordinator, 'openbb_set_member_status', 0, 'inactive' ) );
		$this->assertFalse( user_can( $coordinator, 'openbb_set_member_roles', 0, array( 'distribution_helper' ) ) );
		$this->assertFalse( user_can( $coordinator, 'openbb_set_member_status', 'abc', 'active' ) );
		$this->assertFalse( user_can( $coordinator, 'openbb_edit_member', 0 ) );
		$this->assertFalse( user_can( $this->make_user( 'member' ), 'openbb_create_members' ) );
	}
}
