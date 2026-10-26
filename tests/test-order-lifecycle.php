<?php
/**
 * Payment, fulfillment, abandonment and retention invariants.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Order_Lifecycle extends OPENBB_TestCase {

	private $actor;
	private $product;
	private $cycle;

	public function set_up(): void {
		parent::set_up();
		$this->actor = $this->make_user( 'coordinator' );
		wp_set_current_user( $this->actor );
		$product_id = self::factory()->post->create( array( 'post_type' => OPENBB_PRODUCT_POST_TYPE, 'post_status' => 'publish' ) );
		update_post_meta( $product_id, OPENBB_META_PREFIX . 'active', true );
		update_post_meta( $product_id, OPENBB_META_PREFIX . 'price', 250 );
		$this->product = OPENBB_Product::get( $product_id );
		$this->assertInstanceOf( OPENBB_Stock_Move::class, OPENBB_Inventory::record_manual_move( $this->product, 10, 'receipt' ) );
		$this->cycle = new OPENBB_Cycle(
			array(
				'name'      => 'Distribution',
				'status'    => OPENBB_Cycle::STATUS_DISTRIBUTION,
				'opens_at'  => '2020-01-01 00:00:00',
				'closes_at' => '2020-01-02 00:00:00',
			)
		);
		$this->assertTrue( OPENBB_Cycles::store( $this->cycle, '', $this->actor ) );
	}

	private function order( float $quantity = 2 ): OPENBB_Order {
		$order = OPENBB_Order_Editor::start( $this->cycle, $this->make_user( 'member' ) );
		$this->assertInstanceOf( OPENBB_Order::class, $order );
		$order = OPENBB_Order_Editor::apply(
			$order,
			array(
				'status' => OPENBB_Order::STATUS_SUBMITTED,
				'lines'  => array( array( 'product_id' => $this->product->id, 'qty' => $quantity ) ),
			),
			$this->actor
		);
		$this->assertInstanceOf( OPENBB_Order::class, $order );
		return $order;
	}

	private function confirm( OPENBB_Order $order, string $action ) {
		return OPENBB_Order_Lifecycle::confirm( $order->id, $action, $this->actor, $order->updated_at, $order->revision );
	}

	private function assert_stock( float $on_hand, float $reserved ): void {
		$this->assertEquals( $on_hand, OPENBB_Inventory::get_on_hand( $this->product->id ) );
		$this->assertEquals( $reserved, OPENBB_Inventory::get_reserved( $this->product->id ) );
		$this->assertEquals( $reserved, OPENBB_Inventory::get_reserved_for( array( $this->product->id ) )[ $this->product->id ] );
		$this->assertEquals( $on_hand - $reserved, OPENBB_Inventory::get_available_for( array( $this->product->id ) )[ $this->product->id ] );
	}

	public function test_reservation_queries_match_php_policy_across_statuses_and_deadlines(): void {
		$order = $this->order();
		foreach ( array( 'submitted', 'prepaid', 'draft', 'fulfilled', 'cancelled' ) as $status ) {
			$order->status = $status;
			$this->assertTrue( $order->save() );
			foreach ( OPENBB_Cycle::status_labels() as $stage => $label ) {
				foreach ( array( null, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) as $deadline ) {
					$this->cycle->status = $stage;
					// Different deadlines catch accidentally reading the other payment state's hold.
					$this->cycle->unpaid_hold_until = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
					$this->cycle->prepaid_hold_until = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
					$this->cycle->{OPENBB_Cycle::hold_field( $status )} = $deadline;
					$this->assertTrue( $this->cycle->save() );
					$expected = OPENBB_Inventory::order_reserves( $order, $this->cycle ) ? 2.0 : 0.0;
					$this->assert_stock( 10, $expected );
				}
			}
			$order->status = 'submitted';
			$order->collected_at = current_time( 'mysql', true );
			$this->assertTrue( $order->save() );
			$this->cycle->status = 'distribution';
			$this->cycle->unpaid_hold_until = null;
			$this->assertTrue( $this->cycle->save() );
			$this->assertFalse( OPENBB_Inventory::order_reserves( $order, $this->cycle ) );
			$this->assert_stock( 10, 0 );
		}
	}

	public function test_prepayment_preserves_stock_and_reservations_and_is_retry_safe(): void {
		global $wpdb;
		$order = $this->order();
		$paid  = $this->confirm( $order, 'prepay' );
		$this->assertInstanceOf( OPENBB_Order::class, $paid );
		$this->assertSame( 'prepaid', $paid->status );
		$this->assertSame( 500, $paid->paid_amount );
		$this->assertSame( $order->currency, $paid->paid_currency );
		$this->assertSame( $this->actor, $paid->paid_by );
		$this->assertNotNull( $paid->paid_at );
		$this->assertNull( $paid->collected_at );
		$this->assertTrue( OPENBB_Inventory::order_reserves( $paid ) );
		$this->assert_stock( 10, 2 );
		$retry = $this->confirm( $order, 'prepay' );
		$this->assertSame( $paid->revision, $retry->revision );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_order_events} WHERE event_type = 'prepay'" ) );
	}

	public function test_fulfillment_settles_payment_and_deducts_stock_once(): void {
		global $wpdb;
		$order = $this->order();
		// Historical fractional purchases remain fulfillable, without enabling new ones.
		$line = $order->get_lines()[0];
		$line->qty_requested = 2.5;
		$this->assertTrue( $line->save() );
		$order = OPENBB_Orders::get( $order->id );
		$done  = $this->confirm( $order, 'fulfill' );
		$this->assertInstanceOf( OPENBB_Order::class, $done );
		$saved = OPENBB_Orders::get( $order->id );
		$this->assertSame( 'fulfilled', $saved->status );
		$this->assertSame( 625, $saved->paid_amount );
		$this->assertSame( $this->actor, $saved->collected_by );
		$this->assertNotNull( $saved->collected_at );
		$this->assertSame( 2.5, $saved->get_lines()[0]->qty_final );
		$this->assert_stock( 7.5, 0 );
		$this->assertInstanceOf( OPENBB_Order::class, $this->confirm( $order, 'fulfill' ) );
		$this->assert_stock( 7.5, 0 );
		$move = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->openbb_stock_moves} WHERE order_id = %d", $order->id ), ARRAY_A );
		$this->assertSame( 'distribution', $move['reason'] );
		$this->assertEquals( $this->cycle->id, $move['cycle_id'] );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_order_events} WHERE event_type = 'fulfill'" ) );
	}

	public function test_prepaid_fulfillment_retains_original_payment_confirmation(): void {
		$paid = $this->confirm( $this->order(), 'prepay' );
		$done = $this->confirm( $paid, 'fulfill' );
		$this->assertInstanceOf( OPENBB_Order::class, $done );
		$this->assertSame( $paid->paid_at, $done->paid_at );
		$this->assertSame( $paid->paid_by, $done->paid_by );
		$this->assert_stock( 8, 0 );
	}

	public function test_zero_balance_is_settled_and_fulfilled(): void {
		$order = $this->order();
		$order->aid_approved = $order->total;
		$this->assertTrue( $order->save() );
		$done = $this->confirm( $order, 'fulfill' );
		$this->assertInstanceOf( OPENBB_Order::class, $done );
		$this->assertSame( 0, $done->paid_amount );
		$this->assertNotNull( $done->paid_at );
		$this->assert_stock( 8, 0 );
	}

	public function test_handoff_uses_saved_prepared_quantity_and_base_conversion(): void {
		$order = $this->order();
		$line = $order->get_lines()[0];
		$line->qty_prepared = 1.5;
		$line->base_per_unit = 2;
		$this->assertTrue( $line->save() );
		$order = OPENBB_Orders::get( $order->id );
		$this->assert_stock( 10, 3 );
		$done = $this->confirm( $order, 'fulfill' );
		$this->assertInstanceOf( OPENBB_Order::class, $done );
		$this->assertSame( 375, $done->paid_amount );
		$this->assertSame( 1.5, OPENBB_Orders::get( $order->id )->get_lines()[0]->qty_final );
		$this->assert_stock( 7, 0 );
	}

	public function test_paid_order_edits_cancellation_and_status_bypasses_are_blocked(): void {
		$paid = $this->confirm( $this->order(), 'prepay' );
		foreach ( array(
			array( 'donation' => 100 ),
			array( 'status' => 'cancelled' ),
			array( 'status' => 'submitted' ),
			array( 'lines' => array() ),
		) as $changes ) {
			$changes['reason'] = 'Attempted adjustment';
			$this->assertWPError( OPENBB_Order_Editor::apply( $paid, $changes, $this->actor ) );
		}
		$this->assertSame( 'prepaid', OPENBB_Orders::get( $paid->id )->status );
		$this->assert_stock( 10, 2 );
		$other = $this->order();
		foreach ( array( 'prepaid', 'fulfilled', 'draft' ) as $status ) {
			$this->assertWPError( OPENBB_Order_Editor::apply( $other, array( 'status' => $status, 'reason' => 'Bypass' ), $this->actor ) );
		}
	}

	public function test_cancellation_releases_stock_but_preserves_order_and_lines(): void {
		$order     = $this->order();
		$cancelled = OPENBB_Order_Editor::apply( $order, array( 'status' => 'cancelled', 'reason' => 'Member withdrew' ), $this->actor );
		$this->assertInstanceOf( OPENBB_Order::class, $cancelled );
		$this->assert_stock( 10, 0 );
		$this->assertWPError( $cancelled->delete() );
		$this->assertWPError( $cancelled->get_lines()[0]->delete() );
		$this->assertFalse( $cancelled->is_abandoned() );
		$this->assertWPError( OPENBB_Order_Editor::apply( $cancelled, array( 'status' => 'submitted', 'reason' => 'Revive' ), $this->actor ) );
	}

	public function test_cycle_finalization_abandons_only_unfulfilled_committed_orders(): void {
		$unpaid    = $this->order();
		$paid      = $this->confirm( $this->order(), 'prepay' );
		$fulfilled = $this->confirm( $this->order(), 'fulfill' );
		$draft     = OPENBB_Order_Editor::start( $this->cycle, $this->make_user( 'member' ) );
		$this->assertTrue( $draft->save() );
		$this->assert_stock( 8, 4 );
		$this->cycle->status = OPENBB_Cycle::STATUS_COMPLETE;
		$this->assertTrue( OPENBB_Cycles::store( $this->cycle, 'distribution', $this->actor ) );
		$closed = OPENBB_Cycles::get( $this->cycle->id );
		$this->assertNotNull( $closed->finalized_at );
		$this->assertSame( $this->actor, $closed->finalized_by );
		$this->assertSame( $closed->finalized_at, $closed->unpaid_hold_until );
		$this->assertSame( $closed->finalized_at, $closed->prepaid_hold_until );
		$this->assertTrue( $unpaid->is_abandoned() );
		$this->assertTrue( $paid->is_abandoned() );
		$this->assertFalse( $fulfilled->is_abandoned() );
		$this->assertFalse( $draft->is_abandoned() );
		$this->assert_stock( 8, 0 );
		$this->assertWPError( $this->confirm( $unpaid, 'fulfill' ) );
		$this->assertWPError( $this->confirm( $unpaid, 'prepay' ) );
		$this->assertInstanceOf( OPENBB_Order::class, $this->confirm( $fulfilled, 'fulfill' ) );
		$closed->finalized_at = '2000-01-01 00:00:00';
		$this->assertTrue( OPENBB_Cycles::store( $closed, 'complete', $this->actor ) );
		$this->assertSame( $this->cycle->finalized_at, OPENBB_Cycles::get( $closed->id )->finalized_at );
		$closed->status = 'distribution';
		$this->assertWPError( OPENBB_Cycles::store( $closed, 'complete', $this->actor ) );
	}

	public function test_frozen_future_prepaid_hold_and_boundary_agree_with_inventory(): void {
		$order = $this->confirm( $this->order(), 'prepay' );
		$this->cycle->status = 'complete';
		$this->cycle->finalized_at = current_time( 'mysql', true );
		$this->cycle->unpaid_hold_until = $this->cycle->finalized_at;
		$this->cycle->prepaid_hold_until = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$this->assertTrue( $this->cycle->save() );
		$this->assertFalse( $order->is_abandoned() );
		$this->assertTrue( OPENBB_Inventory::order_reserves( $order ) );
		$this->assert_stock( 10, 2 );
		$deadline = strtotime( $this->cycle->prepaid_hold_until . ' UTC' );
		$this->assertFalse( $order->is_abandoned( $this->cycle, $deadline - 1 ) );
		$this->assertTrue( $order->is_abandoned( $this->cycle, $deadline ) );
		$this->cycle->prepaid_hold_until = gmdate( 'Y-m-d H:i:s', time() - 1 );
		$this->assertTrue( $this->cycle->save() );
		$this->assertTrue( $order->is_abandoned() );
		$this->assertFalse( OPENBB_Inventory::order_reserves( $order ) );
		$this->assert_stock( 10, 0 );
	}

	public function test_same_second_edit_rejects_stale_payment_confirmation(): void {
		$order = $this->order();
		$edited = OPENBB_Order_Editor::apply( $order, array( 'donation' => 25, 'reason' => 'Changed' ), $this->actor );
		$this->assertInstanceOf( OPENBB_Order::class, $edited );
		$this->assertGreaterThan( $order->revision, $edited->revision );
		$result = $this->confirm( $order, 'prepay' );
		$this->assertWPError( $result );
		$this->assertSame( 'openbb_order_changed', $result->get_error_code() );
		$this->assertNull( OPENBB_Orders::get( $order->id )->paid_at );
	}

	public function test_missing_currency_cannot_be_silently_assumed_for_payment(): void {
		$order = $this->order();
		$order->currency = '';
		$this->assertTrue( $order->save() );
		$result = $this->confirm( $order, 'prepay' );
		$this->assertWPError( $result );
		$this->assertSame( 'openbb_order_currency_missing', $result->get_error_code() );
		$this->assertNull( OPENBB_Orders::get( $order->id )->paid_at );
		$this->assert_stock( 10, 2 );
	}

	public function test_insufficient_stock_and_payment_mismatch_do_not_partially_fulfill(): void {
		$paid = $this->confirm( $this->order(), 'prepay' );
		$paid->paid_amount = 1;
		$this->assertTrue( $paid->save() );
		$this->assertSame( 'openbb_payment_mismatch', $this->confirm( $paid, 'fulfill' )->get_error_code() );
		$paid->paid_amount = $paid->total;
		$this->assertTrue( $paid->save() );
		$this->assertInstanceOf( OPENBB_Stock_Move::class, OPENBB_Inventory::record_manual_move( $this->product, -9, 'spoilage' ) );
		$this->assertSame( 'openbb_insufficient_stock', $this->confirm( $paid, 'fulfill' )->get_error_code() );
		$this->assertNull( OPENBB_Orders::get( $paid->id )->collected_at );
		$this->assert_stock( 1, 2 );
	}

	public function test_failure_to_write_event_rolls_back_payment_lines_and_stock(): void {
		global $wpdb;
		$order = $this->order();
		$fail = static function ( $query ) use ( $wpdb ) {
			return 0 === strpos( $query, "INSERT INTO `{$wpdb->openbb_order_events}`" ) ? 'INVALID OPENBB EVENT WRITE' : $query;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$result = $this->confirm( $order, 'fulfill' );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertWPError( $result );
		$this->assertSame( 'openbb_confirmation_failed', $result->get_error_code() );
		$saved = OPENBB_Orders::get( $order->id );
		$this->assertSame( 'submitted', $saved->status );
		$this->assertNull( $saved->paid_at );
		$this->assertNull( $saved->collected_at );
		$this->assertNull( $saved->get_lines()[0]->qty_final );
		$this->assert_stock( 10, 2 );
		$this->assertInstanceOf( OPENBB_Order::class, $this->confirm( $saved, 'fulfill' ) );
		$this->assert_stock( 8, 0 );
	}
}
