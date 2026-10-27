<?php
/**
 * Admin REST API: permissions, stock limits on orders and the inventory ledger.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_REST_Admin extends OPENBB_TestCase {

	/** @var int */
	private $coordinator;

	public function set_up(): void {
		parent::set_up();
		rest_get_server();
		$this->coordinator = $this->make_user( 'coordinator', '' );
		wp_set_current_user( $this->coordinator );
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

	private function make_stocked_product( float $quantity ): int {
		$response = $this->api(
			'POST',
			'/products',
			array(
				'name'   => 'Oats',
				'price'  => 250,
				'active' => true,
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$product_id = $response->get_data()['id'];

		$move = $this->api(
			'POST',
			"/products/{$product_id}/moves",
			array(
				'quantity' => $quantity,
				'unit'     => 'selling',
				'reason'   => 'receipt',
			)
		);
		$this->assertSame( 201, $move->get_status() );

		return $product_id;
	}

	public function test_product_display_order_uses_native_field_and_sorts_with_name_ties(): void {
		$ids = array();
		foreach ( array( array( 'Zucchini', 20 ), array( 'Beans', -5 ), array( 'Apples', 20 ) ) as $row ) {
			$response = $this->api( 'POST', '/products', array( 'name' => $row[0], 'menu_order' => $row[1] ) );
			$this->assertSame( 201, $response->get_status() );
			$item = $response->get_data();
			$ids[] = $item['id'];
			$this->assertSame( $row[1], $item['menu_order'] );
			$this->assertSame( $row[1], (int) get_post( $item['id'] )->menu_order );
			$this->assertSame( $row[1], OPENBB_Product::get( $item['id'] )->menu_order );
			$this->assertFalse( metadata_exists( 'post', $item['id'], OPENBB_META_PREFIX . 'menu_order' ) );
		}
		$items = $this->api( 'GET', '/products', array( 'orderby' => 'menu_order' ) )->get_data();
		$this->assertSame( array( $ids[1], $ids[2], $ids[0] ), wp_list_pluck( $items, 'id' ) );
		$items = $this->api( 'GET', '/products', array( 'orderby' => 'menu_order', 'order' => 'desc' ) )->get_data();
		$this->assertSame( array( $ids[2], $ids[0], $ids[1] ), wp_list_pluck( $items, 'id' ) );
		$updated = $this->api( 'PUT', "/products/{$ids[0]}", array( 'menu_order' => 7 ) );
		$this->assertSame( 7, $updated->get_data()['menu_order'] );
		$this->assertSame( 7, $this->api( 'PUT', "/products/{$ids[0]}", array( 'brand' => 'New brand' ) )->get_data()['menu_order'] );
		$this->assertSame( 400, $this->api( 'PUT', "/products/{$ids[0]}", array( 'menu_order' => 1.5 ) )->get_status() );
	}

	public function test_category_order_defaults_sorts_updates_and_checks_access(): void {
		$ids = array();
		foreach ( array( array( 'Zucchini', 20 ), array( 'Beans', -5 ), array( 'Apples', 20 ) ) as $row ) {
			$response = $this->api( 'POST', '/product-terms/categories', array( 'name' => $row[0], 'order' => $row[1] ) );
			$this->assertSame( 201, $response->get_status() );
			$ids[] = $response->get_data()['id'];
			$this->assertSame( $row[1], $response->get_data()['order'] );
		}
		$legacy = wp_insert_term( 'Legacy category', OPENBB_PRODUCT_TAXONOMY );
		$items  = $this->api( 'GET', '/product-terms/categories' )->get_data();
		$this->assertSame( array( $ids[1], $legacy['term_id'], $ids[2], $ids[0] ), wp_list_pluck( $items, 'id' ) );
		$this->assertSame( 0, $items[1]['order'] );
		$response = $this->api( 'PUT', "/product-terms/categories/{$ids[0]}", array( 'order' => -10 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( -10, $response->get_data()['order'] );
		$this->assertSame( -10, (int) get_term_meta( $ids[0], OPENBB_META_PREFIX . 'order', true ) );
		$this->assertSame( 400, $this->api( 'PUT', "/product-terms/categories/{$ids[0]}", array( 'order' => 0.5 ) )->get_status() );
		$this->assertSame( 400, $this->api( 'PUT', "/product-terms/categories/{$ids[0]}" )->get_status() );
		$supplier = $this->api( 'POST', '/product-terms/suppliers', array( 'name' => 'Supplier' ) )->get_data();
		$this->assertArrayNotHasKey( 'order', $supplier );
		$this->assertSame( 404, $this->api( 'PUT', "/product-terms/categories/{$supplier['id']}", array( 'order' => 1 ) )->get_status() );
		wp_set_current_user( $this->make_user( 'member' ) );
		$this->assertSame( 403, $this->api( 'PUT', "/product-terms/categories/{$ids[0]}", array( 'order' => 1 ) )->get_status() );
	}

	private function make_open_cycle(): int {
		$cycle = new OPENBB_Cycle(
			array(
				'name'   => 'Open cycle',
				'status' => OPENBB_Cycle::STATUS_ORDERING,
			)
		);
		$this->assertTrue( $cycle->save() );

		return $cycle->id;
	}

	private function new_order( int $cycle_id ): int {
		$response = $this->api(
			'POST',
			'/orders',
			array(
				'cycle_id' => $cycle_id,
				'user_id'  => $this->make_user( 'member' ),
			)
		);
		$this->assertSame( 201, $response->get_status() );

		return $response->get_data()['id'];
	}

	private function submit( int $order_id, int $product_id, float $qty ): WP_REST_Response {
		return $this->api(
			'PUT',
			"/orders/{$order_id}",
			array(
				'status' => 'submitted',
				'lines'  => array(
					array(
						'product_id' => $product_id,
						'qty'        => $qty,
					),
				),
			)
		);
	}

	public function test_members_cannot_use_the_admin_api(): void {
		wp_set_current_user( $this->make_user( 'member' ) );

		foreach ( array( '/products', '/cycles', '/orders', '/members' ) as $route ) {
			$this->assertSame( 403, $this->api( 'GET', $route )->get_status(), $route );
		}
	}

	public function test_administrators_can_update_their_own_membership_and_details(): void {
		$admin = $this->make_user( 'member', 'administrator' );
		wp_set_current_user( $admin );

		$response = $this->api(
			'PUT',
			"/members/{$admin}",
			array(
				'first_name' => 'Co-op',
				'phone'      => '555-0100',
				'status'     => 'inactive',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'inactive', $response->get_data()['status'] );
		$this->assertSame( 'Co-op', get_user_meta( $admin, 'first_name', true ) );
		$this->assertSame( '555-0100', openbb_get_member_field( $admin, 'phone' ) );
	}

	public function test_cycle_managers_can_toggle_the_preparation_cleanup_lock(): void {
		$cycle = new OPENBB_Cycle( array( 'name' => 'Preparation lock cycle' ) );
		$this->assertTrue( OPENBB_Cycles::store( $cycle, '', $this->coordinator ) );

		$response = $this->api( 'PUT', "/cycles/{$cycle->id}/preparation-cleanup", array( 'locked' => true ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['cleanup_locked'] );
		$this->assertFalse( $response->get_data()['preparation_cleanup'] );

		$response = $this->api( 'PUT', "/cycles/{$cycle->id}/preparation-cleanup", array( 'locked' => false ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['cleanup_locked'] );
		$this->assertTrue( $response->get_data()['preparation_cleanup'] );

		wp_set_current_user( $this->make_user( 'member' ) );
		$this->assertSame( 403, $this->api( 'PUT', "/cycles/{$cycle->id}/preparation-cleanup", array( 'locked' => true ) )->get_status() );
	}

	public function test_orders_are_limited_to_available_stock(): void {
		$product_id = $this->make_stocked_product( 5 );
		$cycle_id   = $this->make_open_cycle();

		$first = $this->new_order( $cycle_id );
		$this->assertSame( 200, $this->submit( $first, $product_id, 3 )->get_status() );

		// Only 2 are left once the first order reserves 3.
		$second   = $this->new_order( $cycle_id );
		$response = $this->submit( $second, $product_id, 3 );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'openbb_insufficient_stock', $response->as_error()->get_error_code() );
		$this->assertEquals( 2, $response->as_error()->get_error_data()['max_qty'] );
		$this->assertSame( 200, $this->submit( $second, $product_id, 2 )->get_status() );

		// The first order may still hold its own reservation, but no more.
		$products = $this->api( 'GET', '/order-products', array( 'order_id' => $first ) )->get_data();
		$this->assertEquals( 3, wp_list_pluck( $products, 'max_qty', 'id' )[ $product_id ] );
	}

	public function test_staff_cannot_purchase_fractional_selling_packages(): void {
		$product_id = $this->make_stocked_product( 5 );
		$order_id = $this->new_order( $this->make_open_cycle() );
		foreach ( array( 0.5, 1.5, 1.0001 ) as $qty ) {
			$response = $this->submit( $order_id, $product_id, $qty );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'openbb_invalid_order', $response->as_error()->get_error_code() );
		}
		$this->assertSame( 200, $this->submit( $order_id, $product_id, 2 )->get_status() );
		$line = OPENBB_Orders::get_lines( $order_id )[0];
		$response = $this->api( 'PUT', "/orders/{$order_id}", array(
			'reason' => 'Member requested a different quantity',
			'lines' => array( array( 'id' => $line->id, 'qty' => 2.0001 ) ),
		) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 2.0, OPENBB_Orders::get_lines( $order_id )[0]->qty_requested );
	}

	public function test_orders_in_a_setup_cycle_reserve_stock(): void {
		$product_id = $this->make_stocked_product( 5 );
		$cycle      = new OPENBB_Cycle(
			array(
				'name'   => 'Setup cycle',
				'status' => OPENBB_Cycle::STATUS_SETUP,
			)
		);
		$this->assertTrue( $cycle->save() );

		$order = $this->new_order( $cycle->id );
		$this->assertSame( 200, $this->submit( $order, $product_id, 3 )->get_status() );

		$this->assertEquals( 3, OPENBB_Inventory::get_reserved( $product_id ) );
		$this->assertEquals( 3, OPENBB_Inventory::get_reserved_for( array( $product_id ) )[ $product_id ] );
		$product = $this->api( 'GET', "/products/{$product_id}" )->get_data();
		$this->assertEquals( 3, $product['reserved_units'] );
	}

	public function test_duplicate_orders_point_to_the_existing_order(): void {
		$cycle_id = $this->make_open_cycle();
		$member   = $this->make_user( 'member' );
		$args     = array(
			'cycle_id' => $cycle_id,
			'user_id'  => $member,
		);

		$first    = $this->api( 'POST', '/orders', $args );
		$response = $this->api( 'POST', '/orders', $args );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $first->get_data()['id'], $response->as_error()->get_error_data()['order_id'] );
	}

	public function test_submitted_changes_need_a_reason(): void {
		$product_id = $this->make_stocked_product( 5 );
		$order_id   = $this->new_order( $this->make_open_cycle() );
		$this->submit( $order_id, $product_id, 1 );

		$response = $this->api( 'PUT', "/orders/{$order_id}", array( 'status' => 'cancelled' ) );
		$this->assertSame( 'openbb_reason_required', $response->as_error()->get_error_code() );

		$response = $this->api(
			'PUT',
			"/orders/{$order_id}",
			array(
				'status' => 'cancelled',
				'reason' => 'Member asked to cancel.',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Member asked to cancel.', $response->get_data()['staff_note'] );
	}

	public function test_stock_cannot_go_negative(): void {
		$product_id = $this->make_stocked_product( 2 );

		$response = $this->api(
			'POST',
			"/products/{$product_id}/moves",
			array(
				'quantity' => -3,
				'unit'     => 'selling',
				'reason'   => 'spoilage',
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertEquals( 2, OPENBB_Inventory::get_on_hand( $product_id ) );
	}

	public function test_payment_and_fulfillment_require_confirmation_and_capabilities(): void {
		$product_id = $this->make_stocked_product( 5 );
		$cycle_id   = $this->make_open_cycle();
		$order_id   = $this->new_order( $cycle_id );
		$order      = $this->submit( $order_id, $product_id, 2 )->get_data();
		$params     = array(
			'confirmed'           => true,
			'expected_updated_at' => $order['updated_at'],
			'expected_revision'   => $order['revision'],
		);
		$this->assertSame( 400, $this->api( 'POST', "/orders/{$order_id}/prepay", array_merge( $params, array( 'confirmed' => false ) ) )->get_status() );
		$this->assertSame( 400, $this->api( 'POST', "/orders/{$order_id}/prepay" )->get_status() );
		$this->assertSame( 400, $this->api( 'PUT', "/orders/{$order_id}", array( 'status' => 'prepaid' ) )->get_status() );
		$this->assertSame( 400, $this->api( 'PUT', "/orders/{$order_id}", array( 'status' => 'fulfilled' ) )->get_status() );
		wp_set_current_user( $this->make_user( 'member' ) );
		$this->assertSame( 403, $this->api( 'POST', "/orders/{$order_id}/prepay", $params )->get_status() );
		wp_set_current_user( $this->coordinator );
		$this->assertSame( 409, $this->api( 'POST', "/orders/{$order_id}/fulfill", $params )->get_status() );
		$paid = $this->api( 'POST', "/orders/{$order_id}/prepay", $params );
		$this->assertSame( 200, $paid->get_status() );
		$this->assertSame( 'prepaid', $paid->get_data()['status'] );
		$this->assertEquals( 500, $paid->get_data()['paid_amount'] );
		$this->assertTrue( $paid->get_data()['reserves_stock'] );
		$this->assertTrue( $paid->get_data()['is_locked'] );
		$this->assertFalse( $paid->get_data()['can_edit_lines'] );
		$this->assertFalse( $paid->get_data()['can_edit_amounts'] );
		$this->assertFalse( $paid->get_data()['can_cancel'] );
		$this->assertFalse( $paid->get_data()['can_delete'] );
		$this->assertFalse( $paid->get_data()['can_prepay'] );
		$this->assertFalse( $paid->get_data()['can_fulfill'] );
		$this->assertSame( 1, (int) $this->api( 'GET', '/orders', array( 'status' => array( 'prepaid' ) ) )->get_headers()['X-WP-Total'] );
		$cycle = OPENBB_Cycles::get( $cycle_id );
		$cycle->status = OPENBB_Cycle::STATUS_DISTRIBUTION;
		$this->assertTrue( $cycle->save() );
		$params['expected_updated_at'] = $paid->get_data()['updated_at'];
		$params['expected_revision']   = $paid->get_data()['revision'];
		$available = $this->api( 'GET', "/orders/{$order_id}" )->get_data();
		$this->assertTrue( $available['can_fulfill'] );
		$this->assertFalse( $available['can_edit_lines'] );
		$done = $this->api( 'POST', "/orders/{$order_id}/fulfill", $params );
		$this->assertSame( 200, $done->get_status() );
		$this->assertSame( 'fulfilled', $done->get_data()['status'] );
		$this->assertFalse( $done->get_data()['reserves_stock'] );
		$this->assertNotNull( $done->get_data()['collected_at'] );
		$this->assertSame( 200, $this->api( 'POST', "/orders/{$order_id}/fulfill", $params )->get_status() );
		$this->assertEquals( 3, OPENBB_Inventory::get_on_hand( $product_id ) );
	}

	public function test_cycle_summaries_include_prepaid_and_fulfilled_orders(): void {
		$product_id = $this->make_stocked_product( 10 );
		$cycle_id   = $this->make_open_cycle();
		$paid_id    = $this->new_order( $cycle_id );
		$done_id    = $this->new_order( $cycle_id );
		$this->submit( $paid_id, $product_id, 2 );
		$this->submit( $done_id, $product_id, 1 );
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Order_Lifecycle::confirm( $paid_id, 'prepay', $this->coordinator ) );
		$cycle = OPENBB_Cycles::get( $cycle_id );
		$cycle->status = 'distribution';
		$this->assertTrue( $cycle->save() );
		$this->assertInstanceOf( OPENBB_Order::class, OPENBB_Order_Lifecycle::confirm( $done_id, 'fulfill', $this->coordinator ) );
		$summary = $this->api( 'GET', "/cycles/{$cycle_id}" )->get_data();
		$this->assertSame( 1, $summary['submitted_count'] );
		$this->assertSame( 1, $summary['prepaid_count'] );
		$this->assertSame( 1, $summary['fulfilled_count'] );
		$this->assertSame( 750, $summary['orders_total'] );
	}

	public function test_distribution_can_be_recorded_by_hand_and_only_removes_stock(): void {
		$product_id = $this->make_stocked_product( 5 );
		$move       = function ( float $quantity ) use ( $product_id ) {
			return $this->api(
				'POST',
				"/products/{$product_id}/moves",
				array(
					'quantity' => $quantity,
					'unit'     => 'selling',
					'reason'   => 'distribution',
				)
			);
		};

		$this->assertSame( 400, $move( 2 )->get_status() );
		$this->assertSame( 201, $move( -2 )->get_status() );
		$this->assertEquals( 3, OPENBB_Inventory::get_on_hand( $product_id ) );
	}
}
