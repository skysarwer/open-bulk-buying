<?php
/**
 * Profile field schema, values, access and cleanup.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Profile_Fields extends OPENBB_TestCase {

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
	}

	private function api( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/openbb/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}

		return rest_do_request( $request );
	}

	private function diet_field( array $overrides = array() ): array {
		return array_merge(
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
							),
						),
						array(
							'key'     => 'allergies',
							'label'   => array( 'default' => 'Allergies' ),
							'options' => array(
								array(
									'key'   => 'nuts',
									'label' => array( 'default' => 'Nuts' ),
								),
							),
						),
					),
				),
			),
			$overrides
		);
	}

	private function boolean_field( string $key, array $overrides = array() ): array {
		return array_merge(
			array(
				'key'   => $key,
				'label' => array( 'default' => ucfirst( $key ) ),
				'type'  => 'boolean',
			),
			$overrides
		);
	}

	private function save( array $fields ): array {
		$schema = OPENBB_Profile_Fields::save_schema( $fields, OPENBB_Profile_Fields::get_schema()['revision'] );
		$this->assertNotWPError( $schema );

		return $schema;
	}

	public function test_key_from_label(): void {
		$this->assertSame( 'dietary_needs', OPENBB_Profile_Fields::key_from_label( 'Dietary needs!' ) );
		$this->assertSame( 'allergies_ete', OPENBB_Profile_Fields::key_from_label( '2 Allergies — été' ) );
		$this->assertSame( '', OPENBB_Profile_Fields::key_from_label( 'アレルギー' ) );
	}

	public function test_reserved_keys_are_rejected(): void {
		$result = OPENBB_Profile_Fields::save_schema( array( $this->boolean_field( 'coop_role' ) ), 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'openbb_invalid_profile_fields', $result->get_error_code() );
	}

	public function test_values_use_their_own_meta_namespace(): void {
		$this->assertNotSame( openbb_member_meta_key( 'grants' ), OPENBB_Profile_Fields::meta_key( 'grants' ) );
	}

	public function test_saved_keys_and_types_are_immutable(): void {
		$this->save( array( $this->boolean_field( 'newsletter' ) ) );

		$removed = OPENBB_Profile_Fields::save_schema( array(), 1 );
		$retyped = OPENBB_Profile_Fields::save_schema( array( $this->boolean_field( 'newsletter', array( 'type' => 'text' ) ) ), 1 );

		$this->assertWPError( $removed );
		$this->assertWPError( $retyped );
	}

	public function test_stale_revision_is_a_conflict(): void {
		$this->save( array( $this->boolean_field( 'newsletter' ) ) );

		$result = OPENBB_Profile_Fields::save_schema( array( $this->boolean_field( 'newsletter' ) ), 0 );

		$this->assertWPError( $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_multiple_options_are_stored_one_row_each_and_filterable(): void {
		$this->save( array( $this->diet_field() ) );
		$member = $this->make_user( 'member' );
		$other  = $this->make_user( 'member' );

		$values = OPENBB_Profile_Fields::validate_values( $member, array( 'diet' => array( 'vegan', 'nuts' ) ), 'staff' );
		$this->assertNotWPError( $values );
		$this->assertTrue( OPENBB_Profile_Fields::set_values( $member, $values, 'staff' ) );

		$this->assertSame( array( 'vegan', 'nuts' ), get_user_meta( $member, OPENBB_Profile_Fields::meta_key( 'diet' ) ) );
		$this->assertSame( array( 'diet' => array( 'vegan', 'nuts' ) ), OPENBB_Profile_Fields::get_values( $member, 'staff' ) );

		$field = OPENBB_Profile_Fields::get_fields()['diet'];
		$query = new WP_User_Query(
			array(
				'fields'     => 'ID',
				'meta_query' => array( OPENBB_Profile_Fields::meta_query_clause( $field, 'nuts' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$this->assertSame( array( (string) $member ), $query->get_results() );
		$this->assertNotContains( (string) $other, $query->get_results() );
	}

	public function test_unknown_and_extra_options_are_rejected(): void {
		$this->save( array( $this->diet_field( array( 'options' => array_merge( $this->diet_field()['options'], array( 'multiple' => false ) ) ) ) ) );
		$member = $this->make_user( 'member' );

		$unknown = OPENBB_Profile_Fields::validate_values( $member, array( 'diet' => 'meat' ), 'staff' );
		$extra   = OPENBB_Profile_Fields::validate_values( $member, array( 'diet' => array( 'vegan', 'nuts' ) ), 'staff' );

		$this->assertWPError( $unknown );
		$this->assertArrayHasKey( 'diet', $unknown->get_error_data()['errors'] );
		$this->assertWPError( $extra );
	}

	public function test_required_applies_to_member_contexts_only(): void {
		$this->save( array( $this->boolean_field( 'agree', array( 'required' => true ) ) ) );
		$member = $this->make_user( 'member' );

		$this->assertWPError( OPENBB_Profile_Fields::validate_values( $member, array( 'agree' => false ), 'signup' ) );
		$this->assertWPError( OPENBB_Profile_Fields::validate_values( $member, array(), 'self' ) );
		$this->assertNotWPError( OPENBB_Profile_Fields::validate_values( $member, array(), 'staff' ) );
	}

	public function test_staff_fields_are_hidden_from_members(): void {
		$this->save(
			array(
				$this->boolean_field( 'newsletter' ),
				$this->boolean_field( 'flagged', array( 'visibility' => 'staff' ) ),
			)
		);

		$this->assertSame( array( 'newsletter' ), array_keys( OPENBB_Profile_Fields::get_fields_for_context( 'self' ) ) );
		$this->assertSame( array( 'newsletter', 'flagged' ), array_keys( OPENBB_Profile_Fields::get_fields_for_context( 'staff' ) ) );
	}

	public function test_boolean_false_deletes_the_row(): void {
		$this->save( array( $this->boolean_field( 'newsletter' ) ) );
		$member = $this->make_user( 'member' );
		$key    = OPENBB_Profile_Fields::meta_key( 'newsletter' );

		OPENBB_Profile_Fields::set_values( $member, array( 'newsletter' => true ), 'staff' );
		$this->assertSame( '1', get_user_meta( $member, $key, true ) );

		OPENBB_Profile_Fields::set_values( $member, array( 'newsletter' => false ), 'staff' );
		$this->assertSame( array(), get_user_meta( $member, $key ) );
	}

	public function test_purge_requires_archive_and_retires_the_key(): void {
		$this->save( array( $this->boolean_field( 'newsletter' ) ) );
		$member = $this->make_user( 'member' );
		OPENBB_Profile_Fields::set_values( $member, array( 'newsletter' => true ), 'staff' );

		$this->assertWPError( OPENBB_Profile_Fields::purge_field( 'newsletter' ) );

		$this->save( array( $this->boolean_field( 'newsletter', array( 'archived' => true ) ) ) );
		$schema = OPENBB_Profile_Fields::purge_field( 'newsletter' );

		$this->assertNotWPError( $schema );
		$this->assertSame( array(), $schema['fields'] );
		$this->assertSame( array( 'newsletter' ), $schema['retired_keys'] );
		$this->assertSame( array(), get_user_meta( $member, OPENBB_Profile_Fields::meta_key( 'newsletter' ) ) );
		$this->assertWPError( OPENBB_Profile_Fields::save_schema( array( $this->boolean_field( 'newsletter' ) ), $schema['revision'] ) );
	}

	public function test_delete_all_data_removes_schema_and_values(): void {
		$this->save( array( $this->boolean_field( 'newsletter' ) ) );
		$member = $this->make_user( 'member' );
		OPENBB_Profile_Fields::set_values( $member, array( 'newsletter' => true ), 'staff' );

		OPENBB_Maintenance::delete_all_site_data();

		$this->assertFalse( get_option( OPENBB_Profile_Fields::OPTION ) );
		$this->assertSame( array(), get_user_meta( $member, OPENBB_Profile_Fields::meta_key( 'newsletter' ) ) );
	}

	public function test_manage_profile_fields_follows_manage_settings(): void {
		$manager     = $this->make_user( 'manager' );
		$coordinator = $this->make_user( 'coordinator' );

		$this->assertTrue( user_can( $manager, 'openbb_manage_profile_fields' ) );
		$this->assertFalse( user_can( $coordinator, 'openbb_manage_profile_fields' ) );
	}

	public function test_rest_routes_check_access_and_confirmation(): void {
		wp_set_current_user( $this->make_user( 'coordinator' ) );
		$this->assertSame( 403, $this->api( 'GET', '/settings/profile-fields' )->get_status() );

		wp_set_current_user( $this->make_user( 'manager' ) );
		$saved = $this->api(
			'PUT',
			'/settings/profile-fields',
			array(
				'revision' => 0,
				'fields'   => array( $this->boolean_field( 'newsletter', array( 'archived' => true ) ) ),
			)
		);
		$this->assertSame( 200, $saved->get_status() );

		$unconfirmed = $this->api( 'POST', '/settings/profile-fields/newsletter/purge', array( 'confirmation' => 'nope' ) );
		$purged      = $this->api( 'POST', '/settings/profile-fields/newsletter/purge', array( 'confirmation' => 'newsletter' ) );

		$this->assertSame( 400, $unconfirmed->get_status() );
		$this->assertSame( 200, $purged->get_status() );
	}
}
