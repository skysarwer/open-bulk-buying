<?php
/**
 * General settings persistence, validation and access.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_General_Settings extends OPENBB_TestCase {

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		wp_set_current_user( $this->make_user( 'manager' ) );
		delete_option( OPENBB_Settings::GENERAL_OPTION );
	}

	private function api( string $method, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/openbb/v1/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		return rest_do_request( $request );
	}

	public function test_defaults_close_signups_and_login_links_require_approval_and_have_no_fee(): void {
		$response = $this->api( 'GET' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'allow_signups'                 => false,
				'allow_login_links'             => false,
				'require_member_approval'       => true,
				'membership_fee_type'           => 'one_time',
				'membership_fee_amount'         => 0,
				'membership_fee_order_interval' => 1,
			),
			$response->get_data()['general']
		);
	}

	public function test_round_trips_fee_types_and_approval_without_changing_members(): void {
		$applicant = $this->make_user( 'applicant' );
		$member    = $this->make_user( 'member' );
		foreach ( array( 'recurring', 'one_time' ) as $type ) {
			$general = array(
				'allow_signups'                 => true,
				'allow_login_links'             => true,
				'require_member_approval'       => false,
				'membership_fee_type'           => $type,
				'membership_fee_amount'         => 1250,
				'membership_fee_order_interval' => 5,
			);
			$response = $this->api( 'POST', array( 'general' => $general ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $general, $response->get_data()['general'] );
			$this->assertSame( $general, get_option( OPENBB_Settings::GENERAL_OPTION ) );
			$this->assertSame( $general, $this->api( 'GET' )->get_data()['general'] );
			$this->assertSame( 200, $this->api( 'POST', array( 'general' => $general ) )->get_status() );
			$this->assertSame( 200, $this->api( 'POST', array( 'general' => array_reverse( $general, true ) ) )->get_status() );
		}
		$this->assertSame( 'applicant', OPENBB_Access::get_status( $applicant ) );
		$this->assertSame( 'active', OPENBB_Access::get_status( $member ) );
	}

	public function test_invalid_settings_do_not_write(): void {
		$defaults = OPENBB_Settings::get_general();
		$invalid  = array(
			array( 'membership_fee_type' => 'monthly' ),
			array( 'membership_fee_amount' => -1 ),
			array( 'membership_fee_amount' => 1.5 ),
			array( 'membership_fee_amount' => 9007199254740992 ),
			array( 'membership_fee_order_interval' => 0 ),
			array( 'membership_fee_order_interval' => 1.5 ),
			array( 'membership_fee_order_interval' => 'many' ),
			array( 'require_member_approval' => 'maybe' ),
			array( 'allow_signups' => 'yes' ),
			array( 'allow_login_links' => 'yes' ),
			array( 'unexpected' => true ),
		);
		foreach ( $invalid as $changes ) {
			$response = $this->api( 'POST', array( 'general' => array_merge( $defaults, $changes ) ) );
			$this->assertSame( 400, $response->get_status(), wp_json_encode( $changes ) );
			$this->assertSame( $defaults, OPENBB_Settings::get_general() );
		}
		$this->assertSame( 400, $this->api( 'POST', array( 'general' => array( 'require_member_approval' => false ) ) )->get_status() );
		$this->assertFalse( get_option( OPENBB_Settings::GENERAL_OPTION ) );
	}

	public function test_general_and_maintenance_saves_do_not_overwrite_each_other(): void {
		$general = array_merge( OPENBB_Settings::get_general(), array( 'membership_fee_amount' => 500 ) );
		$this->assertSame( 200, $this->api( 'POST', array( 'general' => $general ) )->get_status() );
		$response = $this->api( 'POST', array( 'test_mode' => true ) );
		$this->assertSame( $general, $response->get_data()['general'] );
		$this->assertTrue( $response->get_data()['test_mode'] );
		$this->assertSame( 200, $this->api( 'POST', array( 'general' => $general ) )->get_status() );
		$this->assertTrue( OPENBB_Maintenance::is_test_mode() );
	}

	public function test_save_failures_are_reported(): void {
		update_option( OPENBB_Settings::GENERAL_OPTION, OPENBB_Settings::get_general(), false );
		$fail = static function ( $value, $old_value ) {
			return $old_value;
		};
		add_filter( 'pre_update_option_' . OPENBB_Settings::GENERAL_OPTION, $fail, 10, 2 );
		$general  = array_merge( OPENBB_Settings::get_general(), array( 'membership_fee_amount' => 500 ) );
		$response = $this->api( 'POST', array( 'general' => $general ) );
		remove_filter( 'pre_update_option_' . OPENBB_Settings::GENERAL_OPTION, $fail );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'openbb_settings_save_failed', $response->get_data()['code'] );
	}

	public function test_only_settings_managers_can_read_or_write(): void {
		$general = OPENBB_Settings::get_general();
		foreach ( array( 'coordinator', 'member', 'applicant', '' ) as $role ) {
			wp_set_current_user( $this->make_user( $role, 'subscriber', array( 'rules_editor' ) ) );
			$this->assertSame( 403, $this->api( 'GET' )->get_status() );
			$this->assertSame( 403, $this->api( 'POST', array( 'general' => $general ) )->get_status() );
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->api( 'GET' )->get_status() );
		$this->assertSame( 401, $this->api( 'POST', array( 'general' => $general ) )->get_status() );
	}
}
