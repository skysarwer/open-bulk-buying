<?php
/**
 * Repeatable catalog fixtures for order-form layout testing.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Demo_Catalog {

	/**
	 * Existing demo records keep staff edits. Missing fixtures are added on reruns.
	 *
	 * @return true|WP_Error
	 */
	public static function populate( int $user_id ) {
		global $wpdb;

		if ( ! OPENBB_Maintenance::is_test_mode() ) {
			return new WP_Error( 'openbb_test_mode_disabled', __( 'Enable test mode before populating demo data.', 'open-bulk-buying' ) );
		}

		$categories = array();
		$groups     = self::groups();
		foreach ( $groups as $key => $group ) {
			$parent = isset( $group['parent'] ) ? $categories[ $group['parent'] ] : 0;
			$id     = self::term( $key, $group['name'], OPENBB_PRODUCT_TAXONOMY, $parent, ( count( $categories ) + 1 ) * 10 );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$categories[ $key ] = $id;
		}
		$empty = self::term( 'seasonal', 'Seasonal specials (empty)', OPENBB_PRODUCT_TAXONOMY, 0, 110 );
		if ( is_wp_error( $empty ) ) {
			return $empty;
		}

		$suppliers = array();
		foreach ( array( 'farm' => 'Community Farm', 'mill' => 'Valley Mill', 'pantry' => 'Cooperative Pantry', 'refill' => 'Refill Workshop' ) as $key => $name ) {
			$id = self::term( $key, $name, OPENBB_SUPPLIER_TAXONOMY );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$suppliers[] = $id;
		}

		$index = 0;
		foreach ( $groups as $key => $group ) {
			foreach ( $group['products'] as $position => $row ) {
				++$index;
				list( $slug, $name, $amount, $unit, $price ) = $row;
				$slug = 'openbb-demo-' . $slug;
				$post = get_page_by_path( $slug, OBJECT, OPENBB_PRODUCT_POST_TYPE );
				if ( $post && '1' !== get_post_meta( $post->ID, '_openbb_demo_data', true ) ) {
					return new WP_Error( 'openbb_demo_product_conflict', __( 'A non-demo product is already using a demo product identifier.', 'open-bulk-buying' ) );
				}

				if ( ! $post ) {
					$description = 0 === $index % 5 ? '' : '<p>Demo catalog item for layout testing. Sold per listed unit; bring a clean container where applicable.</p>';
					if ( 0 === $index % 7 ) {
						$description .= '<p><strong>Storage and handling:</strong> Keep in a cool, dry place. This longer description tests wrapping, expanded details, and products with several paragraphs of information.</p><ul><li>Packaged by the co-op.</li><li>Check the label for ingredients and allergens.</li></ul>';
					}
					$data = array(
						'name'             => '[DEMO] ' . $name,
						'description'      => $description,
						'brand'            => 0 === $index % 6 ? '' : array( 'Field & Fork', 'Valley Organics', 'Community Kitchen', 'Refill Collective' )[ $index % 4 ],
						'unit_amount'      => $amount,
						'unit_type'        => $unit,
						'price'            => $price,
						'container_charge' => 0 === $index % 3 ? 75 : 0,
						'active'           => 0 !== $index % 9,
						'category_ids'     => 40 === $index ? array() : array( $categories[ $key ] ),
						'supplier_id'      => 0 === $index % 8 ? 0 : $suppliers[ $index % 4 ],
						'menu_order'       => ( $position + 1 ) * 10,
					);
					if ( 0 === $index % 7 ) {
						$data['category_ids'][] = $categories['pantry'];
					}
					$request = new WP_REST_Request( 'POST' );
					$request->set_body_params( $data );
					$result = ( new OPENBB_REST_Products() )->create_item( $request );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					$product_id = (int) $result->get_data()['id'];
					$result     = wp_update_post( array( 'ID' => $product_id, 'post_name' => $slug ), true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					if ( ! update_post_meta( $product_id, '_openbb_demo_data', '1' ) ) {
						return new WP_Error( 'openbb_demo_product_failed', __( 'The demo product could not be marked.', 'open-bulk-buying' ) );
					}
				} else {
					$product_id = (int) $post->ID;
				}

				$stock_key = $slug . '-stock-v1';
				$move_id   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->openbb_stock_moves} WHERE request_key = %s", $stock_key ) );
				$quantity  = array( 0, 0.5, 2, 25, 100 )[ $index % 5 ];
				if ( ! $move_id && $quantity > 0 ) {
					$product = OPENBB_Product::get( $product_id );
					if ( ! $product ) {
						return new WP_Error( 'openbb_demo_product_failed', __( 'The demo product could not be loaded.', 'open-bulk-buying' ) );
					}
					$move = new OPENBB_Stock_Move(
						array(
							'product_id'  => $product_id,
							'quantity'    => $quantity * $product->get_base_per_unit(),
							'unit_amount' => $product->unit_amount,
							'unit_type'   => $product->unit_type,
							'reason'      => OPENBB_Stock_Move::REASON_RECEIPT,
							'user_id'     => $user_id,
							'request_key' => $stock_key,
							'note'        => __( 'Generated demo stock receipt.', 'open-bulk-buying' ),
						)
					);
					if ( ! $move->save() ) {
						return new WP_Error( 'openbb_demo_stock_failed', __( 'The demo stock receipt could not be populated.', 'open-bulk-buying' ) );
					}
				}
			}
		}
		return true;
	}

	/** @return int|WP_Error */
	private static function term( string $key, string $name, string $taxonomy, int $parent = 0, int $order = 0 ) {
		$slug = 'openbb-demo-' . $key;
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term ) {
			if ( '1' !== get_term_meta( $term->term_id, '_openbb_demo_data', true ) ) {
				return new WP_Error( 'openbb_demo_term_conflict', __( 'A non-demo category or supplier is already using a demo identifier.', 'open-bulk-buying' ) );
			}
			return (int) $term->term_id;
		}
		$result = wp_insert_term( '[DEMO] ' . $name, $taxonomy, array( 'slug' => $slug, 'parent' => $parent ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = (int) $result['term_id'];
		$marked = update_term_meta( $id, '_openbb_demo_data', '1' );
		if ( is_wp_error( $marked ) || ! $marked ) {
			return new WP_Error( 'openbb_demo_term_failed', __( 'The demo category or supplier could not be marked.', 'open-bulk-buying' ) );
		}
		if ( OPENBB_PRODUCT_TAXONOMY === $taxonomy ) {
			$saved = update_term_meta( $id, OPENBB_META_PREFIX . 'order', $order );
			if ( is_wp_error( $saved ) || (int) get_term_meta( $id, OPENBB_META_PREFIX . 'order', true ) !== $order ) {
				return new WP_Error( 'openbb_demo_term_failed', __( 'The demo category order could not be saved.', 'open-bulk-buying' ) );
			}
		}
		return $id;
	}

	private static function groups(): array {
		return array(
			'pantry' => array( 'name' => 'Pantry staples', 'products' => array(
				array( 'brown-rice', 'Brown rice', 1, 'kg', 425 ),
				array( 'rolled-oats', 'Rolled oats', 500, 'g', 240 ),
				array( 'quinoa', 'Tri-colour quinoa', 250, 'g', 575 ),
				array( 'pasta', 'Whole-wheat fusilli pasta', 1, 'kg', 650 ),
			) ),
			'beans' => array( 'name' => 'Beans and lentils', 'parent' => 'pantry', 'products' => array(
				array( 'chickpeas', 'Dried chickpeas', 500, 'g', 325 ),
				array( 'red-lentils', 'Split red lentils', 1, 'kg', 590 ),
				array( 'black-beans', 'Black turtle beans', 250, 'g', 210 ),
				array( 'kidney-beans', 'Red kidney beans', 1, 'kg', 620 ),
			) ),
			'flour' => array( 'name' => 'Flour and baking', 'parent' => 'pantry', 'products' => array(
				array( 'bread-flour', 'Stone-ground whole-wheat bread flour', 1, 'kg', 375 ),
				array( 'rye-flour', 'Dark rye flour', 500, 'g', 295 ),
				array( 'baking-soda', 'Baking soda', 100, 'g', 80 ),
				array( 'cocoa', 'Fair-trade unsweetened cocoa powder for baking and hot chocolate', 50, 'g', 420 ),
			) ),
			'produce' => array( 'name' => 'Fresh fruit and vegetables', 'products' => array(
				array( 'carrots', 'Carrots', 1, 'kg', 300 ),
				array( 'apples', 'Mixed orchard apples', 1.5, 'kg', 525 ),
				array( 'kale', 'Kale bunch', 1, 'unit', 275 ),
				array( 'garlic', 'Garlic bulb', 1, 'unit', 125 ),
			) ),
			'nuts' => array( 'name' => 'Nuts, seeds and dried fruit', 'products' => array(
				array( 'almonds', 'Raw almonds', 100, 'g', 325 ),
				array( 'pumpkin-seeds', 'Pumpkin seeds', 250, 'g', 450 ),
				array( 'raisins', 'Thompson raisins', 500, 'g', 550 ),
				array( 'dates', 'Soft Medjool dates', 250, 'g', 675 ),
			) ),
			'oils' => array( 'name' => 'Oils, vinegars and spreads', 'products' => array(
				array( 'olive-oil', 'Extra virgin olive oil', 500, 'ml', 1250 ),
				array( 'sunflower-oil', 'Sunflower oil', 1, 'l', 750 ),
				array( 'apple-vinegar', 'Unfiltered apple cider vinegar', 250, 'ml', 395 ),
				array( 'peanut-butter', 'Smooth peanut butter', 500, 'g', 625 ),
			) ),
			'herbs' => array( 'name' => 'Herbs, spices and seasonings', 'products' => array(
				array( 'cumin', 'Ground cumin', 50, 'g', 195 ),
				array( 'oregano', 'Dried oregano', 25, 'g', 175 ),
				array( 'sea-salt', 'Fine sea salt', 100, 'g', 65 ),
				array( 'pepper', 'Whole black peppercorns', 50, 'g', 310 ),
			) ),
			'drinks' => array( 'name' => 'Tea, coffee and drinks', 'products' => array(
				array( 'coffee', 'Co-op medium roast coffee beans', 250, 'g', 950 ),
				array( 'tea', 'Loose-leaf breakfast tea', 100, 'g', 725 ),
				array( 'herbal-tea', 'Caffeine-free chamomile and lemon balm herbal tea blend', 50, 'g', 550 ),
				array( 'apple-juice', 'Cloudy apple juice', 1, 'l', 495 ),
			) ),
			'chilled' => array( 'name' => 'Chilled and refrigerated', 'products' => array(
				array( 'eggs', 'Free-range eggs (six)', 6, 'unit', 450 ),
				array( 'yogurt', 'Plain whole-milk yogurt', 500, 'g', 425 ),
				array( 'tofu', 'Firm tofu', 250, 'g', 350 ),
				array( 'oat-drink', 'Unsweetened oat drink', 1, 'l', 325 ),
			) ),
			'household' => array( 'name' => 'Household refills and reusable essentials', 'products' => array(
				array( 'dish-soap', 'Unscented washing-up liquid', 500, 'ml', 395 ),
				array( 'laundry', 'Concentrated laundry liquid', 1, 'l', 895 ),
				array( 'hand-soap', 'Gentle hand soap refill', 250, 'ml', 275 ),
				array( 'paper-bag', 'Community reuse paper bag', 1, 'unit', 0 ),
			) ),
		);
	}
}
