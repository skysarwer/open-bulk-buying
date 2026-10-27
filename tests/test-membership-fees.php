<?php
/**
 * Membership charges, settlement, recurrence and stock isolation.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Membership_Fees extends OPENBB_TestCase {
	private $staff;
	private $member;
	private $product;

	public function set_up(): void {
		parent::set_up();
		$this->staff  = $this->make_user( 'coordinator' );
		$this->member = $this->make_user( 'member' );
		wp_set_current_user( $this->staff );
		update_option( OPENBB_Settings::GENERAL_OPTION, array( 'membership_fee_amount' => 1000 ) );
		$id = self::factory()->post->create( array( 'post_type' => OPENBB_PRODUCT_POST_TYPE, 'post_status' => 'publish' ) );
		update_post_meta( $id, OPENBB_META_PREFIX . 'active', true );
		update_post_meta( $id, OPENBB_META_PREFIX . 'price', 250 );
		$this->product = OPENBB_Product::get( $id );
		$this->assertInstanceOf( OPENBB_Stock_Move::class, OPENBB_Inventory::record_manual_move( $this->product, 50, 'receipt' ) );
	}

	private function order( string $status = 'submitted' ): OPENBB_Order {
		$cycle = OPENBB_Cycles::get( $this->make_cycle( 'distribution' ) );
		$order = OPENBB_Order_Editor::start( $cycle, $this->member );
		$result = OPENBB_Order_Editor::apply( $order, array(
			'status' => $status,
			'lines'  => array( array( 'product_id' => $this->product->id, 'qty' => 2 ) ),
		), $this->staff );
		$this->assertInstanceOf( OPENBB_Order::class, $result );
		return $result;
	}

	public function test_fee_is_a_server_managed_line_and_never_reserves_or_moves_stock(): void {
		global $wpdb;
		$order = $this->order();
		$this->assertSame( 1000, $order->membership_fee() );
		$this->assertSame( 1500, $order->total );
		$this->assertSame( 2.0, OPENBB_Inventory::get_reserved( $this->product->id ) );
		$this->assertArrayNotHasKey( 0, OPENBB_Inventory::get_order_reservations( $order ) );
		$paid = OPENBB_Order_Lifecycle::confirm( $order->id, 'fulfill', $this->staff );
		$this->assertInstanceOf( OPENBB_Order::class, $paid );
		$this->assertSame( 1500, $paid->paid_amount );
		$this->assertSame( 48.0, OPENBB_Inventory::get_on_hand( $this->product->id ) );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_stock_moves} WHERE product_id = 0" ) );
		$this->assertSame( 'paid', OPENBB_Membership_Fees::summary( $this->member )['status'] );
		$this->assertSame( 0, $this->order()->membership_fee() );
	}

	public function test_staff_can_empty_and_refill_a_draft_reusing_its_fee_line(): void {
		$order = $this->order( 'draft' );
		$fee = array_values( array_filter( $order->get_lines(), static function ( $line ) { return $line->is_membership_fee(); } ) )[0];
		$empty = OPENBB_Order_Editor::apply( $order, array( 'lines' => array() ), $this->staff );
		$this->assertInstanceOf( OPENBB_Order::class, $empty );
		$this->assertSame( 0, $empty->total );

		$refilled = OPENBB_Order_Editor::apply( $empty, array(
			'lines' => array( array( 'product_id' => $this->product->id, 'qty' => 1 ) ),
			'expected_revision' => $empty->revision,
		), $this->staff );
		$this->assertInstanceOf( OPENBB_Order::class, $refilled );
		$this->assertSame( $order->id, $refilled->id );
		$this->assertSame( 1250, $refilled->total );
		$fees = array_values( array_filter( $refilled->get_lines(), static function ( $line ) { return $line->is_membership_fee(); } ) );
		$this->assertCount( 1, $fees );
		$this->assertSame( $fee->id, $fees[0]->id );
		$this->assertSame( $order->id, $fees[0]->order_id );
		$this->assertSame( 1.0, $fees[0]->qty_requested );
		$this->assertSame( 0.0, OPENBB_Inventory::get_reserved( $this->product->id ) );
	}

	public function test_claim_is_submitted_not_paid_and_suppresses_duplicate_charge(): void {
		$claim = OPENBB_Membership_Fees::claim( $this->member, 'Transfer ABC' );
		$this->assertInstanceOf( OPENBB_Order::class, $claim );
		$this->assertSame( 0, $claim->cycle_id );
		$this->assertSame( 'submitted', $claim->status );
		$this->assertNull( $claim->paid_at );
		$this->assertSame( 'pending', OPENBB_Membership_Fees::summary( $this->member )['status'] );
		$this->assertSame( $claim->id, OPENBB_Membership_Fees::claim( $this->member )->id );
		$this->assertSame( 0, $this->order()->membership_fee() );
	}

	public function test_standalone_confirmation_is_paid_without_physical_collection_and_is_idempotent(): void {
		global $wpdb;
		$applicant = $this->make_user( 'applicant' );
		$claim = OPENBB_Membership_Fees::claim( $applicant );
		$paid = OPENBB_Membership_Fees::review( $applicant, $claim->id, $this->staff, true );
		$this->assertInstanceOf( OPENBB_Order::class, $paid );
		$this->assertSame( 'active', OPENBB_Access::get_status( $applicant ) );
		$this->assertSame( 'fulfilled', $paid->status );
		$this->assertSame( 1000, $paid->paid_amount );
		$this->assertSame( $this->staff, $paid->paid_by );
		$this->assertNull( $paid->collected_at );
		$this->assertNull( $paid->collected_by );
		$this->assertSame( $paid->revision, OPENBB_Membership_Fees::review( $applicant, $claim->id, $this->staff, true )->revision );
		$types = $wpdb->get_col( $wpdb->prepare( "SELECT event_type FROM {$wpdb->openbb_order_events} WHERE order_id = %d", $claim->id ) );
		$this->assertContains( 'membership_paid', $types );
		$this->assertContains( 'membership_approved', $types );
	}

	public function test_payment_confirmation_does_not_require_membership_approval(): void {
		$applicant = $this->make_user( 'applicant' );
		$claim = OPENBB_Membership_Fees::claim( $applicant );
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Membership_Fees::review( $applicant, $claim->id, $this->staff ) );
		$this->assertSame( 'applicant', OPENBB_Access::get_status( $applicant ) );
	}

	public function test_rejected_claim_retains_history_and_fee_becomes_due(): void {
		$claim = OPENBB_Membership_Fees::claim( $this->member );
		$rejected = OPENBB_Membership_Fees::review( $this->member, $claim->id, $this->staff, false, true );
		$this->assertInstanceOf( OPENBB_Order::class, $rejected );
		$this->assertSame( 'cancelled', $rejected->status );
		$this->assertNull( $rejected->paid_at );
		$this->assertSame( 1000, OPENBB_Membership_Fees::due_amount( $this->member ) );
		$this->assertCount( 1, OPENBB_Membership_Fees::summary( $this->member )['history'] );
		$this->assertSame( 1000, $this->order()->membership_fee() );
	}

	public function test_separate_recording_reconciles_unpaid_cycle_charge_and_keeps_paid_orders(): void {
		$order = $this->order();
		$paid = OPENBB_Membership_Fees::record( $this->member, $this->staff, 'Cash' );
		$this->assertInstanceOf( OPENBB_Order::class, $paid );
		$updated = OPENBB_Orders::get( $order->id );
		$this->assertSame( 0, $updated->membership_fee() );
		$this->assertSame( 500, $updated->total );
		$this->assertGreaterThan( $order->revision, $updated->revision );
		$this->assertSame( 'Cash', $paid->member_note );
		$this->assertWPError( OPENBB_Membership_Fees::record( $this->member, $this->staff ) );
	}

	public function test_recurring_counts_prepaid_orders_but_not_unpaid_or_membership_only_orders(): void {
		update_option( OPENBB_Settings::GENERAL_OPTION, array(
			'membership_fee_amount' => 1000, 'membership_fee_type' => 'recurring', 'membership_fee_order_interval' => 2,
		) );
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Membership_Fees::record( $this->member, $this->staff ) );
		$first = $this->order();
		$this->assertSame( 0, $first->membership_fee() );
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Order_Lifecycle::confirm( $first->id, 'prepay', $this->staff ) );
		$this->assertSame( 0, OPENBB_Membership_Fees::due_amount( $this->member ) );
		$second = $this->order();
		$this->assertSame( 0, $second->membership_fee() );
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Order_Lifecycle::confirm( $second->id, 'prepay', $this->staff ) );
		$this->assertSame( 1000, OPENBB_Membership_Fees::due_amount( $this->member ) );
		$this->assertSame( 1000, $this->order()->membership_fee() );
	}

	public function test_only_one_submitted_cycle_order_carries_the_outstanding_fee(): void {
		$first = $this->order();
		$second = $this->order();
		$this->assertSame( 1000, $first->membership_fee() );
		$this->assertSame( 0, $second->membership_fee() );
		$this->assertSame( 'due', OPENBB_Membership_Fees::summary( $this->member )['status'] );
	}

	public function test_members_cannot_record_payment_and_cannot_modify_fee_quantity(): void {
		wp_set_current_user( $this->member );
		$this->assertWPError( OPENBB_Membership_Fees::record( $this->member, $this->member ) );
		wp_set_current_user( $this->staff );
		$order = $this->order();
		$fee = array_values( array_filter( $order->get_lines(), static function ( $line ) { return $line->is_membership_fee(); } ) )[0];
		$this->assertWPError( OPENBB_Order_Editor::apply( $order, array(
			'lines' => array( array( 'id' => $fee->id, 'qty' => 0 ) ), 'reason' => 'Remove fee',
		), $this->staff ) );
	}

	public function test_aid_does_not_discount_membership_fee_and_price_snapshot_survives_settings_changes(): void {
		$order = $this->order();
		update_option( OPENBB_Settings::GENERAL_OPTION, array( 'membership_fee_amount' => 2000 ) );
		$order->aid_approved = 10000;
		$this->assertTrue( $order->save() );
		$this->assertSame( 1000, $order->membership_fee() );
		$this->assertSame( 1000, $order->total );
	}

	public function test_order_form_state_keeps_fee_out_of_editable_product_lines(): void {
		$order = $this->order();
		$state = OPENBB_Member_Orders::state( $order, OPENBB_Cycles::get( $order->cycle_id ) );
		$this->assertCount( 1, $state['lines'] );
		$this->assertSame( $this->product->id, $state['lines'][0]['product_id'] );
		$this->assertSame( 1000, $state['membership_fee'] );
		$this->assertFalse( $state['membership_pending'] );
	}

	public function test_claim_snapshot_is_used_when_settings_change_before_confirmation(): void {
		$claim = OPENBB_Membership_Fees::claim( $this->member );
		update_option( OPENBB_Settings::GENERAL_OPTION, array( 'membership_fee_amount' => 2000 ) );
		$this->assertSame( 1000, OPENBB_Membership_Fees::summary( $this->member )['amount'] );
		$paid = OPENBB_Membership_Fees::review( $this->member, $claim->id, $this->staff );
		$this->assertSame( 1000, $paid->paid_amount );
	}

	public function test_recording_separately_preserves_submitted_charge_snapshot(): void {
		$this->order();
		update_option( OPENBB_Settings::GENERAL_OPTION, array( 'membership_fee_amount' => 2000 ) );
		$this->assertSame( 1000, OPENBB_Membership_Fees::summary( $this->member )['amount'] );
		$paid = OPENBB_Membership_Fees::record( $this->member, $this->staff );
		$this->assertSame( 1000, $paid->paid_amount );
	}

	public function test_duplicate_draft_fee_cannot_be_settled_after_another_order_is_paid(): void {
		$draft = $this->order( 'draft' );
		$submitted = $this->order();
		$this->assertSame( 1000, $draft->membership_fee() );
		$this->assertSame( 1000, $submitted->membership_fee() );
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Order_Lifecycle::confirm( $submitted->id, 'prepay', $this->staff ) );
		$updated = OPENBB_Order_Editor::apply( $draft, array( 'status' => 'submitted' ), $this->staff );
		$this->assertInstanceOf( OPENBB_Order::class, $updated );
		$this->assertSame( 0, $updated->membership_fee() );
		$this->assertSame( 500, $updated->total );
	}

	public function test_membership_order_allows_notes_but_not_manual_status_changes(): void {
		$paid = OPENBB_Membership_Fees::record( $this->member, $this->staff );
		$updated = OPENBB_Order_Editor::apply( $paid, array( 'staff_note' => 'Receipt filed.' ), $this->staff );
		$this->assertInstanceOf( OPENBB_Order::class, $updated );
		$this->assertSame( 'Receipt filed.', $updated->staff_note );
		$this->assertWPError( OPENBB_Order_Editor::apply( $updated, array( 'status' => 'cancelled', 'reason' => 'Undo' ), $this->staff ) );
		$this->assertSame( 'fulfilled', OPENBB_Orders::get( $paid->id )->status );
	}

	public function test_combined_approval_rolls_back_payment_and_membership_on_audit_failure(): void {
		global $wpdb;
		$applicant = $this->make_user( 'applicant' );
		$claim = OPENBB_Membership_Fees::claim( $applicant );
		$fail = static function ( $sql ) use ( $wpdb ) {
			return false !== strpos( $sql, 'membership_approved' ) && false !== strpos( $sql, $wpdb->openbb_order_events ) ? 'SELECT * FROM openbb_deliberately_missing_table' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		$result = OPENBB_Membership_Fees::review( $applicant, $claim->id, $this->staff, true );
		remove_filter( 'query', $fail );
		$wpdb->suppress_errors( $previous );
		$this->assertWPError( $result );
		$this->assertSame( 'applicant', OPENBB_Access::get_status( $applicant ) );
		$this->assertSame( 'submitted', OPENBB_Orders::get( $claim->id )->status );
		$this->assertNull( OPENBB_Orders::get( $claim->id )->paid_at );
	}
}
