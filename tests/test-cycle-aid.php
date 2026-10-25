<?php
/**
 * Cycle workspace access and eligibility-only aid decisions.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Cycle_Aid extends OPENBB_TestCase {

	private $cycle_id;
	private $order_id;
	private $reviewer;

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		$this->reviewer = $this->make_user( 'aid_reviewer' );
		$this->cycle_id = $this->make_cycle( OPENBB_Cycle::STATUS_PROCESSING );
		$this->order_id = $this->make_request( $this->cycle_id, $this->make_user( 'member' ) );
		wp_set_current_user( $this->reviewer );
	}

	private function make_request( int $cycle_id, int $user_id ): int {
		global $wpdb;
		$id = $this->make_order( $cycle_id, $user_id );
		$wpdb->update(
			$wpdb->openbb_orders,
			array(
				'aid_status' => 'pending',
				'aid_reason' => 'Private request',
				'subtotal'   => 1200,
				'total'      => 1000,
				'donation'   => 100,
				'container_credit' => 300,
			),
			array( 'id' => $id )
		);
		return $id;
	}

	private function api( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/openbb/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return rest_do_request( $request );
	}

	private function decide( string $decision = 'approved', ?int $revision = null ): WP_REST_Response {
		return $this->api(
			'POST',
			"/cycles/{$this->cycle_id}/aid/{$this->order_id}",
			array(
				'decision' => $decision,
				'expected_revision' => $revision ?? OPENBB_Orders::get( $this->order_id )->revision,
			)
		);
	}

	public function test_reviewer_can_read_cycles_but_cannot_edit_them_or_read_general_orders(): void {
		$this->assertTrue( current_user_can( 'openbb_access_cycles' ) );
		$this->assertFalse( current_user_can( 'openbb_manage_cycles' ) );
		$this->assertSame( 200, $this->api( 'GET', '/cycles' )->get_status() );
		$this->assertSame( 200, $this->api( 'GET', "/cycles/{$this->cycle_id}" )->get_status() );
		$this->assertSame( 403, $this->api( 'PUT', "/cycles/{$this->cycle_id}", array( 'name' => 'Changed' ) )->get_status() );
		$this->assertSame( 403, $this->api( 'GET', '/orders' )->get_status() );
		$list = $this->api( 'GET', "/cycles/{$this->cycle_id}/aid" );
		$this->assertSame( 200, $list->get_status() );
		$this->assertSame( 'Private request', $list->get_data()[0]['aid_reason'] );
		$this->assertTrue( $list->get_data()[0]['can_decide'] );
	}

	public function test_decisions_preserve_all_amounts_and_record_actor_date_and_event(): void {
		global $wpdb;
		$before = OPENBB_Orders::get( $this->order_id );
		$response = $this->decide();
		$this->assertSame( 200, $response->get_status() );
		$saved = OPENBB_Orders::get( $this->order_id );
		$this->assertSame( 'approved', $saved->aid_status );
		$this->assertSame( $this->reviewer, $saved->aid_decided_by );
		$this->assertNotEmpty( $saved->aid_decided_at );
		$this->assertSame( $before->revision + 1, $saved->revision );
		foreach ( array( 'subtotal', 'container_credit', 'donation', 'total', 'aid_requested', 'aid_approved' ) as $field ) {
			$this->assertSame( $before->$field, $saved->$field, $field );
		}
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->openbb_order_events} WHERE order_id = %d", $this->order_id ), ARRAY_A );
		$this->assertSame( 'aid_approved', $event['event_type'] );
		$this->assertSame( 'pending -> approved', $event['reason'] );
		$this->assertSame( 409, $this->decide( 'declined', $before->revision )->get_status() );
		$this->assertSame( 200, $this->decide( 'declined' )->get_status() );
		$this->assertSame( 'declined', OPENBB_Orders::get( $this->order_id )->aid_status );
	}

	public function test_requests_are_cycle_scoped_paginated_searchable_and_filterable(): void {
		global $wpdb;
		$other_cycle = $this->make_cycle( OPENBB_Cycle::STATUS_PROCESSING );
		$this->make_request( $other_cycle, $this->make_user( 'member' ) );
		$draft = $this->make_request( $this->cycle_id, $this->make_user( 'member' ) );
		$wpdb->update( $wpdb->openbb_orders, array( 'status' => 'draft' ), array( 'id' => $draft ) );
		$list = $this->api( 'GET', "/cycles/{$this->cycle_id}/aid", array( 'per_page' => 1 ) );
		$this->assertCount( 1, $list->get_data() );
		$this->assertSame( $this->order_id, $list->get_data()[0]['id'] );
		$this->assertSame( '1', $list->get_headers()['X-WP-Total'] );
		$this->assertCount( 0, $this->api( 'GET', "/cycles/{$this->cycle_id}/aid", array( 'aid_status' => array( 'approved' ) ) )->get_data() );
		$this->assertCount( 1, $this->api( 'GET', "/cycles/{$this->cycle_id}/aid", array( 'search' => (string) $this->order_id ) )->get_data() );
		$this->assertSame( 404, $this->api( 'POST', "/cycles/{$other_cycle}/aid/{$this->order_id}", array( 'decision' => 'approved', 'expected_revision' => 0 ) )->get_status() );
		$this->assertSame( 404, $this->api( 'GET', '/cycles/999999/aid' )->get_status() );
	}

	public function test_members_non_reviewers_and_request_owners_cannot_decide(): void {
		$owner = OPENBB_Orders::get( $this->order_id )->user_id;
		add_user_meta( $owner, openbb_member_meta_key( 'roles' ), 'aid_reviewer' );
		wp_set_current_user( $owner );
		$this->assertFalse( $this->api( 'GET', "/cycles/{$this->cycle_id}/aid" )->get_data()[0]['can_decide'] );
		$this->assertSame( 403, $this->decide()->get_status() );
		wp_set_current_user( $this->make_user( 'member' ) );
		$this->assertSame( 403, $this->api( 'GET', "/cycles/{$this->cycle_id}/aid" )->get_status() );
		$this->assertSame( 403, $this->decide()->get_status() );
		wp_set_current_user( $this->make_user( 'distribution_helper' ) );
		$this->assertSame( 403, $this->api( 'GET', "/cycles/{$this->cycle_id}/aid" )->get_status() );
	}

	public function test_locked_requests_and_invalid_or_missing_decisions_are_rejected(): void {
		global $wpdb;
		foreach ( array(
			array( 'status' => 'draft' ),
			array( 'status' => 'prepaid' ),
			array( 'status' => 'fulfilled' ),
			array( 'status' => 'cancelled' ),
			array( 'aid_approved' => 100 ),
			array( 'aid_status' => 'none' ),
			array( 'paid_at' => '2026-01-01 00:00:00' ),
		) as $change ) {
			$wpdb->update( $wpdb->openbb_orders, $change, array( 'id' => $this->order_id ) );
			$this->assertSame( 409, $this->decide()->get_status(), wp_json_encode( $change ) );
			$wpdb->update( $wpdb->openbb_orders, array( 'status' => 'submitted', 'aid_approved' => 0, 'aid_status' => 'pending', 'paid_at' => null ), array( 'id' => $this->order_id ) );
		}
		$this->assertSame( 400, $this->decide( 'pending' )->get_status() );
		$this->assertSame( 400, $this->api( 'POST', "/cycles/{$this->cycle_id}/aid/{$this->order_id}", array( 'decision' => 'approved' ) )->get_status() );
		$wpdb->update( $wpdb->openbb_cycles, array( 'status' => 'complete' ), array( 'id' => $this->cycle_id ) );
		$this->assertSame( 409, $this->decide()->get_status() );
	}

	public function test_failed_event_rolls_back_decision(): void {
		global $wpdb;
		$fail = static function ( $query ) use ( $wpdb ) {
			return false !== strpos( $query, "INSERT INTO `{$wpdb->openbb_order_events}`" ) ? 'SELECT * FROM openbb_missing_aid_test_table' : $query;
		};
		add_filter( 'query', $fail );
		$wpdb->suppress_errors( true );
		try {
			$response = $this->decide();
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( false );
		}
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'pending', OPENBB_Orders::get( $this->order_id )->aid_status );
		$this->assertSame( 0, OPENBB_Orders::get( $this->order_id )->revision );
	}
}
