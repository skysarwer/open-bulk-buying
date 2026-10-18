<?php
/**
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Privacy extends OPENBB_TestCase {

	const KEY = 'open-bulk-buying-profile-fields';

	private function save_fields(): void {
		$schema = OPENBB_Profile_Fields::save_schema(
			array(
				array(
					'key'     => 'diet',
					'label'   => array( 'default' => 'Diet' ),
					'type'    => 'options',
					'options' => array(
						'style'    => 'chips',
						'multiple' => true,
						'groups'   => array(
							array(
								'key'     => '',
								'options' => array(
									array(
										'key'   => 'vegan',
										'label' => array( 'default' => 'Vegan' ),
									),
									array(
										'key'   => 'halal',
										'label' => array( 'default' => 'Halal' ),
									),
								),
							),
						),
					),
				),
				array(
					'key'   => 'newsletter',
					'label' => array( 'default' => 'Newsletter' ),
					'type'  => 'boolean',
				),
				array(
					'key'        => 'notes',
					'label'      => array( 'default' => 'Notes' ),
					'type'       => 'text',
					'visibility' => 'staff',
				),
				array(
					'key'   => 'empty',
					'label' => array( 'default' => 'Empty' ),
					'type'  => 'text',
				),
			),
			OPENBB_Profile_Fields::get_schema()['revision']
		);
		$this->assertNotWPError( $schema );
	}

	private function member_with_values(): WP_User {
		$this->save_fields();
		$user_id = $this->make_user( 'member' );
		$this->assertNotWPError(
			OPENBB_Profile_Fields::set_values(
				$user_id,
				array(
					'diet'       => array( 'vegan', 'halal' ),
					'newsletter' => true,
					'notes'      => 'Prefers pickup',
				),
				OPENBB_Profile_Fields::CONTEXT_STAFF
			)
		);

		return get_userdata( $user_id );
	}

	private function callback( string $filter ): callable {
		$registered = apply_filters( $filter, array() );
		$this->assertArrayHasKey( self::KEY, $registered );

		return $registered[ self::KEY ]['callback'];
	}

	public function test_exports_labelled_values_including_staff_fields(): void {
		$user   = $this->member_with_values();
		$result = call_user_func( $this->callback( 'wp_privacy_personal_data_exporters' ), $user->user_email, 1 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'openbb-profile-fields', $result['data'][0]['group_id'] );
		$this->assertSame(
			array(
				array(
					'name'  => 'Diet',
					'value' => 'Vegan; Halal',
				),
				array(
					'name'  => 'Newsletter',
					'value' => 'Yes',
				),
				array(
					'name'  => 'Notes',
					'value' => 'Prefers pickup',
				),
			),
			$result['data'][0]['data']
		);
	}

	public function test_member_without_values_exports_nothing(): void {
		$this->save_fields();
		$user   = get_userdata( $this->make_user( 'member' ) );
		$result = call_user_func( $this->callback( 'wp_privacy_personal_data_exporters' ), $user->user_email, 1 );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_erase_deletes_all_profile_meta_including_unknown_keys(): void {
		$user = $this->member_with_values();
		add_user_meta( $user->ID, OPENBB_Profile_Fields::meta_key( 'removed_field' ), 'leftover' );
		$other = $this->member_with_values();

		$result = call_user_func( $this->callback( 'wp_privacy_personal_data_erasers' ), $user->user_email, 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array(), OPENBB_Privacy_Profile_Fields::stored_meta_keys( $user->ID ) );
		$this->assertNotEmpty( OPENBB_Privacy_Profile_Fields::stored_meta_keys( $other->ID ) );
	}

	public function test_unknown_email_is_a_no_op(): void {
		$export = call_user_func( $this->callback( 'wp_privacy_personal_data_exporters' ), 'nobody@example.invalid', 1 );
		$erase  = call_user_func( $this->callback( 'wp_privacy_personal_data_erasers' ), 'nobody@example.invalid', 1 );

		$this->assertSame( array(), $export['data'] );
		$this->assertFalse( $erase['items_removed'] );
	}

	public function test_providers_filter_ignores_invalid_entries(): void {
		$filter = static function ( array $providers ): array {
			$providers[] = 'not a provider';
			return $providers;
		};
		add_filter( 'openbb_privacy_providers', $filter );
		$providers = OPENBB_Privacy::providers();
		remove_filter( 'openbb_privacy_providers', $filter );

		$this->assertSame( array( 'profile-fields' ), array_keys( $providers ) );
	}
}
