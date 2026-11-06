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
		$this->assertArrayHasKey( 'due_stage', $this->api( 'GET', "/cycles/{$this->cycle_id}" )->get_data() );
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

	public function test_summary_totals_cycle_donations_and_allocated_aid(): void {
		global $wpdb;
		$paid = $this->make_request( $this->cycle_id, $this->make_user( 'member' ) );
		$wpdb->update( $wpdb->openbb_orders, array( 'donation' => 250, 'aid_status' => 'approved', 'aid_approved' => 150, 'paid_at' => '2026-01-01 00:00:00' ), array( 'id' => $paid ) );
		foreach ( array( 'draft', 'cancelled' ) as $status ) {
			$ignored = $this->make_request( $this->cycle_id, $this->make_user( 'member' ) );
			$wpdb->update( $wpdb->openbb_orders, array( 'status' => $status, 'donation' => 9999, 'aid_approved' => 9999 ), array( 'id' => $ignored ) );
		}
		$this->make_request( $this->make_cycle( OPENBB_Cycle::STATUS_PROCESSING ), $this->make_user( 'member' ) );

		$response = $this->api( 'GET', "/cycles/{$this->cycle_id}/aid/summary" );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			array( 'pool' => 200, 'eligible' => 0, 'recipients' => 0, 'percent' => 0.0, 'allocated' => 0, 'can_distribute' => true ),
			$data['distribution']
		);
		unset( $data['distribution'] );
		$this->assertSame(
			array(
				'donated'   => 350,
				'received'  => 250,
				'donors'    => 2,
				'allocated' => 150,
				'available' => 200,
				'requests'  => array( 'pending' => 1, 'approved' => 1, 'declined' => 0 ),
			),
			$data
		);
		$this->assertSame( 404, $this->api( 'GET', '/cycles/999999/aid/summary' )->get_status() );
		wp_set_current_user( $this->make_user( 'member' ) );
		$this->assertSame( 403, $this->api( 'GET', "/cycles/{$this->cycle_id}/aid/summary" )->get_status() );
	}

	public function test_equal_share_gives_every_amount_the_largest_fundable_percentage(): void {
		// A pool covering everything gives 100% and leaves the rest unallocated.
		$this->assertSame( array( 1 => 900 ), OPENBB_Order_Aid::equal_share( 5000, array( 1 => 900 ) ) );
		$this->assertSame( array( 1 => 500, 2 => 1000 ), OPENBB_Order_Aid::equal_share( 3000, array( 1 => 500, 2 => 1000 ) ) );
		// 50% of each.
		$this->assertSame( array( 1 => 250, 2 => 500 ), OPENBB_Order_Aid::equal_share( 750, array( 1 => 500, 2 => 1000 ) ) );
		// Leftover cents go to the largest remainders; the pool is used exactly.
		$shares = OPENBB_Order_Aid::equal_share( 100, array( 1 => 100, 2 => 100, 3 => 100 ) );
		$this->assertSame( array( 1 => 34, 2 => 33, 3 => 33 ), $shares );
		$shares = OPENBB_Order_Aid::equal_share( 1001, array( 4 => 333, 7 => 1234, 9 => 5 ) );
		$this->assertSame( 1001, array_sum( $shares ) );
		foreach ( array( 4 => 333, 7 => 1234, 9 => 5 ) as $key => $amount ) {
			$this->assertLessThanOrEqual( $amount, $shares[ $key ] );
		}
		$this->assertSame( array( 1 => 0 ), OPENBB_Order_Aid::equal_share( 0, array( 1 => 500 ) ) );
		$this->assertSame( array( 1 => 0 ), OPENBB_Order_Aid::equal_share( 500, array( 1 => 0 ) ) );
		$this->assertSame( array(), OPENBB_Order_Aid::equal_share( 500, array() ) );
	}

	private function add_line( int $order_id, int $price, string $type = OPENBB_Order_Line::TYPE_PRODUCT ): void {
		$line = new OPENBB_Order_Line(
			array(
				'order_id'      => $order_id,
				'line_type'     => $type,
				'product_name'  => 'Item',
				'unit_price'    => $price,
				'qty_requested' => 1,
			)
		);
		$this->assertTrue( $line->save() );
	}

	public function test_distribution_applies_one_percentage_to_approved_open_orders_and_is_rerunnable(): void {
		global $wpdb;
		$wpdb->update( $wpdb->openbb_orders, array( 'aid_status' => 'approved', 'donation' => 0, 'container_credit' => 0 ), array( 'id' => $this->order_id ) );
		$this->add_line( $this->order_id, 1000 );
		$this->add_line( $this->order_id, 300, OPENBB_Order_Line::TYPE_MEMBERSHIP_FEE );
		$second = $this->make_request( $this->cycle_id, $this->make_user( 'member' ) );
		$wpdb->update( $wpdb->openbb_orders, array( 'aid_status' => 'approved', 'donation' => 0, 'container_credit' => 0 ), array( 'id' => $second ) );
		$this->add_line( $second, 3000 );
		$donor = $this->make_order( $this->cycle_id, $this->make_user( 'member' ) );
		$wpdb->update( $wpdb->openbb_orders, array( 'donation' => 2000 ), array( 'id' => $donor ) );

		$preview = $this->api( 'GET', "/cycles/{$this->cycle_id}/aid/summary" )->get_data()['distribution'];
		$this->assertSame( 2000, $preview['pool'] );
		$this->assertSame( 4000, $preview['eligible'] );
		$this->assertSame( 50.0, $preview['percent'] );
		$this->assertSame( 0, OPENBB_Orders::get( $second )->aid_approved, 'Previewing must not allocate.' );

		$this->assertSame( 400, $this->api( 'POST', "/cycles/{$this->cycle_id}/aid/distribute", array( 'confirmed' => false ) )->get_status() );
		$response = $this->api( 'POST', "/cycles/{$this->cycle_id}/aid/distribute", array( 'confirmed' => true ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2000, $response->get_data()['allocated'] );
		$this->assertSame( 0, $response->get_data()['available'] );
		$first = OPENBB_Orders::get( $this->order_id );
		$this->assertSame( 500, $first->aid_approved );
		// Goods 1000 - 500 aid, plus the uncovered membership fee.
		$this->assertSame( 800, $first->total );
		$this->assertSame( 1500, OPENBB_Orders::get( $second )->aid_approved );
		$this->assertSame( 1500, OPENBB_Orders::get( $second )->total );

		// Rerunning after more donations raises everyone to the new common percentage, capped at 100%.
		$wpdb->update( $wpdb->openbb_orders, array( 'donation' => 10000 ), array( 'id' => $donor ) );
		$this->assertSame( 200, $this->api( 'POST', "/cycles/{$this->cycle_id}/aid/distribute", array( 'confirmed' => true ) )->get_status() );
		$this->assertSame( 1000, OPENBB_Orders::get( $this->order_id )->aid_approved );
		$this->assertSame( 300, OPENBB_Orders::get( $this->order_id )->total );
		$this->assertSame( 3000, OPENBB_Orders::get( $second )->aid_approved );
		$this->assertSame( 0, OPENBB_Orders::get( $second )->total );

		wp_set_current_user( $this->make_user( 'member' ) );
		$this->assertSame( 403, $this->api( 'POST', "/cycles/{$this->cycle_id}/aid/distribute", array( 'confirmed' => true ) )->get_status() );
		wp_set_current_user( $this->reviewer );
		$wpdb->update( $wpdb->openbb_cycles, array( 'status' => 'complete' ), array( 'id' => $this->cycle_id ) );
		$this->assertSame( 409, $this->api( 'POST', "/cycles/{$this->cycle_id}/aid/distribute", array( 'confirmed' => true ) )->get_status() );
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
