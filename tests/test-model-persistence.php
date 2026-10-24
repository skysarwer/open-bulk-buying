<?php
/**
 * Persistence primitives for orders, cycles, inventory and rules.
 *
 * @package Open_Bulk_Buying
 */

class Test_OPENBB_Model_Persistence extends OPENBB_TestCase {

	public function test_order_and_line_save_and_delete(): void {
		$user_id = $this->make_user( 'member' );
		$cycle   = new OPENBB_Cycle( array( 'name' => 'Persistence cycle' ) );
		$this->assertTrue( $cycle->save() );

		$order = new OPENBB_Order(
			array(
				'cycle_id' => $cycle->id,
				'user_id'  => $user_id,
				'donation' => 25,
			)
		);
		$this->assertTrue( $order->save() );
		$this->assertGreaterThan( 0, $order->id );

		$line = new OPENBB_Order_Line(
			array(
				'order_id'      => $order->id,
				'product_id'    => 123,
				'product_name'  => 'Sample item',
				'unit_price'    => 100,
				'qty_requested' => 2,
			)
		);
		$this->assertTrue( $line->save() );

		$saved_order = OPENBB_Orders::get( $order->id );
		$this->assertSame( 200, $saved_order->subtotal );
		$this->assertSame( 225, $saved_order->total );
		$this->assertCount( 1, $saved_order->get_lines() );

		$this->assertTrue( $order->delete() );
		$this->assertNull( OPENBB_Orders::get( $order->id ) );
		$this->assertSame( array(), OPENBB_Orders::get_lines( $order->id ) );
	}

	public function test_cycle_with_orders_cannot_be_deleted(): void {
		$user_id = $this->make_user( 'member' );
		$cycle   = new OPENBB_Cycle(
			array(
				'name'                => 'Cycle with order',
				'pickup_instructions' => array( 'default' => 'Front entrance' ),
			)
		);
		$this->assertTrue( $cycle->save() );
		$cycle->name = 'Updated cycle';
		$this->assertTrue( $cycle->save() );
		$this->assertSame( 'Updated cycle', OPENBB_Cycles::get( $cycle->id )->name );
		$this->assertSame( 'Front entrance', OPENBB_Cycles::get( $cycle->id )->pickup_instructions['default'] );

		$order = new OPENBB_Order( array( 'cycle_id' => $cycle->id, 'user_id' => $user_id ) );
		$this->assertTrue( $order->save() );

		$result = $cycle->delete();
		$this->assertWPError( $result );
		$this->assertSame( 'openbb_cycle_has_orders', $result->get_error_code() );
	}

	public function test_order_history_cannot_be_deleted(): void {
		$user_id = $this->make_user( 'member' );
		$cycle   = new OPENBB_Cycle( array( 'name' => 'Submitted order cycle' ) );
		$this->assertTrue( $cycle->save() );

		$order = new OPENBB_Order(
			array(
				'cycle_id' => $cycle->id,
				'user_id'  => $user_id,
				'status'   => OPENBB_Order::STATUS_SUBMITTED,
			)
		);
		$this->assertTrue( $order->save() );

		$result = $order->delete();
		$this->assertWPError( $result );
		$this->assertSame( 'openbb_order_not_deletable', $result->get_error_code() );
	}

	public function test_empty_cycles_can_be_deleted(): void {
		$cycle = new OPENBB_Cycle( array( 'name' => 'Empty cycle' ) );
		$this->assertTrue( $cycle->save() );
		$this->assertTrue( $cycle->delete() );
		$this->assertNull( OPENBB_Cycles::get( $cycle->id ) );
	}

	public function test_rules_draft_can_be_discarded_but_published_versions_remain(): void {
		$this->assertTrue( OPENBB_Rules::save_draft( array( 'default' => '<p>Initial rules</p>' ) ) );
		$version = OPENBB_Rules::publish( true, 1 );
		$this->assertInstanceOf( OPENBB_Rules_Version::class, $version );

		$this->assertTrue( OPENBB_Rules::save_draft( array( 'default' => '<p>Unpublished edit</p>' ) ) );
		$this->assertTrue( OPENBB_Rules::discard_draft() );
		$this->assertSame( '<p>Initial rules</p>', OPENBB_Rules::get_draft()[0]['default'] );
		$this->assertSame( $version->id, OPENBB_Rules::get_latest()->id );
	}

	public function test_stock_moves_are_append_only(): void {
		global $wpdb;

		$product_id = wp_insert_post(
			array(
				'post_type'   => OPENBB_PRODUCT_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Inventory persistence product',
			)
		);
		$move = new OPENBB_Stock_Move(
			array(
				'product_id' => $product_id,
				'quantity'   => 7.5,
				'reason'     => OPENBB_Stock_Move::REASON_RECEIPT,
			)
		);
		$this->assertTrue( $move->save() );
		$this->assertSame( 7.5, OPENBB_Inventory::get_on_hand( $product_id ) );

		$move->quantity = 4;
		$this->assertFalse( $move->save() );
		$this->assertSame( 7.5, OPENBB_Inventory::get_on_hand( $product_id ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_stock_moves} WHERE product_id = %d", $product_id ) ) );
	}

	public function test_demo_data_population_is_idempotent_and_requires_test_mode(): void {
		global $wpdb;

		wp_set_current_user( $this->make_user( 'manager' ) );
		delete_option( OPENBB_Maintenance::TEST_MODE_OPTION );
		$this->assertWPError( OPENBB_Maintenance::populate_test_data() );

		update_option( OPENBB_Maintenance::TEST_MODE_OPTION, true );
		$this->assertTrue( OPENBB_Maintenance::populate_test_data() );
		$this->assertTrue( OPENBB_Maintenance::populate_test_data() );

		$products = get_posts( array(
			'post_type' => OPENBB_PRODUCT_POST_TYPE,
			'posts_per_page' => -1,
			'meta_key' => '_openbb_demo_data',
			'meta_value' => '1',
		) );
		$this->assertCount( 41, $products );
		$this->assertCount( 11, get_terms( array( 'taxonomy' => OPENBB_PRODUCT_TAXONOMY, 'hide_empty' => false ) ) );
		$this->assertCount( 4, get_terms( array( 'taxonomy' => OPENBB_SUPPLIER_TAXONOMY, 'hide_empty' => false ) ) );
		$this->assertSame( 33, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_stock_moves}" ) );
		$rice = get_page_by_path( 'openbb-demo-brown-rice', OBJECT, OPENBB_PRODUCT_POST_TYPE );
		$this->assertSame( 500.0, OPENBB_Inventory::get_on_hand( $rice->ID ) );
		$this->assertSame( 10, OPENBB_Product::get( $rice->ID )->menu_order );
		$pantry = get_term_by( 'slug', 'openbb-demo-pantry', OPENBB_PRODUCT_TAXONOMY );
		$beans = get_term_by( 'slug', 'openbb-demo-beans', OPENBB_PRODUCT_TAXONOMY );
		$this->assertSame( $pantry->term_id, $beans->parent );
		$empty = get_term_by( 'slug', 'openbb-demo-seasonal', OPENBB_PRODUCT_TAXONOMY );
		$this->assertSame( 0, $empty->count );
		$units = array();
		$inactive = $out_of_stock = $container = 0;
		foreach ( $products as $post ) {
			$item = OPENBB_Product::get( $post->ID );
			$units[] = $item->unit_type;
			$inactive += ! $item->active;
			$out_of_stock += 0.0 === OPENBB_Inventory::get_on_hand( $post->ID );
			$container += $item->container_charge > 0;
		}
		$this->assertCount( 5, array_unique( $units ) );
		$this->assertSame( 4, $inactive );
		$this->assertSame( 8, $out_of_stock );
		$this->assertGreaterThan( 0, $container );
		wp_update_post( array( 'ID' => $rice->ID, 'menu_order' => 99 ) );
		update_post_meta( $rice->ID, OPENBB_META_PREFIX . 'price', 123 );
		update_term_meta( $pantry->term_id, OPENBB_META_PREFIX . 'order', 99 );
		$this->assertTrue( OPENBB_Maintenance::populate_test_data() );
		$this->assertSame( 99, OPENBB_Product::get( $rice->ID )->menu_order );
		$this->assertSame( 123, OPENBB_Product::get( $rice->ID )->price );
		$this->assertSame( 99, (int) get_term_meta( $pantry->term_id, OPENBB_META_PREFIX . 'order', true ) );
		$this->assertSame( 33, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_stock_moves}" ) );

		$product = get_page_by_path( OPENBB_Maintenance::DEMO_PRODUCT_SLUG, OBJECT, OPENBB_PRODUCT_POST_TYPE );
		$this->assertInstanceOf( WP_Post::class, $product );
		$this->assertSame( '1', get_post_meta( $product->ID, '_openbb_demo_data', true ) );
		$this->assertSame(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->openbb_cycles} WHERE name = %s",
					OPENBB_Maintenance::DEMO_CYCLE_NAME
				)
			)
		);
		$this->assertSame( 1, count( OPENBB_Inventory::get_moves( $product->ID ) ) );
	}

	public function test_demo_catalog_does_not_modify_unmarked_terms(): void {
		wp_set_current_user( $this->make_user( 'manager' ) );
		update_option( OPENBB_Maintenance::TEST_MODE_OPTION, true );
		$term = wp_insert_term( 'Real pantry', OPENBB_PRODUCT_TAXONOMY, array( 'slug' => 'openbb-demo-pantry' ) );
		$result = OPENBB_Maintenance::populate_test_data();
		$this->assertWPError( $result );
		$this->assertSame( 'openbb_demo_term_conflict', $result->get_error_code() );
		$this->assertSame( 'Real pantry', get_term( $term['term_id'] )->name );
		$this->assertFalse( metadata_exists( 'term', $term['term_id'], '_openbb_demo_data' ) );
	}

	public function test_delete_all_site_data_preserves_schema_and_wordpress_accounts(): void {
		global $wpdb;

		$user_id = $this->make_user( 'member' );
		update_option( OPENBB_Maintenance::TEST_MODE_OPTION, true );
		$product_id = wp_insert_post(
			array(
				'post_type'   => OPENBB_PRODUCT_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Wipe test product',
			)
		);
		$this->assertGreaterThan( 0, $product_id );

		$order = new OPENBB_Order(
			array( 'cycle_id' => $this->make_cycle( 'setup' ), 'user_id' => $user_id )
		);
		$this->assertTrue( $order->save() );
		$this->assertTrue( OPENBB_Orders::record_event( $order, array( 'actor_id' => $user_id, 'event_type' => 'created', 'from_status' => '' ) ) );
		$this->assertTrue( OPENBB_Maintenance::delete_all_site_data() );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_orders}" ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_order_lines}" ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_order_events}" ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_stock_moves}" ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_cycles}" ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->openbb_rules_versions}" ) );
		$this->assertNull( get_post( $product_id ) );
		$this->assertSame( '', OPENBB_Access::get_status( $user_id ) );
		$this->assertFalse( OPENBB_Maintenance::is_test_mode() );
		$this->assertSame( OPENBB_Installer::DB_VERSION, get_option( OPENBB_Installer::DB_VERSION_OPTION ) );
		$this->assertNotNull( get_userdata( $user_id ) );
	}
}
