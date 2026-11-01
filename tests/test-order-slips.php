<?php
/**
 * Printable order slips: content, privacy, access, filters and language.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Order_Slips extends OPENBB_TestCase {

	/** @var int */
	private $staff;

	/** @var int */
	private $member;

	/** @var int */
	private $cycle;

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		$this->staff  = $this->make_user( 'coordinator' );
		$this->member = $this->make_user( 'member' );
		$this->cycle  = $this->make_cycle( OPENBB_Cycle::STATUS_PROCESSING );
		wp_set_current_user( $this->staff );
		delete_option( OPENBB_Settings::GENERAL_OPTION );
	}

	private function slips( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/openbb/v1/documents/order-slip' );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	private function update_order( int $order_id, array $fields ): void {
		global $wpdb;
		$wpdb->update( $wpdb->openbb_orders, $fields, array( 'id' => $order_id ) );
	}

	private function add_line( int $order_id, array $fields ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->openbb_order_lines,
			array_merge(
				array(
					'order_id'      => $order_id,
					'product_id'    => 1,
					'product_name'  => 'Rolled oats',
					'unit_amount'   => 1,
					'unit_type'     => 'kg',
					'unit_price'    => 1234,
					'qty_requested' => 1,
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				$fields
			)
		);
	}

	private function named_member( string $first, string $last ): int {
		$user_id = $this->make_user( 'member' );
		wp_update_user( array( 'ID' => $user_id, 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( "$first $last" ) ) );
		return $user_id;
	}

	public function test_slip_lists_what_to_pack_without_prices_contact_or_staff_details(): void {
		$user_id = $this->named_member( 'Ada', 'Lovelace' );
		$user    = get_userdata( $user_id );
		update_user_meta( $user_id, 'billing_phone', '555-0199' );
		$order_id = $this->make_order( $this->cycle, $user_id );
		$this->update_order(
			$order_id,
			array(
				'currency'    => 'CAD',
				'total'       => 4321,
				'member_note' => "<b>Ring</b> the bell\nThanks",
				'staff_note'  => 'SECRET-STAFF-NOTE',
				'aid_status'  => OPENBB_Order::AID_APPROVED,
				'aid_reason'  => 'SECRET-AID-REASON',
			)
		);
		$this->add_line( $order_id, array( 'product_name' => 'Oats <script>alert(1)</script>', 'brand' => 'Acme & Sons', 'qty_requested' => 2, 'qty_prepared' => 1.5, 'own_container' => 1 ) );
		$this->add_line( $order_id, array( 'product_name' => 'Removed beans', 'qty_requested' => 3, 'qty_final' => 0 ) );
		$this->add_line( $order_id, array( 'product_name' => 'Rice', 'qty_requested' => 4, 'qty_prepared' => 3, 'qty_final' => 2 ) );
		$this->add_line( $order_id, array( 'product_name' => 'Membership fee', 'line_type' => OPENBB_Order_Line::TYPE_MEMBERSHIP_FEE, 'unit_type' => 'unit', 'base_per_unit' => 0, 'unit_price' => 2500 ) );

		$response = $this->slips( array( 'orders' => array( $order_id ) ) );
		$html     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'text/html; charset=utf-8', $response->get_headers()['Content-Type'] );
		$this->assertSame( 'private, no-store, max-age=0', $response->get_headers()['Cache-Control'] );
		$this->assertStringContainsString( "default-src 'none'", $response->get_headers()['Content-Security-Policy'] );
		$this->assertMatchesRegularExpression( "/script-src 'nonce-([0-9a-f]{32})'/", $response->get_headers()['Content-Security-Policy'] );
		preg_match( "/'nonce-([0-9a-f]{32})'/", $response->get_headers()['Content-Security-Policy'], $nonce );
		$this->assertSame( 2, substr_count( $html, 'nonce="' . $nonce[1] . '"' ) );
		$this->assertSame( 1, substr_count( $html, '<script' ) );

		$this->assertStringContainsString( 'Lovelace, Ada', $html );
		$this->assertStringContainsString( 'Order #' . $order_id, $html );
		$this->assertStringContainsString( 'Test cycle', $html );
		$this->assertStringContainsString( 'Oats &lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringContainsString( 'Acme &amp; Sons', $html );
		$this->assertStringContainsString( '1.5 × 1 kg', $html );
		$this->assertStringContainsString( '2 × 1 kg', $html );
		$this->assertStringNotContainsString( 'Removed beans', $html );
		$this->assertStringContainsString( 'Also on this order:', $html );
		$this->assertStringContainsString( '&lt;b&gt;Ring&lt;/b&gt; the bell<br />', $html );
		$this->assertStringContainsString( 'Amount due', $html );
		$this->assertStringContainsString( esc_html( openbb_format_money( 4321, 'CAD' ) ), $html );

		foreach ( array( 'SECRET-STAFF-NOTE', 'SECRET-AID-REASON', $user->user_email, '555-0199', esc_html( openbb_format_money( 1234, 'CAD' ) ), esc_html( openbb_format_money( 2500, 'CAD' ) ) ) as $private ) {
			$this->assertStringNotContainsString( $private, $html );
		}
	}

	public function test_paid_order_is_marked_paid_and_document_is_served_as_html(): void {
		$order_id = $this->make_order( $this->cycle, $this->member );
		$this->update_order( $order_id, array( 'status' => OPENBB_Order::STATUS_PREPAID, 'paid_at' => '2025-03-04 15:00:00', 'currency' => 'CAD', 'total' => 999 ) );

		$response = $this->slips( array( 'orders' => (string) $order_id, 'autoprint' => 'true' ) );
		$html     = $response->get_data();
		$this->assertStringContainsString( 'PAID', $html );
		$this->assertStringNotContainsString( 'Amount due', $html );
		$this->assertStringContainsString( 'window.print', $html );
		$this->assertStringContainsString( "addEventListener( 'load'", $html );

		ob_start();
		$served = OPENBB_Documents::serve( false, $response );
		$this->assertTrue( $served );
		$this->assertSame( $html, ob_get_clean() );

		$json = new WP_REST_Response( array( 'id' => 1 ) );
		$json->set_matched_route( '/openbb/v1/orders' );
		$this->assertFalse( OPENBB_Documents::serve( false, $json ) );
	}

	public function test_batch_prints_eligible_orders_sorted_by_last_name_with_filters(): void {
		$zed     = $this->make_order( $this->cycle, $this->named_member( 'Zoe', 'Zed' ) );
		$adams_b = $this->make_order( $this->cycle, $this->named_member( 'Bob', 'Adams' ) );
		$adams_a = $this->make_order( $this->cycle, $this->named_member( 'Al', 'Adams' ) );
		$draft   = $this->make_order( $this->cycle, $this->make_user( 'member' ) );
		$gone    = $this->make_order( $this->cycle, $this->make_user( 'member' ) );
		$other   = $this->make_order( $this->make_cycle( OPENBB_Cycle::STATUS_PROCESSING ), $this->member );
		$this->update_order( $draft, array( 'status' => OPENBB_Order::STATUS_DRAFT ) );
		$this->update_order( $gone, array( 'status' => OPENBB_Order::STATUS_CANCELLED ) );
		$this->update_order( $zed, array( 'status' => OPENBB_Order::STATUS_FULFILLED, 'paid_at' => '2025-03-04 15:00:00' ) );

		$html = $this->slips( array( 'cycle_id' => $this->cycle ) )->get_data();
		$this->assertSame( 3, substr_count( $html, 'class="openbb-slip"' ) );
		$this->assertStringContainsString( '3 order slips', $html );
		$positions = array_map(
			static function ( int $id ) use ( $html ) {
				return strpos( $html, 'Order #' . $id . '<' );
			},
			array( $adams_a, $adams_b, $zed )
		);
		$sorted = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions );
		foreach ( array( $draft, $gone, $other ) as $excluded ) {
			$this->assertStringNotContainsString( 'Order #' . $excluded . '<', $html );
		}

		$unpaid = $this->slips( array( 'cycle_id' => $this->cycle, 'paid' => 'unpaid' ) )->get_data();
		$this->assertSame( 2, substr_count( $unpaid, 'class="openbb-slip"' ) );
		$this->assertStringNotContainsString( 'Order #' . $zed . '<', $unpaid );

		$fulfilled = $this->slips( array( 'cycle_id' => $this->cycle, 'status' => 'fulfilled' ) )->get_data();
		$this->assertSame( 1, substr_count( $fulfilled, 'class="openbb-slip"' ) );

		$search = $this->slips( array( 'cycle_id' => $this->cycle, 'search' => 'Bob' ) )->get_data();
		$this->assertSame( 1, substr_count( $search, 'class="openbb-slip"' ) );

		$none = $this->slips( array( 'orders' => array( $draft ) ) );
		$this->assertSame( 200, $none->get_status() );
		$this->assertStringContainsString( 'No orders match', $none->get_data() );
		$this->assertStringNotContainsString( 'id="openbb-print"', $none->get_data() );

		$this->assertSame( 400, $this->slips( array( 'status' => 'draft', 'cycle_id' => $this->cycle ) )->get_status() );
		$this->assertSame( 400, $this->slips( array() )->get_status() );
	}

	public function test_only_staff_who_can_see_the_cycle_orders_can_print(): void {
		$order_id      = $this->make_order( $this->cycle, $this->member );
		$ordering      = $this->make_cycle( OPENBB_Cycle::STATUS_ORDERING );
		$open_order    = $this->make_order( $ordering, $this->member );
		$membership_id = $this->make_order( 0, $this->member );
		$this->update_order( $membership_id, array( 'cycle_id' => null ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->slips( array( 'orders' => array( $order_id ) ) )->get_status() );

		foreach ( array( $this->member, $this->make_user( 'distribution_helper' ) ) as $user_id ) {
			wp_set_current_user( $user_id );
			$this->assertSame( 403, $this->slips( array( 'orders' => array( $order_id ) ) )->get_status() );
			$this->assertSame( 403, $this->slips( array( 'cycle_id' => $this->cycle ) )->get_status() );
		}

		$roles = static function ( array $roles ): array {
			$roles['packer'] = array( 'openbb_process_orders' );
			return $roles;
		};
		add_filter( 'openbb_default_roles', $roles );
		OPENBB_Roles::flush();
		try {
			wp_set_current_user( $this->make_user( 'packer' ) );
			$this->assertSame( 200, $this->slips( array( 'orders' => array( $order_id ) ) )->get_status() );
			$this->assertSame( 200, $this->slips( array( 'cycle_id' => $this->cycle ) )->get_status() );
			$this->assertSame( 404, $this->slips( array( 'orders' => array( $open_order ) ) )->get_status() );
			$this->assertSame( 404, $this->slips( array( 'orders' => array( $order_id, $open_order ) ) )->get_status() );
			$this->assertSame( 404, $this->slips( array( 'cycle_id' => $ordering ) )->get_status() );
		} finally {
			remove_filter( 'openbb_default_roles', $roles );
			OPENBB_Roles::flush();
		}

		wp_set_current_user( $this->staff );
		$this->assertSame( 200, $this->slips( array( 'orders' => array( $open_order ) ) )->get_status() );
		$this->assertSame( 200, $this->slips( array( 'cycle_id' => $ordering ) )->get_status() );
		$this->assertSame( 404, $this->slips( array( 'orders' => array( $membership_id ) ) )->get_status() );
		$this->assertSame( 404, $this->slips( array( 'orders' => array( 999999 ) ) )->get_status() );
		$this->assertSame( 404, $this->slips( array( 'cycle_id' => 999999 ) )->get_status() );
	}

	public function test_site_title_and_paper_size_from_settings(): void {
		update_option( 'blogname', 'Green <Co-op>' );
		$order_id = $this->make_order( $this->cycle, $this->member );
		$html     = $this->slips( array( 'orders' => array( $order_id ) ) )->get_data();
		$this->assertStringContainsString( 'Green &lt;Co-op&gt;', $html );
		$this->assertStringContainsString( 'size: letter', $html );
		$this->assertStringNotContainsString( '<img', $html );

		update_option( OPENBB_Settings::GENERAL_OPTION, array( 'document_paper_size' => 'a4' ) );
		$html = $this->slips( array( 'orders' => array( $order_id ) ) )->get_data();
		$this->assertStringContainsString( 'size: A4', $html );
	}

	public function test_each_slip_uses_its_order_language(): void {
		global $wp_locale_switcher;
		$original_switcher = $wp_locale_switcher;
		$original_locale   = determine_locale();
		$languages         = static function ( array $available ): array {
			$available[] = 'fr_CA';
			return $available;
		};
		add_filter( 'get_available_languages', $languages );
		$wp_locale_switcher = new WP_Locale_Switcher();
		$wp_locale_switcher->init();
		remove_filter( 'get_available_languages', $languages );
		try {
			$french  = $this->make_order( $this->cycle, $this->named_member( 'Aline', 'Bastien' ) );
			$english = $this->make_order( $this->cycle, $this->named_member( 'Carl', 'Dunn' ) );
			$this->update_order( $french, array( 'language' => 'fr' ) );
			$this->update_order( $english, array( 'language' => 'en' ) );
			$this->add_line( $french, array() );
			$this->add_line( $english, array() );

			$html  = $this->slips( array( 'cycle_id' => $this->cycle ) )->get_data();
			$pages = explode( 'class="openbb-slip"', $html );
			$this->assertCount( 3, $pages );
			$this->assertStringContainsString( 'Remis', $pages[1] );
			$this->assertStringContainsString( 'Commande no ' . $french, $pages[1] );
			$this->assertStringContainsString( 'Handed out', $pages[2] );
			$this->assertStringContainsString( '<html lang="en-US">', $html );
			$this->assertSame( $original_locale, determine_locale() );
		} finally {
			remove_filter( 'locale', array( $wp_locale_switcher, 'filter_locale' ) );
			remove_filter( 'determine_locale', array( $wp_locale_switcher, 'filter_locale' ) );
			$wp_locale_switcher = $original_switcher;
		}
	}

	public function test_orders_list_filters_by_payment(): void {
		$paid   = $this->make_order( $this->cycle, $this->member );
		$unpaid = $this->make_order( $this->cycle, $this->make_user( 'member' ) );
		$this->update_order( $paid, array( 'status' => OPENBB_Order::STATUS_PREPAID, 'paid_at' => '2025-03-04 15:00:00' ) );

		foreach ( array( 'paid' => array( $paid ), 'unpaid' => array( $unpaid ), 'all' => array( $unpaid, $paid ) ) as $filter => $expected ) {
			$request = new WP_REST_Request( 'GET', '/openbb/v1/orders' );
			$request->set_query_params( array( 'cycle_id' => $this->cycle, 'paid' => $filter ) );
			$this->assertSame( $expected, wp_list_pluck( rest_do_request( $request )->get_data(), 'id' ), $filter );
		}
	}
}
