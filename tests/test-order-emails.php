<?php
/**
 * Transactional member email follows successful order service writes.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Order_Emails extends OPENBB_TestCase {

	private $mail = array();
	private $member;
	private $cycle;
	private $products = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		$this->member = $this->make_user( 'member' );
		$this->cycle = OPENBB_Cycles::get( $this->make_cycle( OPENBB_Cycle::STATUS_DISTRIBUTION ) );
		foreach ( array( 'Apples <script>alert(1)</script>', 'Unchanged carrots', 'Added beans' ) as $name ) {
			$id = self::factory()->post->create( array( 'post_type' => OPENBB_PRODUCT_POST_TYPE, 'post_status' => 'publish', 'post_title' => $name ) );
			update_post_meta( $id, OPENBB_META_PREFIX . 'active', true );
			update_post_meta( $id, OPENBB_META_PREFIX . 'price', 250 );
			update_post_meta( $id, OPENBB_META_PREFIX . 'container_charge', 50 );
			$product = OPENBB_Product::get( $id );
			$this->assertInstanceOf( OPENBB_Stock_Move::class, OPENBB_Inventory::record_manual_move( $product, 100, 'receipt' ) );
			$this->products[] = $product;
		}
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		parent::tear_down();
	}

	public function capture_mail( $result, array $mail ) {
		$this->mail[] = $mail;
		return true;
	}

	private function create_order( string $status = 'submitted', bool $by_member = false ): OPENBB_Order {
		$order = OPENBB_Order_Editor::start( $this->cycle, $this->member );
		$saved = OPENBB_Order_Editor::apply(
			$order,
			array(
				'status' => $status,
				'lines' => array(
					array( 'product_id' => $this->products[0]->id, 'qty' => 2 ),
					array( 'product_id' => $this->products[1]->id, 'qty' => 1 ),
				),
				'staff_note' => 'Private staff note',
				'aid_reason' => 'Private financial explanation',
			),
			$this->member,
			$by_member
		);
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		return $saved;
	}

	public function test_staff_creation_sends_member_itemized_html_without_private_content(): void {
		$order = $this->create_order();
		$this->assertCount( 1, $this->mail );
		$mail = $this->mail[0];
		$this->assertSame( get_userdata( $this->member )->user_email, $mail['to'] );
		$this->assertStringContainsString( 'Order created (#' . $order->id . ')', $mail['subject'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $mail['headers'] );
		$this->assertStringContainsString( '<table', $mail['message'] );
		$this->assertStringContainsString( 'Unchanged carrots', $mail['message'] );
		$this->assertStringContainsString( esc_html( openbb_format_money( 900, $order->currency ) ), $mail['message'] );
		$this->assertStringNotContainsString( '<img', $mail['message'] );
		$this->assertStringNotContainsString( '<script', $mail['message'] );
		$this->assertStringNotContainsString( 'Private', $mail['message'] );
	}

	public function test_member_submission_sends_once_but_drafts_and_unchanged_saves_are_silent(): void {
		$order = $this->create_order( 'draft', true );
		$this->assertCount( 0, $this->mail );
		$order = OPENBB_Order_Editor::apply( $order, array( 'donation' => 100 ), $this->member, true );
		$this->assertCount( 0, $this->mail );
		$order = OPENBB_Order_Editor::apply( $order, array( 'status' => 'submitted' ), $this->member, true );
		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'Order created', $this->mail[0]['subject'] );
		OPENBB_Order_Editor::apply( $order, array(), $this->member, true );
		OPENBB_Order_Editor::apply( $order, array( 'staff_note' => 'Internal only' ), $this->member );
		$this->assertCount( 1, $this->mail );
	}

	public function test_update_shows_changed_lines_and_totals_not_entire_order(): void {
		$order = $this->create_order();
		$lines = $order->get_lines();
		$saved = OPENBB_Order_Editor::apply(
			$order,
			array(
				'lines' => array(
					array( 'id' => $lines[0]->id, 'qty' => 3, 'own_container' => true ),
					array( 'id' => $lines[1]->id, 'qty' => 1 ),
					array( 'product_id' => $this->products[2]->id, 'qty' => 1 ),
				),
				'donation' => 100,
				'reason' => 'Private staff explanation',
			),
			$this->member
		);
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		$this->assertCount( 2, $this->mail );
		$mail = $this->mail[1];
		$this->assertStringContainsString( 'Order updated', $mail['subject'] );
		foreach ( array( 'Before', 'After', '2 x', '3 x', 'Added beans', 'Donation', 'Container credit', 'Total' ) as $text ) {
			$this->assertStringContainsString( $text, $mail['message'] );
		}
		$this->assertStringNotContainsString( 'Unchanged carrots', $mail['message'] );
		$this->assertStringNotContainsString( 'Private', $mail['message'] );
	}

	public function test_member_removal_and_note_only_update_are_diffs(): void {
		$order = $this->create_order( 'submitted', true );
		$line = $order->get_lines()[1];
		$order = OPENBB_Order_Editor::apply( $order, array( 'lines' => array( array( 'id' => $line->id, 'qty' => 1 ) ) ), $this->member, true );
		$this->assertInstanceOf( OPENBB_Order::class, $order );
		$this->assertStringContainsString( 'Apples', $this->mail[1]['message'] );
		$this->assertStringNotContainsString( 'Unchanged carrots', $this->mail[1]['message'] );
		OPENBB_Order_Editor::apply( $order, array( 'member_note' => '<b>Please call</b>' ), $this->member, true );
		$this->assertCount( 3, $this->mail );
		$this->assertStringContainsString( '&lt;b&gt;Please call&lt;/b&gt;', $this->mail[2]['message'] );
		$this->assertStringNotContainsString( 'Apples', $this->mail[2]['message'] );
	}

	public function test_cancellation_is_itemized_and_note_edits_are_silent(): void {
		$order = $this->create_order();
		$saved = OPENBB_Order_Editor::apply( $order, array( 'status' => 'cancelled', 'reason' => 'Cancelled by staff' ), $this->member );
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		$this->assertCount( 2, $this->mail );
		$this->assertStringContainsString( 'Order cancelled', $this->mail[1]['subject'] );
		$this->assertStringContainsString( 'Unchanged carrots', $this->mail[1]['message'] );
		OPENBB_Order_Editor::apply( $saved, array( 'staff_note' => 'More internal notes' ), $this->member );
		$this->assertCount( 2, $this->mail );
	}

	public function test_draft_cancellation_is_silent(): void {
		$order = $this->create_order( 'draft' );
		$saved = OPENBB_Order_Editor::apply( $order, array( 'status' => 'cancelled', 'reason' => 'Unused draft' ), $this->member );
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		$this->assertCount( 0, $this->mail );
	}

	public function test_prepayment_and_fulfillment_send_once_each_after_commit(): void {
		$order = $this->create_order();
		$paid = OPENBB_Order_Lifecycle::confirm( $order->id, 'prepay', $this->member );
		$this->assertInstanceOf( OPENBB_Order::class, $paid );
		OPENBB_Order_Lifecycle::confirm( $order->id, 'prepay', $this->member );
		$done = OPENBB_Order_Lifecycle::confirm( $order->id, 'fulfill', $this->member );
		$this->assertInstanceOf( OPENBB_Order::class, $done );
		OPENBB_Order_Lifecycle::confirm( $order->id, 'fulfill', $this->member );
		$this->assertCount( 3, $this->mail );
		$this->assertStringContainsString( 'marked as prepaid', $this->mail[1]['subject'] );
		$this->assertStringContainsString( 'Order fulfilled', $this->mail[2]['subject'] );
		foreach ( array( 1, 2 ) as $index ) {
			$this->assertStringContainsString( 'Unchanged carrots', $this->mail[ $index ]['message'] );
		}
	}

	public function test_rejected_and_rolled_back_writes_send_nothing(): void {
		global $wpdb;
		$order = $this->create_order();
		$this->assertWPError( OPENBB_Order_Editor::apply( $order, array( 'donation' => 100 ), $this->member ) );
		$this->assertWPError( OPENBB_Order_Editor::apply( $order, array( 'expected_revision' => $order->revision - 1, 'donation' => 100, 'reason' => 'Stale edit' ), $this->member ) );
		$fail = static function ( $query ) use ( $wpdb ) {
			return 0 === strpos( $query, "INSERT INTO `{$wpdb->openbb_order_events}`" ) ? 'INVALID OPENBB EMAIL TEST WRITE' : $query;
		};
		add_filter( 'query', $fail );
		$wpdb->suppress_errors( true );
		try {
			$this->assertWPError( OPENBB_Order_Editor::apply( $order, array( 'donation' => 100, 'reason' => 'Adjustment' ), $this->member ) );
			$this->assertWPError( OPENBB_Order_Lifecycle::confirm( $order->id, 'prepay', $this->member ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( false );
		}
		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'submitted', OPENBB_Orders::get( $order->id )->status );
	}

	public function test_mail_failure_is_reported_without_undoing_payment(): void {
		$order = $this->create_order();
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		add_filter( 'pre_wp_mail', '__return_false' );
		$log = wp_tempnam( 'openbb-order-email-log' );
		$previous = ini_set( 'error_log', $log );
		$failures = did_action( 'openbb_order_email_failed' );
		try {
			$saved = OPENBB_Order_Lifecycle::confirm( $order->id, 'prepay', $this->member );
			$this->assertInstanceOf( OPENBB_Order::class, $saved );
			$this->assertSame( 'prepaid', OPENBB_Orders::get( $order->id )->status );
			$this->assertSame( $failures + 1, did_action( 'openbb_order_email_failed' ) );
			$this->assertStringContainsString( 'email could not be sent', file_get_contents( $log ) );
		} finally {
			remove_filter( 'pre_wp_mail', '__return_false' );
			ini_set( 'error_log', $previous );
			unlink( $log );
		}
	}

	public function test_direct_fulfillment_uses_historical_prices_and_final_quantities(): void {
		$order = $this->create_order();
		$line = $order->get_lines()[0];
		$line->qty_prepared = 1.5;
		$this->assertTrue( $line->save() );
		update_post_meta( $this->products[0]->id, OPENBB_META_PREFIX . 'price', 9999 );
		$saved = OPENBB_Order_Lifecycle::confirm( $order->id, 'fulfill', $this->member );
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		$this->assertCount( 2, $this->mail );
		$this->assertStringContainsString( 'Order fulfilled', $this->mail[1]['subject'] );
		$this->assertStringContainsString( '1.500', $this->mail[1]['message'] );
		$this->assertStringContainsString( esc_html( openbb_format_money( 750, $order->currency ) ), $this->mail[1]['message'] );
		$this->assertStringNotContainsString( esc_html( openbb_format_money( 9999, $order->currency ) ), $this->mail[1]['message'] );
	}

	public function test_order_language_is_used_instead_of_staff_locale_and_restored(): void {
		global $wp_locale_switcher;
		$original_switcher = $wp_locale_switcher;
		$original_locale = determine_locale();
		$languages = static function ( array $available ): array {
			$available[] = 'fr_CA';
			return $available;
		};
		add_filter( 'get_available_languages', $languages );
		$wp_locale_switcher = new WP_Locale_Switcher();
		$wp_locale_switcher->init();
		remove_filter( 'get_available_languages', $languages );
		try {
			openbb_update_member_field( $this->member, 'language', 'fr' );
			$this->create_order();
			$this->assertCount( 1, $this->mail );
			$this->assertStringContainsString( 'Commande créée', $this->mail[0]['subject'] );
			$this->assertStringContainsString( 'Prix unitaire', $this->mail[0]['message'] );
			$this->assertSame( $original_locale, determine_locale() );
			$this->assertTrue( switch_to_locale( 'fr_CA' ) );
			$english = OPENBB_Orders::get_for_member( $this->cycle->id, $this->member );
			$english->language = 'en';
			OPENBB_Order_Emails::on_saved( $english, null );
			$this->assertStringContainsString( 'Order created', $this->mail[1]['subject'] );
			$this->assertSame( 'fr_CA', determine_locale() );
			restore_previous_locale();
		} finally {
			remove_filter( 'locale', array( $wp_locale_switcher, 'filter_locale' ) );
			remove_filter( 'determine_locale', array( $wp_locale_switcher, 'filter_locale' ) );
			$wp_locale_switcher = $original_switcher;
		}
	}

	public function test_own_container_change_without_a_charge_is_still_in_the_diff(): void {
		update_post_meta( $this->products[0]->id, OPENBB_META_PREFIX . 'container_charge', 0 );
		$order = $this->create_order();
		$lines = $order->get_lines();
		$saved = OPENBB_Order_Editor::apply(
			$order,
			array(
				'lines' => array(
					array( 'id' => $lines[0]->id, 'qty' => 2, 'own_container' => true ),
					array( 'id' => $lines[1]->id, 'qty' => 1 ),
				),
			),
			$this->member,
			true
		);
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		$this->assertCount( 2, $this->mail );
		$this->assertStringContainsString( 'Own container: No', $this->mail[1]['message'] );
		$this->assertStringContainsString( 'Own container: Yes', $this->mail[1]['message'] );
		$this->assertStringNotContainsString( 'Unchanged carrots', $this->mail[1]['message'] );
	}
}
