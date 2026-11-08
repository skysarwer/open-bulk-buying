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
				'staff_note' => 'Visible <b>staff</b> note',
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
		$this->assertStringContainsString( '>Staff note<', $mail['message'] );
		$this->assertStringContainsString( 'Visible &lt;b&gt;staff&lt;/b&gt; note', $mail['message'] );
	}

	public function test_staff_note_is_omitted_when_disabled_or_empty(): void {
		update_option( OPENBB_Settings::GENERAL_OPTION, array( 'email_staff_note' => false ) );
		$order = $this->create_order();
		$this->assertCount( 1, $this->mail );
		$this->assertStringNotContainsString( 'Staff note', $this->mail[0]['message'] );
		$this->assertStringNotContainsString( 'Visible', $this->mail[0]['message'] );
		$saved = OPENBB_Order_Editor::apply( $order, array( 'status' => 'cancelled' ), $this->member );
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		$this->assertCount( 2, $this->mail );
		$this->assertStringNotContainsString( 'Visible', $this->mail[1]['message'] );

		delete_option( OPENBB_Settings::GENERAL_OPTION );
		$saved->staff_note = " \n ";
		$mail = OPENBB_Emails::render( 'order-event', array( 'order' => $saved, 'event' => 'cancelled', 'changes' => array() ) );
		$this->assertStringNotContainsString( 'Staff note', $mail['message'] );
	}

	public function test_membership_emails_show_staff_note_only_when_enabled_and_present(): void {
		$this->configure_membership_fee();
		$claim = OPENBB_Membership_Fees::claim( $this->member, 'Reference' );
		$this->assertInstanceOf( OPENBB_Order::class, $claim );
		$this->assertStringNotContainsString( '>Staff note<', $this->mail[0]['message'] );
		$claim->staff_note = 'Bank transfer not found';
		$data = array( 'order' => $claim, 'event' => 'cancelled', 'changes' => array() );
		$mail = OPENBB_Emails::render( 'order-event', $data );
		$this->assertStringContainsString( '>Staff note<', $mail['message'] );
		$this->assertStringContainsString( 'Bank transfer not found', $mail['message'] );
		update_option( OPENBB_Settings::GENERAL_OPTION, array_merge( OPENBB_Settings::get_general(), array( 'email_staff_note' => false ) ) );
		$this->assertStringNotContainsString( 'Bank transfer not found', OPENBB_Emails::render( 'order-event', $data )['message'] );
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

	public function test_update_shows_full_order_and_change_summaries(): void {
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
		foreach ( array( '2 x', '3 x', 'Unchanged carrots', 'Added beans', 'Donation', 'Container credit', 'Total', '>Staff note<', 'Visible &lt;b&gt;staff' ) as $text ) {
			$this->assertStringContainsString( $text, $mail['message'] );
		}
		$this->assertStringContainsString( '<b>Apples alert(1)</b> changed from <b>2 x', $mail['message'] );
		$this->assertStringContainsString( '</b> to <b>3 x', $mail['message'] );
		$this->assertStringContainsString( '<b>Added beans</b> added: <b>1 x', $mail['message'] );
		$this->assertStringContainsString( '<b>Donation</b> changed from <b>' . esc_html( openbb_format_money( 0, $order->currency ) ) . '</b> to <b>' . esc_html( openbb_format_money( 100, $order->currency ) ) . '</b>', $mail['message'] );
		$this->assertStringNotContainsString( '<th scope="col" style="border:1px solid #cccccc;padding:8px;text-align:left;vertical-align:top;">Before</th>', $mail['message'] );
		$this->assertStringNotContainsString( '<script', $mail['message'] );
		$this->assertStringNotContainsString( 'Private', $mail['message'] );
	}

	public function test_membership_fee_removal_email_shows_only_the_removed_amount(): void {
		update_option( OPENBB_Settings::GENERAL_OPTION, array( 'membership_fee_amount' => 2000 ) );
		$order = $this->create_order();
		$lines = array();
		foreach ( $order->get_lines() as $line ) {
			if ( ! $line->is_membership_fee() ) {
				$lines[] = array( 'id' => $line->id, 'qty' => $line->qty_requested );
			}
		}
		$saved = OPENBB_Order_Editor::apply( $order, array( 'lines' => $lines ), $this->member );
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		$this->assertSame( 0, $saved->membership_fee() );
		$this->assertCount( 2, $this->mail );
		$amount = esc_html( openbb_format_money( 2000, $order->currency ) );
		$message = $this->mail[1]['message'];
		$this->assertStringContainsString( '<b>Membership fee</b> removed: <b>' . $amount . '</b>.', $message );
		$this->assertStringNotContainsString( '<b>Membership fee</b> removed: <b>1 x', $message );
		$this->assertStringContainsString( 'Unchanged carrots', $message );
		$this->assertStringContainsString( '>Container credit<', $message );
	}

	public function test_membership_fee_addition_email_shows_only_the_added_amount(): void {
		$order = $this->create_order();
		update_option( OPENBB_Settings::GENERAL_OPTION, array( 'membership_fee_amount' => 2000 ) );
		$saved = OPENBB_Order_Editor::apply( $order, array(), $this->member );
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		$this->assertSame( 2000, $saved->membership_fee() );
		$this->assertCount( 2, $this->mail );
		$amount = esc_html( openbb_format_money( 2000, $order->currency ) );
		$this->assertStringContainsString( '<b>Membership fee</b> added: <b>' . $amount . '</b>.', $this->mail[1]['message'] );
	}

	public function test_member_removal_and_note_only_update_show_current_order(): void {
		$order = $this->create_order( 'submitted', true );
		$line = $order->get_lines()[1];
		$order = OPENBB_Order_Editor::apply( $order, array( 'lines' => array( array( 'id' => $line->id, 'qty' => 1 ) ) ), $this->member, true );
		$this->assertInstanceOf( OPENBB_Order::class, $order );
		$this->assertStringContainsString( '<b>Apples alert(1)</b> removed: <b>2 x', $this->mail[1]['message'] );
		$this->assertStringContainsString( 'Unchanged carrots', $this->mail[1]['message'] );
		OPENBB_Order_Editor::apply( $order, array( 'member_note' => '<b>Please call</b>' ), $this->member, true );
		$this->assertCount( 3, $this->mail );
		$this->assertStringContainsString( '<b>Member note</b> added: <b>&lt;b&gt;Please call&lt;/b&gt;</b>', $this->mail[2]['message'] );
		$this->assertStringContainsString( 'Unchanged carrots', $this->mail[2]['message'] );
		$this->assertStringNotContainsString( 'Apples', $this->mail[2]['message'] );
	}

	public function test_change_summary_escapes_all_values(): void {
		$order = $this->create_order();
		$mail = OPENBB_Emails::render(
			'order-event',
			array(
				'order' => $order,
				'event' => 'updated',
				'changes' => array( array( '<script>name</script>', '<b>old</b>', "<img src=x>\nnew" ) ),
			)
		);
		$this->assertStringContainsString( '<b>&lt;script&gt;name&lt;/script&gt;</b> changed from <b>&lt;b&gt;old&lt;/b&gt;</b> to <b>&lt;img src=x&gt;<br', $mail['message'] );
		$this->assertStringNotContainsString( '<script>', $mail['message'] );
		$this->assertStringNotContainsString( '<img', $mail['message'] );
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
		$this->assertWPError( OPENBB_Order_Editor::apply( $order, array( 'status' => 'draft', 'reason' => 'Not allowed' ), $this->member ) );
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
			$translated = OPENBB_Emails::render( 'order-event', array( 'order' => $english, 'event' => 'updated', 'changes' => array( array( 'X', 'Y', 'Z' ), array( 'A', '', 'B' ), array( 'C', 'D', '' ) ) ) );
			$this->assertStringContainsString( '<b>X</b> est passé de <b>Y</b> à <b>Z</b>.', $translated['message'] );
			$this->assertStringContainsString( '<b>A</b> ajouté : <b>B</b>.', $translated['message'] );
			$this->assertStringContainsString( '<b>C</b> retiré : <b>D</b>.', $translated['message'] );
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

	private function configure_membership_fee(): void {
		update_option(
			OPENBB_Settings::GENERAL_OPTION,
			array_merge(
				(array) get_option( OPENBB_Settings::GENERAL_OPTION, array() ),
				array( 'membership_fee_type' => 'one_time', 'membership_fee_amount' => 2500 )
			)
		);
	}

	public function test_membership_claim_confirmation_and_retry_use_compact_receipts(): void {
		$this->configure_membership_fee();
		$actor = $this->make_user( 'coordinator', '' );
		$claim = OPENBB_Membership_Fees::claim( $this->member, 'ET-123 & transfer' );
		$this->assertInstanceOf( OPENBB_Order::class, $claim );
		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'awaiting verification', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'not a payment receipt', $this->mail[0]['message'] );
		$this->assertStringNotContainsString( '>Paid on<', $this->mail[0]['message'] );

		$paid = OPENBB_Membership_Fees::review( $this->member, $claim->id, $actor );
		$this->assertInstanceOf( OPENBB_Order::class, $paid );
		$this->assertCount( 2, $this->mail );
		$message = $this->mail[1]['message'];
		$this->assertStringContainsString( 'Membership payment confirmed', $this->mail[1]['subject'] );
		$this->assertStringContainsString( 'Keep this email as your payment receipt', $message );
		$this->assertStringContainsString( esc_html( openbb_format_money( $paid->paid_amount, $paid->paid_currency ) ), $message );
		$this->assertStringContainsString( esc_html( get_date_from_gmt( $paid->paid_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ), $message );
		$this->assertStringContainsString( 'ET-123 &amp; transfer', $message );
		foreach ( array( '<script', '>Package<', '>Quantity<', '>Container credit<', '>Donation<', '>Financial aid<' ) as $excluded ) {
			$this->assertStringNotContainsString( $excluded, $message );
		}
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Membership_Fees::review( $this->member, $claim->id, $actor ) );
		$this->assertCount( 2, $this->mail );
	}

	public function test_separate_recorded_payment_and_rejected_claim_have_distinct_copy(): void {
		$this->configure_membership_fee();
		$actor = $this->make_user( 'coordinator', '' );
		$paid = OPENBB_Membership_Fees::record( $this->member, $actor );
		$this->assertInstanceOf( OPENBB_Order::class, $paid );
		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'Membership payment confirmed', $this->mail[0]['subject'] );
		$this->assertStringNotContainsString( '>Reference<', $this->mail[0]['message'] );

		$member = $this->make_user( 'member' );
		$claim = OPENBB_Membership_Fees::claim( $member, 'Claim reference' );
		$this->assertInstanceOf( OPENBB_Order::class, $claim );
		$rejected = OPENBB_Membership_Fees::review( $member, $claim->id, $actor, false, true );
		$this->assertInstanceOf( OPENBB_Order::class, $rejected );
		$this->assertCount( 3, $this->mail );
		$this->assertStringContainsString( 'claim rejected', $this->mail[2]['subject'] );
		$this->assertStringContainsString( 'not a payment receipt', $this->mail[2]['message'] );
		$this->assertStringContainsString( 'contact the co-op', $this->mail[2]['message'] );
		$this->assertStringNotContainsString( '>Paid on<', $this->mail[2]['message'] );
	}

	public function test_own_container_change_without_a_charge_is_still_summarized(): void {
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
		$this->assertStringContainsString( 'Unchanged carrots', $this->mail[1]['message'] );
	}
}
