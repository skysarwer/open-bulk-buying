<?php
/**
 * The member order form's backend: saving a member's own basket, the catalog and the
 * REST route.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Member_Order extends OPENBB_TestCase {

	/** @var int */
	private $coordinator;

	/** @var int */
	private $member;

	/** @var OPENBB_Cycle */
	private $cycle;

	/** @var OPENBB_Product */
	private $oats;

	/** @var OPENBB_Product */
	private $jars;

	public function set_up(): void {
		parent::set_up();
		$this->coordinator = $this->make_user( 'coordinator' );
		$this->member      = $this->make_user( 'member' );
		wp_set_current_user( $this->coordinator );

		$this->oats = $this->product( 'Oats', 250, 0, 10 );
		$this->jars = $this->product( 'Honey', 900, 100, 4 );

		$this->cycle = $this->cycle( OPENBB_Cycle::STATUS_ORDERING, '-1 day', '+1 day' );
		wp_set_current_user( $this->member );
	}

	private function product( string $name, int $price, int $charge, float $stock, array $args = array() ): OPENBB_Product {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => OPENBB_PRODUCT_POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $name,
				),
				$args
			)
		);
		update_post_meta( $id, OPENBB_META_PREFIX . 'active', true );
		update_post_meta( $id, OPENBB_META_PREFIX . 'price', $price );
		update_post_meta( $id, OPENBB_META_PREFIX . 'container_charge', $charge );
		$product = OPENBB_Product::get( $id );
		if ( $stock > 0 ) {
			$this->assertInstanceOf( OPENBB_Stock_Move::class, OPENBB_Inventory::record_manual_move( $product, $stock, 'receipt' ) );
		}

		return $product;
	}

	private function cycle( string $status, string $opens, string $closes ): OPENBB_Cycle {
		$cycle = new OPENBB_Cycle(
			array(
				'name'      => 'Spring',
				'status'    => $status,
				'opens_at'  => gmdate( 'Y-m-d H:i:s', strtotime( $opens ) ),
				'closes_at' => gmdate( 'Y-m-d H:i:s', strtotime( $closes ) ),
			)
		);
		$this->assertTrue( OPENBB_Cycles::store( $cycle, '', $this->coordinator ) );

		return $cycle;
	}

	private function save( array $input, ?int $user_id = null ) {
		return OPENBB_Member_Orders::save( $this->cycle, $user_id ?? $this->member, $input );
	}

	private function line( OPENBB_Product $product, $qty, bool $own = false ): array {
		return array(
			'product_id'    => $product->id,
			'qty'           => $qty,
			'own_container' => $own,
		);
	}

	private function assert_error( string $code, $result, ?string $field = null ): void {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		if ( $field ) {
			$this->assertArrayHasKey( $field, $result->get_error_data()['errors'] );
		}
	}

	public function test_empty_basket_saves_nothing_until_something_is_added(): void {
		$this->assertNull( $this->save( array( 'lines' => array() ) ) );
		$this->assertNull( OPENBB_Orders::get_for_member( $this->cycle->id, $this->member ) );

		$order = $this->save( array( 'lines' => array( $this->line( $this->oats, 2 ), $this->line( $this->jars, 1, true ) ) ) );
		$this->assertInstanceOf( OPENBB_Order::class, $order );
		$this->assertSame( OPENBB_Order::STATUS_DRAFT, $order->status );

		$state = OPENBB_Member_Orders::state( $order, $this->cycle );
		$this->assertTrue( $state['editable'] );
		$this->assertSame( array( 2, 1 ), wp_list_pluck( $state['lines'], 'qty' ) );
		$this->assertSame( 2 * 250 + 1000, $state['subtotal'] );
		$this->assertSame( 100, $state['credit'] );

		// Dropping a product from the basket removes its draft line.
		$order = $this->save(
			array(
				'lines'             => array( $this->line( $this->oats, 3 ) ),
				'expected_revision' => $order->revision,
			)
		);
		$this->assertSame( array( $this->oats->id ), wp_list_pluck( OPENBB_Member_Orders::state( $order, $this->cycle )['lines'], 'product_id' ) );
	}

	public function test_quantities_must_be_whole_units_without_duplicates(): void {
		$this->assert_error( 'openbb_invalid_order', $this->save( array( 'lines' => array( $this->line( $this->oats, 1.5 ) ) ) ), 'lines' );
		$this->assert_error( 'openbb_invalid_order', $this->save( array( 'lines' => array( $this->line( $this->oats, -1 ) ) ) ), 'lines' );
		$this->assert_error( 'openbb_invalid_order', $this->save( array( 'lines' => array( $this->line( $this->oats, 1 ), $this->line( $this->oats, 2 ) ) ) ), 'lines' );
	}

	public function test_submitting_needs_items_and_the_commitment(): void {
		$this->assert_error( 'openbb_invalid_order', $this->save( array( 'submit' => true, 'commit' => true ) ), 'lines' );
		$this->assert_error(
			'openbb_invalid_order',
			$this->save(
				array(
					'lines'  => array( $this->line( $this->oats, 1 ) ),
					'submit' => true,
				)
			),
			'commit'
		);

		$order = $this->save(
			array(
				'lines'       => array( $this->line( $this->oats, 1 ) ),
				'member_note' => 'Back door please',
				'submit'      => true,
				'commit'      => true,
			)
		);
		$this->assertInstanceOf( OPENBB_Order::class, $order );
		$this->assertSame( OPENBB_Order::STATUS_SUBMITTED, $order->status );
		$this->assertSame( 'Back door please', $order->member_note );

		// A submitted order keeps autosaving changes but can't be emptied.
		$order = $this->save( array( 'lines' => array( $this->line( $this->oats, 4 ) ) ) );
		$this->assertSame( OPENBB_Order::STATUS_SUBMITTED, $order->status );
		$this->assert_error( 'openbb_invalid_order', $this->save( array( 'lines' => array() ) ), 'lines' );
	}

	public function test_submitting_reserves_stock_and_reports_what_is_left(): void {
		$result = $this->save(
			array(
				'lines'  => array( $this->line( $this->jars, 5 ) ),
				'submit' => true,
				'commit' => true,
			)
		);
		$this->assert_error( 'openbb_insufficient_stock', $result );
		$this->assertSame( $this->jars->id, $result->get_error_data()['product_id'] );
		$this->assertEquals( 4, $result->get_error_data()['max_qty'] );

		$this->assertSame( array( $this->jars->id => 4 ), array_intersect_key( OPENBB_Member_Orders::limits( array( $this->jars ), null ), array( $this->jars->id => true ) ) );
	}

	public function test_only_active_members_in_an_open_cycle_can_save(): void {
		$this->assert_error( 'openbb_not_member', $this->save( array( 'lines' => array( $this->line( $this->oats, 1 ) ) ), $this->make_user( 'applicant' ) ) );

		$this->cycle = $this->cycle( OPENBB_Cycle::STATUS_ORDERING, '-3 days', '-2 days' );
		$this->assert_error( 'openbb_ordering_closed', $this->save( array( 'lines' => array( $this->line( $this->oats, 1 ) ) ) ) );
	}

	public function test_stale_revision_is_refused(): void {
		$order = $this->save( array( 'lines' => array( $this->line( $this->oats, 1 ) ) ) );
		$this->assert_error(
			'openbb_order_changed',
			$this->save(
				array(
					'lines'             => array( $this->line( $this->oats, 2 ) ),
					'expected_revision' => $order->revision - 1,
				)
			)
		);
	}

	public function test_aid_request_is_a_flag_with_a_reason(): void {
		$this->assert_error(
			'openbb_invalid_order',
			$this->save(
				array(
					'lines'       => array( $this->line( $this->oats, 2 ) ),
					'aid_request' => true,
				)
			),
			'aid_reason'
		);

		$order = $this->save(
			array(
				'lines'       => array( $this->line( $this->oats, 2 ) ),
				'aid_request' => true,
				'aid_reason'  => 'Short this month',
				'donation'    => 300,
			)
		);
		$this->assertSame( OPENBB_Order::AID_PENDING, $order->aid_status );
		$this->assertSame( 'Short this month', $order->aid_reason );
		$this->assertSame( 0, $order->aid_requested );
		$this->assertSame( 300, $order->donation );

		$state = OPENBB_Member_Orders::state( $order, $this->cycle );
		// Pending aid isn't taken off the total.
		$this->assertSame( 800, $state['total'] );
		$this->assertArrayNotHasKey( 'aid_requested', $state );

		$order = $this->save(
			array(
				'lines'       => array( $this->line( $this->oats, 2 ) ),
				'aid_request' => false,
			)
		);
		$this->assertSame( OPENBB_Order::AID_NONE, $order->aid_status );
		$this->assertSame( '', $order->aid_reason );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'openbb_orders',
			array(
				'aid_status'   => OPENBB_Order::AID_APPROVED,
				'aid_reason'   => 'Short this month',
				'aid_approved' => 200,
			),
			array( 'id' => $order->id )
		);
		$this->assert_error(
			'openbb_aid_decided',
			$this->save(
				array(
					'lines'       => array( $this->line( $this->oats, 2 ) ),
					'aid_request' => false,
				)
			)
		);
	}

	public function test_catalog_groups_products_by_ordered_categories(): void {
		wp_set_current_user( $this->coordinator );
		$pantry = self::factory()->term->create( array( 'taxonomy' => OPENBB_PRODUCT_TAXONOMY, 'name' => 'Pantry' ) );
		$baking = self::factory()->term->create( array( 'taxonomy' => OPENBB_PRODUCT_TAXONOMY, 'name' => 'Baking', 'parent' => $pantry ) );
		$fresh  = self::factory()->term->create( array( 'taxonomy' => OPENBB_PRODUCT_TAXONOMY, 'name' => 'Fresh' ) );
		$empty  = self::factory()->term->create( array( 'taxonomy' => OPENBB_PRODUCT_TAXONOMY, 'name' => 'Empty' ) );
		update_term_meta( $fresh, OPENBB_META_PREFIX . 'order', 1 );
		update_term_meta( $pantry, OPENBB_META_PREFIX . 'order', 2 );
		update_term_meta( $empty, OPENBB_META_PREFIX . 'order', 0 );

		$flour    = $this->product( 'Flour', 300, 0, 0, array( 'menu_order' => 2 ) );
		$sugar    = $this->product( 'Sugar', 300, 0, 0, array( 'menu_order' => 1 ) );
		$apples   = $this->product( 'Apples', 300, 0, 0 );
		$inactive = $this->product( 'Old stock', 300, 0, 0 );
		update_post_meta( $inactive->id, OPENBB_META_PREFIX . 'active', false );
		wp_set_object_terms( $flour->id, array( $baking ), OPENBB_PRODUCT_TAXONOMY );
		wp_set_object_terms( $sugar->id, array( $baking, $fresh ), OPENBB_PRODUCT_TAXONOMY );
		wp_set_object_terms( $apples->id, array( $fresh ), OPENBB_PRODUCT_TAXONOMY );
		wp_set_object_terms( $inactive->id, array( $empty ), OPENBB_PRODUCT_TAXONOMY );

		$sections = OPENBB_Catalog::sections();

		$this->assertSame( array( 'Fresh', 'Pantry', 'Baking', 'Other' ), wp_list_pluck( $sections, 'name' ) );
		$this->assertSame( array( 0, 0, 1, 0 ), wp_list_pluck( $sections, 'depth' ) );
		$this->assertSame( array( $apples->id, $sugar->id ), wp_list_pluck( $sections[0]['products'], 'id' ) );
		$this->assertSame( array(), $sections[1]['products'] );
		$this->assertSame( array( $sugar->id, $flour->id ), wp_list_pluck( $sections[2]['products'], 'id' ) );
		// The set-up products have no category.
		$this->assertEqualSets( array( $this->oats->id, $this->jars->id ), wp_list_pluck( $sections[3]['products'], 'id' ) );
	}

	public function test_rest_route_saves_and_reads_the_members_own_order(): void {
		rest_get_server();

		$request = new WP_REST_Request( 'POST', '/openbb/v1/order' );
		$request->set_body_params(
			array(
				'cycle_id' => $this->cycle->id,
				'lines'    => array( $this->line( $this->oats, 3 ) ),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( OPENBB_Order::STATUS_DRAFT, $response->get_data()['status'] );
		$this->assertSame( 3, $response->get_data()['lines'][0]['qty'] );

		$request  = new WP_REST_Request( 'GET', '/openbb/v1/order' );
		$request->set_query_params( array( 'cycle_id' => $this->cycle->id ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 750, $response->get_data()['subtotal'] );

		$request = new WP_REST_Request( 'POST', '/openbb/v1/order' );
		$request->set_body_params(
			array(
				'cycle_id' => $this->cycle->id,
				'lines'    => array( $this->line( $this->oats, 1000 ) ),
			)
		);
		$this->assertSame( 400, rest_do_request( $request )->get_status() );

		wp_set_current_user( $this->make_user( 'applicant' ) );
		$request = new WP_REST_Request( 'GET', '/openbb/v1/order' );
		$request->set_query_params( array( 'cycle_id' => $this->cycle->id ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	public function test_staff_can_preview_and_place_orders_during_setup(): void {
		$this->cycle->status = OPENBB_Cycle::STATUS_SETUP;
		$this->assertTrue( OPENBB_Cycles::store( $this->cycle, OPENBB_Cycle::STATUS_ORDERING, $this->coordinator ) );
		$this->cycle = OPENBB_Cycles::get( $this->cycle->id );

		wp_set_current_user( $this->coordinator );
		$html = OPENBB_Order_Form_View::render();
		$this->assertStringContainsString( 'openbb-order__topbar', $html );
		$this->assertStringContainsString( 'Staff preview: your role lets you place and edit orders during Setup', $html );

		$request = new WP_REST_Request( 'POST', '/openbb/v1/order' );
		$request->set_body_params(
			array(
				'cycle_id' => $this->cycle->id,
				'lines'    => array( $this->line( $this->oats, 2 ) ),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( OPENBB_Order::STATUS_DRAFT, $response->get_data()['status'] );

		wp_set_current_user( $this->member );
		$this->assertSame( array(), OPENBB_Member_Orders::open_cycles() );
		$member_form = OPENBB_Order_Form_View::render();
		$this->assertStringContainsString( 'There is no ordering period open right now.', $member_form );
		$this->assertStringNotContainsString( 'openbb-order__topbar', $member_form );
	}
}
