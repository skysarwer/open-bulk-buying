<?php
/**
 * Ordered, localized rules, immutable history and the rules settings API.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Rules extends OPENBB_TestCase {

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
	}

	private function api( string $method, string $route = '', array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/openbb/v1/settings/rules' . $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		return rest_do_request( $request );
	}

	public function test_legacy_content_is_one_item_without_rewriting_history(): void {
		global $wpdb;
		$legacy = array( 'default' => '<p>Old rules</p>', 'fr' => '<p>Anciennes regles</p>' );
		$hash   = md5( wp_json_encode( $legacy ) );
		$wpdb->insert(
			$wpdb->openbb_rules_versions,
			array(
				'content'               => wp_json_encode( $legacy ),
				'content_hash'          => $hash,
				'requires_reacceptance' => 1,
				'published_at'          => current_time( 'mysql', true ),
			)
		);
		$id = (int) $wpdb->insert_id;
		$this->assertSame( array( $legacy ), OPENBB_Rules::get( $id )->content );
		$this->assertSame( '<ol><li><p>Old rules</p></li></ol>', OPENBB_Rules::get( $id )->get_content( 'en' ) );
		$this->assertFalse( OPENBB_Rules::has_unpublished_changes() );
		$this->assertSame( $id, OPENBB_Rules::publish( true, 1 )->id );
		$this->assertSame( $hash, OPENBB_Rules::get( $id )->content_hash );
		$this->assertSame( wp_json_encode( $legacy ), $wpdb->get_var( $wpdb->prepare( "SELECT content FROM {$wpdb->openbb_rules_versions} WHERE id = %d", $id ) ) );
	}

	public function test_items_keep_order_sanitize_html_and_fall_back_individually(): void {
		$content = array(
			array( 'default' => '<p>First <strong>rule</strong><script>alert(1)</script></p>', 'fr' => '<p>Premiere</p>' ),
			array( 'default' => '<p>Second</p>', 'fr' => '' ),
		);
		OPENBB_Rules::save_draft( $content );
		$version = OPENBB_Rules::publish( false, 1 );
		$this->assertInstanceOf( OPENBB_Rules_Version::class, $version );
		$this->assertTrue( $version->requires_reacceptance );
		$this->assertStringNotContainsString( '<script>', $version->get_content() );
		$this->assertSame( '<ol><li><p>Premiere</p></li><li><p>Second</p></li></ol>', $version->get_content( 'fr' ) );
		$this->assertArrayNotHasKey( 'fr', $version->content[1] );
		OPENBB_Rules::save_draft( array_reverse( $version->content ) );
		$this->assertTrue( OPENBB_Rules::has_unpublished_changes() );
		$new = OPENBB_Rules::publish( true, 1 );
		$this->assertGreaterThan( $version->id, $new->id );
		$this->assertSame( $version->content, OPENBB_Rules::get( $version->id )->content );
	}

	public function test_translation_only_changes_do_not_invalidate_acceptance(): void {
		OPENBB_Rules::save_draft( array( array( 'default' => '<p>Rule</p>' ) ) );
		$first = OPENBB_Rules::publish( true, 1 );
		OPENBB_Rules::save_draft( array( array( 'default' => '<p>Rule</p>', 'fr' => '<p>Regle</p>' ) ) );
		$second = OPENBB_Rules::publish( true, 1 );
		$this->assertFalse( $second->requires_reacceptance );
		$this->assertSame( $first->id, OPENBB_Rules::get_required_version_id() );
		$this->assertSame( $second->id, OPENBB_Rules::publish( false, 1 )->id );
		$third = OPENBB_Rules::publish( true, 1 );
		$this->assertTrue( $third->requires_reacceptance );
		$this->assertSame( $third->id, OPENBB_Rules::get_required_version_id() );
	}

	public function test_publish_rejects_empty_rules_and_blank_default_items(): void {
		foreach ( array( array(), array( array( 'default' => '<p><br></p>' ) ), array( array( 'default' => '<p>&nbsp;</p>', 'fr' => 'Regle' ) ) ) as $content ) {
			OPENBB_Rules::save_draft( $content );
			$result = OPENBB_Rules::publish( true, 1 );
			$this->assertWPError( $result );
			$this->assertSame( 'openbb_rules_empty', $result->get_error_code() );
		}
	}

	public function test_permissions_and_rules_editor_settings_access(): void {
		wp_set_current_user( $this->make_user( 'coordinator' ) );
		$this->assertSame( 403, $this->api( 'GET' )->get_status() );
		wp_set_current_user( $this->make_user( 'member', 'subscriber', array( 'rules_editor' ) ) );
		$this->assertTrue( current_user_can( 'openbb_access_settings' ) );
		$this->assertFalse( current_user_can( 'openbb_manage_settings' ) );
		$this->assertSame( 200, $this->api( 'GET' )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/openbb/v1/settings/profile-fields' ) )->get_status() );
	}

	public function test_api_draft_publish_history_and_stale_updates(): void {
		wp_set_current_user( $this->make_user( 'manager' ) );
		$initial = $this->api( 'GET' )->get_data();
		$params  = array( 'revision' => $initial['revision'], 'content' => array( array( 'default' => '<p>First</p>' ), array( 'default' => '<p>Second</p>' ) ) );
		$saved   = $this->api( 'PUT', '', $params );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( $params['content'], $saved->get_data()['draft'] );
		$this->assertSame( 409, $this->api( 'PUT', '', $params )->get_status() );
		$this->assertSame( 409, $this->api( 'POST', '/publish', array( 'revision' => $initial['revision'], 'requires_reacceptance' => true ) )->get_status() );
		$published = $this->api( 'POST', '/publish', array( 'revision' => $saved->get_data()['revision'], 'requires_reacceptance' => false ) )->get_data();
		$this->assertCount( 1, $published['versions'] );
		$this->assertSame( $params['content'], $published['versions'][0]['content'] );
		$this->assertTrue( $published['versions'][0]['requires_reacceptance'] );
		$this->assertFalse( $published['unpublished'] );
		$this->assertSame( 409, $this->api( 'POST', '/discard', array( 'revision' => $saved->get_data()['revision'] ) )->get_status() );
		$empty = $this->api( 'PUT', '', array( 'revision' => $published['revision'], 'content' => array() ) )->get_data();
		$discarded = $this->api( 'POST', '/discard', array( 'revision' => $empty['revision'] ) )->get_data();
		$this->assertSame( $params['content'], $discarded['draft'] );
		$this->assertCount( 1, $discarded['versions'] );
	}

	public function test_api_rejects_malformed_items(): void {
		wp_set_current_user( $this->make_user( 'manager' ) );
		$revision = $this->api( 'GET' )->get_data()['revision'];
		foreach ( array( array( 'plain string' ), array( array( 'fr' => 'Missing default' ) ), array( array( 'default' => array( 'nested' ) ) ) ) as $content ) {
			$this->assertSame( 400, $this->api( 'PUT', '', array( 'revision' => $revision, 'content' => $content ) )->get_status() );
		}
	}
}
