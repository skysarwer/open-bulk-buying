<?php
/**
 * Preparation cleanup boundaries, audited reopening, and cancelled member views.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Order_Preparation extends OPENBB_TestCase {

	private $actor;
	private $member;
	private $cycle;
	private $product;

	public function set_up(): void {
		parent::set_up();
		$this->actor = $this->make_user( 'coordinator' );
		$this->member = $this->make_user( 'member' );
		wp_set_current_user( $this->actor );
		rest_get_server();
		$this->cycle = new OPENBB_Cycle(
			array(
				'name'      => 'Preparation cycle',
				'opens_at'  => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'closes_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertTrue( OPENBB_Cycles::store( $this->cycle, '', $this->actor ) );
		$id = self::factory()->post->create( array( 'post_type' => OPENBB_PRODUCT_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Preparation oats' ) );
		update_post_meta( $id, OPENBB_META_PREFIX . 'active', true );
		update_post_meta( $id, OPENBB_META_PREFIX . 'price', 250 );
		$this->product = OPENBB_Product::get( $id );
		$this->assertInstanceOf( OPENBB_Stock_Move::class, OPENBB_Inventory::record_manual_move( $this->product, 10, 'receipt' ) );
	}

	private function stage( string $status ): void {
		$this->cycle->status = $status;
		$this->assertTrue( OPENBB_Cycles::store( $this->cycle, '', $this->actor ) );
		$this->cycle = OPENBB_Cycles::get( $this->cycle->id );
	}

	private function order( string $status = 'submitted' ): OPENBB_Order {
		$order = OPENBB_Order_Editor::start( $this->cycle, $this->member );
		$this->assertInstanceOf( OPENBB_Order::class, $order );
		$saved = OPENBB_Order_Editor::apply(
			$order,
			array( 'status' => $status, 'lines' => array( array( 'product_id' => $this->product->id, 'qty' => 2 ) ) ),
			$this->actor
		);
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		return $saved;
	}

	private function cancel( OPENBB_Order $order ): OPENBB_Order {
		$saved = OPENBB_Order_Editor::apply( $order, array( 'status' => 'cancelled', 'reason' => 'Cancelled for correction' ), $this->actor );
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		return $saved;
	}

	private function api( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/openbb/v1' . $route );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	private function reopen( OPENBB_Order $order, array $params = array() ): WP_REST_Response {
		return $this->api(
			'POST',
			"/orders/{$order->id}/reopen",
			array_merge( array( 'confirmed' => true, 'reason' => 'Accidental cancellation', 'expected_revision' => $order->revision ), $params )
		);
	}

	public function test_setup_submission_reserves_and_deletion_releases_stock_and_dependents(): void {
		global $wpdb;
		$order = $this->order();
		$this->assertNull( OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at );
		$this->assertEquals( 2, OPENBB_Inventory::get_reserved( $this->product->id ) );
		wp_set_current_user( $this->make_user( 'manager' ) );
		$item = $this->api( 'GET', "/orders/{$order->id}" )->get_data();
		$this->assertTrue( $item['can_delete'] );
		$this->assertTrue( $item['preparation_cleanup'] );
		$this->assertSame( 200, $this->api( 'DELETE', "/orders/{$order->id}" )->get_status() );
		$this->assertNull( OPENBB_Orders::get( $order->id ) );
		$this->assertSame( array(), OPENBB_Orders::get_lines( $order->id ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_order_events} WHERE order_id = %d", $order->id ) ) );
		$this->assertEquals( 0, OPENBB_Inventory::get_reserved( $this->product->id ) );
		$this->assertEquals( 10, OPENBB_Inventory::get_on_hand( $this->product->id ) );
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Order_Editor::start( $this->cycle, $this->member ) );
	}

	public function test_cancelled_preparation_orders_can_be_deleted(): void {
		$order = $this->cancel( $this->order() );
		$this->assertTrue( OPENBB_Order_Policy::can_delete( $order ) );
		$this->assertTrue( $order->delete() );
	}

	public function test_stage_changes_drafts_and_note_edits_do_not_lock_cleanup(): void {
		$order = $this->order( 'draft' );
		$this->stage( 'ordering' );
		$this->assertNull( $this->cycle->cleanup_locked_at );
		$order = OPENBB_Order_Editor::apply( $order, array( 'donation' => 50 ), $this->actor );
		$this->assertInstanceOf( OPENBB_Order::class, $order );
		$this->assertNull( OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at );
		$this->stage( 'setup' );
		$order = OPENBB_Order_Editor::apply( $order, array( 'status' => 'submitted' ), $this->actor );
		$this->assertInstanceOf( OPENBB_Order::class, $order );
		$this->stage( 'ordering' );
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Order_Editor::apply( $order, array( 'staff_note' => 'Only a note' ), $this->actor ) );
		$this->assertNull( OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at );
		$this->stage( 'setup' );
		$this->assertTrue( OPENBB_Order_Policy::can_delete( $order ) );
	}

	public static function live_stages(): array {
		return array( array( 'ordering' ), array( 'processing' ), array( 'distribution' ) );
	}

	/** @dataProvider live_stages */
	public function test_staff_submission_outside_setup_permanently_locks_cleanup( string $stage ): void {
		$this->stage( $stage );
		$order = $this->order();
		$locked = OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at;
		$this->assertNotNull( $locked );
		// $this->cycle predates the lock; even an unchecked stale save cannot clear it.
		$this->cycle->cleanup_locked_at = null;
		$this->assertTrue( $this->cycle->save() );
		$this->stage( 'setup' );
		$this->assertSame( $locked, $this->cycle->cleanup_locked_at );
		$order = $this->cancel( $order );
		$this->assertFalse( OPENBB_Order_Policy::can_delete( $order ) );
		$this->assertWPError( $order->delete() );
		$this->assertSame( 200, $this->reopen( $order )->get_status() );
		$this->assertFalse( OPENBB_Order_Policy::can_delete( OPENBB_Orders::get( $order->id ) ) );
	}

	public function test_member_submission_locks_but_member_draft_does_not(): void {
		$this->stage( 'ordering' );
		wp_set_current_user( $this->member );
		$input = array( 'lines' => array( array( 'product_id' => $this->product->id, 'qty' => 2 ) ) );
		$draft = OPENBB_Member_Orders::save( $this->cycle, $this->member, $input );
		$this->assertInstanceOf( OPENBB_Order::class, $draft );
		$this->assertNull( OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at );
		$input['submit'] = true;
		$input['commit'] = true;
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Member_Orders::save( $this->cycle, $this->member, $input ) );
		$this->assertNotNull( OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at );
	}

	public function test_live_update_to_setup_submitted_order_locks_cleanup(): void {
		$order = $this->order();
		$this->stage( 'processing' );
		$saved = OPENBB_Order_Editor::apply( $order, array( 'donation' => 25, 'reason' => 'Updated commitment' ), $this->actor );
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		$this->assertNotNull( OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at );
	}

	public function test_live_cancellation_of_setup_submission_also_locks_cleanup(): void {
		$order = $this->order();
		$this->stage( 'ordering' );
		$order = $this->cancel( $order );
		$this->stage( 'setup' );
		$this->assertNotNull( $this->cycle->cleanup_locked_at );
		$this->assertWPError( $order->delete() );
	}

	public function test_failed_commitment_rolls_back_order_and_cleanup_lock(): void {
		global $wpdb;
		$order = $this->order( 'draft' );
		$this->stage( 'ordering' );
		$fail = static function ( $query ) use ( $wpdb ) {
			return 0 === strpos( $query, "INSERT INTO `{$wpdb->openbb_order_events}`" ) ? 'INVALID OPENBB EVENT WRITE' : $query;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$this->assertWPError( OPENBB_Order_Editor::apply( $order, array( 'status' => 'submitted' ), $this->actor ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertNull( OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at );
		$this->assertSame( 'draft', OPENBB_Orders::get( $order->id )->status );
		$this->assertEquals( 0, OPENBB_Inventory::get_reserved( $this->product->id ) );
	}

	public function test_reopening_preserves_history_requires_confirmation_and_rechecks_submission(): void {
		global $wpdb;
		$order = $this->cancel( $this->order() );
		$this->stage( 'ordering' );
		$this->assertSame( 400, $this->reopen( $order, array( 'confirmed' => false ) )->get_status() );
		$this->assertSame( 400, $this->reopen( $order, array( 'reason' => ' ' ) )->get_status() );
		$this->assertSame( 409, $this->reopen( $order, array( 'expected_revision' => $order->revision - 1 ) )->get_status() );
		$this->assertInstanceOf( OPENBB_Stock_Move::class, OPENBB_Inventory::record_manual_move( $this->product, -9, 'spoilage' ) );
		$this->assertSame( 200, $this->reopen( $order )->get_status(), 'Reopening reserves no stock and must work even when items are unavailable.' );
		$draft = OPENBB_Orders::get( $order->id );
		$this->assertSame( 'draft', $draft->status );
		$this->assertSame( $order->submitted_at, $draft->submitted_at );
		$this->assertSame( $order->get_lines()[0]->id, $draft->get_lines()[0]->id );
		$this->assertStringContainsString( 'Accidental cancellation', $draft->staff_note );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_order_events} WHERE order_id = %d AND event_type = 'reopened'", $order->id ) ) );
		$this->assertEquals( 0, OPENBB_Inventory::get_reserved( $this->product->id ) );
		$changes = array( 'status' => 'submitted', 'reason' => 'Resubmitting' );
		$failed = OPENBB_Order_Editor::apply( $draft, $changes, $this->actor );
		$this->assertWPError( $failed );
		$this->assertSame( 'openbb_insufficient_stock', $failed->get_error_code() );
		$this->assertNull( OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at );
		$this->assertInstanceOf( OPENBB_Stock_Move::class, OPENBB_Inventory::record_manual_move( $this->product, 9, 'receipt' ) );
		update_post_meta( $this->product->id, OPENBB_META_PREFIX . 'active', false );
		$this->assertWPError( OPENBB_Order_Editor::apply( $draft, $changes, $this->actor ) );
		update_post_meta( $this->product->id, OPENBB_META_PREFIX . 'active', true );
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Order_Editor::apply( $draft, $changes, $this->actor ) );
		$this->assertEquals( 2, OPENBB_Inventory::get_reserved( $this->product->id ) );
		$this->assertNotNull( OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at );
	}

	public function test_reopened_draft_edits_do_not_hard_delete_submitted_lines(): void {
		$order = $this->cancel( $this->order() );
		$this->assertSame( 200, $this->reopen( $order )->get_status() );
		$draft = OPENBB_Orders::get( $order->id );
		$saved = OPENBB_Order_Editor::apply( $draft, array( 'lines' => array() ), $this->actor );
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		$this->assertCount( 1, $saved->get_lines() );
		$this->assertSame( 0.0, $saved->get_lines()[0]->qty_requested );
	}

	public function test_reopening_preserves_membership_fee_lines_and_resubmission_accepts_them(): void {
		$settings = OPENBB_Settings::get_general();
		$settings['membership_fee_amount'] = 500;
		update_option( OPENBB_Settings::GENERAL_OPTION, $settings );
		$order = $this->cancel( $this->order() );
		$fees = array_values( array_filter( $order->get_lines(), static function ( OPENBB_Order_Line $line ): bool {
			return $line->is_membership_fee();
		} ) );
		$this->assertCount( 1, $fees );
		$this->assertSame( 200, $this->reopen( $order )->get_status() );
		$draft = OPENBB_Orders::get( $order->id );
		$reopened_fees = array_values( array_filter( $draft->get_lines(), static function ( OPENBB_Order_Line $line ): bool {
			return $line->is_membership_fee();
		} ) );
		$this->assertSame( $fees[0]->id, $reopened_fees[0]->id );
		$this->assertSame( $fees[0]->qty_requested, $reopened_fees[0]->qty_requested );
		$this->stage( 'ordering' );
		$saved = OPENBB_Order_Editor::apply( $draft, array( 'status' => 'submitted', 'reason' => 'Resubmitting with fee' ), $this->actor );
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		$this->assertEquals( 2, OPENBB_Inventory::get_reserved( $this->product->id ) );
	}

	public function test_reopening_permissions_cycle_and_operational_guards(): void {
		global $wpdb;
		$order = $this->cancel( $this->order() );
		wp_set_current_user( $this->member );
		$this->assertSame( 403, $this->reopen( $order )->get_status() );
		$this->assertSame( 403, $this->api( 'DELETE', "/orders/{$order->id}" )->get_status() );
		wp_set_current_user( $this->actor );
		$this->assertSame( 409, $this->api( 'PUT', "/orders/{$order->id}", array( 'status' => 'draft', 'reason' => 'Bypass' ) )->get_status() );
		foreach ( array( 'paid_at' => '2026-01-01 00:00:00', 'paid_amount' => 0, 'collected_at' => '2026-01-01 00:00:00', 'aid_approved' => 25 ) as $field => $value ) {
			$this->assertNotFalse( $wpdb->update( $wpdb->openbb_orders, array( $field => $value ), array( 'id' => $order->id ) ) );
			$this->assertSame( 409, $this->reopen( $order )->get_status() );
			$this->assertWPError( $order->delete() );
			$this->assertNotFalse( $wpdb->update( $wpdb->openbb_orders, array( $field => 'aid_approved' === $field ? 0 : null ), array( 'id' => $order->id ) ) );
		}
		$this->stage( 'complete' );
		$this->assertSame( 409, $this->reopen( $order )->get_status() );
	}

	public function test_stock_history_blocks_cleanup_and_reopening_even_in_setup(): void {
		$order = $this->cancel( $this->order() );
		$move = new OPENBB_Stock_Move( array( 'product_id' => $this->product->id, 'quantity' => -1, 'reason' => 'distribution', 'order_id' => $order->id, 'cycle_id' => $this->cycle->id ) );
		$this->assertTrue( $move->save() );
		$item = $this->api( 'GET', "/orders/{$order->id}" )->get_data();
		$this->assertFalse( $item['can_delete'] );
		$this->assertFalse( $item['can_reopen'] );
		$this->assertWPError( $order->delete() );
		$this->assertSame( 403, $this->api( 'DELETE', "/orders/{$order->id}" )->get_status() );
		$this->assertSame( 409, $this->reopen( $order )->get_status() );
	}

	public function test_migration_protects_ambiguous_history_without_locking_new_or_draft_only_cycles(): void {
		global $wpdb;
		$order = $this->order();
		$empty = new OPENBB_Cycle( array( 'name' => 'Empty cycle' ) );
		$this->assertTrue( $empty->save() );
		$draft_cycle = new OPENBB_Cycle( array( 'name' => 'Draft-only cycle' ) );
		$this->assertTrue( $draft_cycle->save() );
		$draft = new OPENBB_Order( array( 'cycle_id' => $draft_cycle->id, 'user_id' => $this->member ) );
		$this->assertTrue( $draft->save() );
		$this->assertTrue( OPENBB_Installer::protect_existing_cycles() );
		$locked = OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at;
		$this->assertNotNull( $locked );
		$this->assertNull( OPENBB_Cycles::get( $empty->id )->cleanup_locked_at );
		$this->assertNull( OPENBB_Cycles::get( $draft_cycle->id )->cleanup_locked_at );
		$this->assertWPError( $order->delete() );
		$this->assertTrue( OPENBB_Installer::protect_existing_cycles() );
		$this->assertSame( $locked, OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at );
		// A historical order reset to draft still has submission evidence in events.
		$this->assertNotFalse( $wpdb->update( $wpdb->openbb_cycles, array( 'cleanup_locked_at' => null ), array( 'id' => $this->cycle->id ) ) );
		$this->assertNotFalse( $wpdb->update( $wpdb->openbb_orders, array( 'status' => 'draft', 'submitted_at' => null ), array( 'id' => $order->id ) ) );
		$this->assertTrue( OPENBB_Installer::protect_existing_cycles() );
		$this->assertNotNull( OPENBB_Cycles::get( $this->cycle->id )->cleanup_locked_at );
		$this->assertWPError( OPENBB_Orders::get( $order->id )->delete() );
	}

	public function test_preparation_cleanup_fails_closed_until_migration_completes(): void {
		$order = $this->order();
		update_option( OPENBB_Installer::DB_VERSION_OPTION, '2' );
		$this->assertFalse( OPENBB_Cycles::get( $this->cycle->id )->allows_preparation_cleanup() );
		$this->assertWPError( $order->delete() );
		update_option( OPENBB_Installer::DB_VERSION_OPTION, OPENBB_Installer::DB_VERSION );
		$this->assertTrue( OPENBB_Order_Policy::can_delete( $order ) );
	}

	public function test_cancelled_frontend_is_a_read_only_summary_and_becomes_editable_after_staff_reopening(): void {
		$order = $this->cancel( $this->order() );
		$this->stage( 'ordering' );
		wp_set_current_user( $this->member );
		$html = OPENBB_Order_Form_View::render();
		$this->assertStringContainsString( 'Your order was cancelled', $html );
		$this->assertStringContainsString( 'Preparation oats', $html );
		$this->assertStringContainsString( 'not due', $html );
		$this->assertStringNotContainsString( 'Finalize order', $html );
		$this->assertStringNotContainsString( '<dialog', $html );
		$this->assertStringNotContainsString( 'data-wp-interactive="openbb/order"', $html );
		$failed = OPENBB_Member_Orders::save( $this->cycle, $this->member, array() );
		$this->assertWPError( $failed );
		$this->assertStringContainsString( 'cancelled', $failed->get_error_message() );
		wp_set_current_user( $this->actor );
		$this->assertSame( 200, $this->reopen( $order )->get_status() );
		wp_set_current_user( $this->member );
		$this->assertTrue( OPENBB_Member_Orders::can_edit( OPENBB_Orders::get( $order->id ), $this->cycle ) );
		$this->assertStringContainsString( 'data-wp-interactive="openbb/order"', OPENBB_Order_Form_View::render() );
	}
}
