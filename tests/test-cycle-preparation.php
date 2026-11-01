<?php
/**
 * Pre-order preparation: per-product methods, set-aside checks and basket packing.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Cycle_Preparation extends OPENBB_TestCase {

	private $actor;
	private $member;
	private $cycle;
	private $oats;
	private $honey;
	private $grains;

	public function set_up(): void {
		parent::set_up();
		$this->actor  = $this->make_user( 'coordinator' );
		$this->member = $this->make_user( 'member' );
		wp_set_current_user( $this->actor );
		rest_get_server();
		$this->cycle = new OPENBB_Cycle(
			array(
				'name'      => 'Prep cycle',
				'opens_at'  => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'closes_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertTrue( OPENBB_Cycles::store( $this->cycle, '', $this->actor ) );
		$this->grains = self::factory()->term->create( array( 'taxonomy' => OPENBB_PRODUCT_TAXONOMY, 'name' => 'Grains' ) );
		$this->oats   = $this->product( 'Oats', array( $this->grains ) );
		$this->honey  = $this->product( 'Honey', array(), 'basket' );
	}

	private function product( string $name, array $categories, string $method = '' ): OPENBB_Product {
		$id = self::factory()->post->create( array( 'post_type' => OPENBB_PRODUCT_POST_TYPE, 'post_status' => 'publish', 'post_title' => $name ) );
		update_post_meta( $id, OPENBB_META_PREFIX . 'active', true );
		update_post_meta( $id, OPENBB_META_PREFIX . 'price', 100 );
		update_post_meta( $id, OPENBB_META_PREFIX . 'prep_method', $method );
		wp_set_object_terms( $id, $categories, OPENBB_PRODUCT_TAXONOMY );
		$product = OPENBB_Product::get( $id );
		$this->assertInstanceOf( OPENBB_Stock_Move::class, OPENBB_Inventory::record_manual_move( $product, 10, 'receipt' ) );
		return $product;
	}

	private function stage( string $status ): void {
		$old                 = $this->cycle->status;
		$this->cycle->status = $status;
		$this->assertTrue( OPENBB_Cycles::store( $this->cycle, $old, $this->actor ) );
		$this->cycle = OPENBB_Cycles::get( $this->cycle->id );
	}

	private function order( array $quantities, string $status = 'submitted' ): OPENBB_Order {
		$order = OPENBB_Order_Editor::start( $this->cycle, $this->make_user( 'member' ) );
		$this->assertInstanceOf( OPENBB_Order::class, $order );
		$lines = array();
		foreach ( $quantities as $product_id => $qty ) {
			$lines[] = array( 'product_id' => $product_id, 'qty' => $qty );
		}
		$saved = OPENBB_Order_Editor::apply( $order, array( 'status' => $status, 'lines' => $lines ), $this->actor );
		$this->assertInstanceOf( OPENBB_Order::class, $saved );
		return $saved;
	}

	private function api( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/openbb/v1' . $route );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	private function items( array $data ): array {
		$items = array();
		foreach ( $data['sections'] as $section ) {
			foreach ( $section['items'] as $item ) {
				$items[ $item['id'] ] = $item;
			}
		}
		return $items;
	}

	public function test_methods_follow_product_then_default_and_freeze_when_processing_starts(): void {
		global $wpdb;
		$this->order( array( $this->oats->id => 2, $this->honey->id => 1 ) );
		$methods = OPENBB_Cycle_Preparation::methods_for( $this->cycle, array( $this->oats->id, $this->honey->id ) );
		$this->assertSame( array( $this->oats->id => 'station', $this->honey->id => 'basket' ), $methods );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_cycle_products}" ) );

		update_option( OPENBB_Settings::GENERAL_OPTION, array( 'default_prep_method' => 'basket' ) );
		$this->assertSame( 'basket', OPENBB_Cycle_Preparation::methods_for( $this->cycle, array( $this->oats->id ) )[ $this->oats->id ] );
		update_option( OPENBB_Settings::GENERAL_OPTION, array( 'default_prep_method' => 'station' ) );

		$this->stage( 'processing' );
		$this->assertSame( '2', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_cycle_products}" ) );
		update_post_meta( $this->honey->id, OPENBB_META_PREFIX . 'prep_method', 'station' );
		update_option( OPENBB_Settings::GENERAL_OPTION, array( 'default_prep_method' => 'basket' ) );
		$this->assertSame( $methods, OPENBB_Cycle_Preparation::methods_for( $this->cycle, array( $this->oats->id, $this->honey->id ) ) );
		$this->assertSame( 1, OPENBB_Cycle_Preparation::basket_products_count( $this->cycle ) );
	}

	public function test_overview_groups_by_category_and_reports_stock_and_shortages(): void {
		$this->order( array( $this->oats->id => 2, $this->honey->id => 3 ) );
		$this->order( array( $this->oats->id => 4 ) );
		$this->order( array( $this->oats->id => 1 ), 'draft' );
		$this->assertInstanceOf( OPENBB_Stock_Move::class, OPENBB_Inventory::record_manual_move( $this->oats, -5, 'spoilage' ) );
		$this->stage( 'processing' );

		$response = $this->api( 'GET', "/cycles/{$this->cycle->id}/preparation" );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'Grains', 'Other' ), wp_list_pluck( $data['sections'], 'name' ) );
		$this->assertTrue( $data['can_prepare'] );
		$this->assertTrue( $data['methods_frozen'] );

		$items = $this->items( $data );
		$oats  = $items[ $this->oats->id ];
		$this->assertEquals( 6, $oats['ordered_units'] );
		$this->assertSame( 2, $oats['orders_count'] );
		$this->assertEquals( 5, $oats['on_hand_units'] );
		$this->assertEquals( 6, $oats['cycle_reserved_units'] );
		$this->assertTrue( $oats['is_short'] );
		$this->assertEquals( 1, $oats['short_units'] );
		$this->assertSame( 'station', $oats['method'] );

		$honey = $items[ $this->honey->id ];
		$this->assertFalse( $honey['is_short'] );
		$this->assertSame( 'basket', $honey['method'] );
		$this->assertSame( array( 'products' => 2, 'prepared' => 0, 'changed' => 0, 'short' => 1, 'basket_products' => 1 ), $data['summary'] );

		$cycle = $this->api( 'GET', "/cycles/{$this->cycle->id}" )->get_data();
		$this->assertSame( 1, $cycle['basket_products_count'] );
	}

	public function test_products_in_several_categories_are_listed_once(): void {
		$seeds = self::factory()->term->create( array( 'taxonomy' => OPENBB_PRODUCT_TAXONOMY, 'name' => 'Seeds' ) );
		wp_set_object_terms( $this->oats->id, array( $this->grains, $seeds ), OPENBB_PRODUCT_TAXONOMY );
		$sections = OPENBB_Catalog::sections( array( OPENBB_Product::get( $this->oats->id ) ), true );
		$this->assertCount( 1, $sections );
		$this->assertSame( 'Grains', $sections[0]['name'] );
		$this->assertCount( 2, OPENBB_Catalog::sections( array( OPENBB_Product::get( $this->oats->id ) ) ) );
	}

	public function test_set_aside_needs_an_open_stage_and_detects_changed_orders(): void {
		$order = $this->order( array( $this->oats->id => 2 ) );
		$route = "/cycles/{$this->cycle->id}/preparation/{$this->oats->id}";
		$this->assertSame( 409, $this->api( 'POST', $route, array( 'prepared' => true ) )->get_status() );

		$this->stage( 'processing' );
		$this->assertSame( 409, $this->api( 'POST', $route, array( 'prepared' => true, 'expected_ordered' => 1 ) )->get_status() );
		$response = $this->api( 'POST', $route, array( 'prepared' => true, 'expected_ordered' => 2 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'prepared', $this->items( $response->get_data() )[ $this->oats->id ]['prepared_state'] );
		$this->assertSame( $this->actor, $this->items( $response->get_data() )[ $this->oats->id ]['prepared_by']['id'] );
		$this->assertEquals( 10, OPENBB_Inventory::get_on_hand( $this->oats->id ) );

		$this->order( array( $this->oats->id => 1 ) );
		$item = $this->items( $this->api( 'GET', "/cycles/{$this->cycle->id}/preparation" )->get_data() )[ $this->oats->id ];
		$this->assertSame( 'changed', $item['prepared_state'] );
		$this->assertEquals( 2, $item['prepared_units'] );

		$response = $this->api( 'POST', $route, array( 'prepared' => false ) );
		$this->assertSame( 'none', $this->items( $response->get_data() )[ $this->oats->id ]['prepared_state'] );
		$this->assertInstanceOf( OPENBB_Order::class, $order );
	}

	public function test_packing_locks_basket_lines_and_blocks_method_change(): void {
		$order = $this->order( array( $this->oats->id => 2, $this->honey->id => 1 ) );
		$this->stage( 'processing' );

		$request = new WP_REST_Request( 'GET', '/openbb/v1/orders' );
		$request->set_query_params( array( 'cycle_id' => $this->cycle->id ) );
		$item = rest_do_request( $request )->get_data()[0];
		$this->assertSame( 1, $item['basket_lines'] );
		$this->assertSame( 0, $item['basket_packed'] );
		$this->assertTrue( $item['can_pack'] );

		$stale = $this->api( 'POST', "/orders/{$order->id}/pack", array( 'packed' => true, 'expected_revision' => $order->revision - 1 ) );
		$this->assertSame( 409, $stale->get_status() );
		$response = $this->api( 'POST', "/orders/{$order->id}/pack", array( 'packed' => true, 'expected_revision' => $order->revision ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['basket_packed'] );
		$lines = array();
		foreach ( $response->get_data()['lines'] as $line ) {
			$lines[ $line['product_id'] ] = $line;
		}
		$this->assertTrue( $lines[ $this->honey->id ]['is_prepared'] );
		$this->assertFalse( $lines[ $this->oats->id ]['is_prepared'] );

		$method = $this->api( 'POST', "/cycles/{$this->cycle->id}/preparation/{$this->honey->id}", array( 'method' => 'station' ) );
		$this->assertSame( 409, $method->get_status() );

		$packed = OPENBB_Orders::get( $order->id );
		$this->assertSame( 200, $this->api( 'POST', "/orders/{$order->id}/pack", array( 'packed' => false, 'expected_revision' => $packed->revision ) )->get_status() );
		$this->assertSame( 200, $this->api( 'POST', "/cycles/{$this->cycle->id}/preparation/{$this->honey->id}", array( 'method' => 'station' ) )->get_status() );
		$this->assertSame( 0, OPENBB_Cycle_Preparation::basket_products_count( $this->cycle ) );
	}

	public function test_members_and_closed_stages_cannot_prepare(): void {
		$order = $this->order( array( $this->honey->id => 1 ) );
		wp_set_current_user( $this->member );
		$this->assertSame( 403, $this->api( 'GET', "/cycles/{$this->cycle->id}/preparation" )->get_status() );
		$this->assertSame( 403, $this->api( 'POST', "/orders/{$order->id}/pack", array( 'packed' => true, 'expected_revision' => $order->revision ) )->get_status() );

		wp_set_current_user( $this->actor );
		$this->assertSame( 409, $this->api( 'POST', "/orders/{$order->id}/pack", array( 'packed' => true, 'expected_revision' => $order->revision ) )->get_status() );
	}

	public function test_deleting_the_cycle_removes_preparation_rows(): void {
		global $wpdb;
		$this->order( array( $this->oats->id => 2 ) );
		$this->stage( 'processing' );
		$this->assertTrue( $this->cycle->delete( true ) );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_cycle_products}" ) );
	}

	public function test_product_prep_method_round_trips_through_the_api(): void {
		$response = $this->api( 'POST', "/products/{$this->oats->id}", array( 'prep_method' => 'basket' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'basket', $response->get_data()['prep_method'] );
		$this->assertSame( 400, $this->api( 'POST', "/products/{$this->oats->id}", array( 'prep_method' => 'shelf' ) )->get_status() );
		$this->assertSame( 200, $this->api( 'POST', "/products/{$this->oats->id}", array( 'prep_method' => '' ) )->get_status() );
		$this->assertSame( '', OPENBB_Product::get( $this->oats->id )->prep_method );
	}
}
