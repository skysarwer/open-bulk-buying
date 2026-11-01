<?php
/**
 * Pre-order preparation for a cycle.
 *
 * Every pre-ordered product is gathered from stock ("set aside"); products prepared
 * with the basket method are then also packed into each member's order. The method
 * comes from the product (or the co-op default) and is frozen per cycle once the
 * cycle reaches processing, so later catalogue edits don't change a cycle in progress.
 *
 * Preparation records no stock moves: stock leaves inventory at handoff
 * (OPENBB_Order_Lifecycle). Quantities are inventory base units.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

class OPENBB_Cycle_Preparation {

	const METHOD_STATION = 'station';
	const METHOD_BASKET  = 'basket';
	const METHODS        = array( self::METHOD_STATION, self::METHOD_BASKET );

	/** Stages in which preparation can be recorded. */
	const STAGES = array( OPENBB_Cycle::STATUS_PROCESSING, OPENBB_Cycle::STATUS_DISTRIBUTION );

	public static function schema(): array {
		global $wpdb;

		return array(
			$wpdb->openbb_cycle_products => "CREATE TABLE {$wpdb->openbb_cycle_products} (
				id bigint(20) unsigned NOT NULL auto_increment,
				cycle_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				prep_method varchar(20) NOT NULL default '',
				prepared_qty decimal(12,3) NULL default NULL,
				prepared_at datetime NULL default NULL,
				prepared_by bigint(20) unsigned NULL default NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY cycle_product (cycle_id,product_id),
				KEY product_id (product_id)
			) {$wpdb->get_charset_collate()};",
		);
	}

	public static function method_labels(): array {
		return array(
			self::METHOD_STATION => __( 'Set aside by product', 'open-bulk-buying' ),
			self::METHOD_BASKET  => __( 'Pack into member baskets', 'open-bulk-buying' ),
		);
	}

	public static function sanitize_method( $value ): string {
		return in_array( $value, self::METHODS, true ) ? $value : '';
	}

	public static function default_method(): string {
		$method = self::sanitize_method( OPENBB_Settings::get_general()['default_prep_method'] ?? '' );
		return '' === $method ? self::METHOD_STATION : $method;
	}

	public static function is_frozen( OPENBB_Cycle $cycle ): bool {
		return ! in_array( $cycle->status, array( OPENBB_Cycle::STATUS_SETUP, OPENBB_Cycle::STATUS_ORDERING ), true );
	}

	/**
	 * Freezes the methods of the cycle's pre-ordered products when processing starts.
	 *
	 * Hooked to 'openbb_cycle_status_changed'.
	 */
	public static function on_cycle_status_changed( OPENBB_Cycle $cycle, string $old_status ): void {
		if ( OPENBB_Cycle::STATUS_PROCESSING === $cycle->status ) {
			self::methods_for( $cycle, array_keys( OPENBB_Inventory::get_cycle_demand( $cycle->id ) ) );
		}
	}

	/**
	 * Each product's preparation method in $cycle. Once the cycle is past ordering,
	 * missing methods are frozen from the product (or co-op default).
	 *
	 * @param int[] $product_ids
	 * @return array<int, string> Product id => method.
	 */
	public static function methods_for( OPENBB_Cycle $cycle, array $product_ids ): array {
		global $wpdb;

		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );
		if ( ! $product_ids ) {
			return array();
		}
		$rows    = self::rows( $cycle->id );
		$default = self::default_method();
		$frozen  = self::is_frozen( $cycle );
		$now     = current_time( 'mysql', true );
		$methods = array();
		foreach ( $product_ids as $product_id ) {
			$method = self::sanitize_method( $rows[ $product_id ]['prep_method'] ?? '' );
			if ( '' === $method ) {
				$method = self::sanitize_method( get_post_meta( $product_id, OPENBB_META_PREFIX . 'prep_method', true ) );
				$method = '' === $method ? $default : $method;
				if ( $frozen ) {
					$wpdb->query(
						$wpdb->prepare(
							"INSERT INTO {$wpdb->openbb_cycle_products} (cycle_id, product_id, prep_method, updated_at) VALUES (%d, %d, %s, %s)
							ON DUPLICATE KEY UPDATE prep_method = IF(prep_method = '', VALUES(prep_method), prep_method)",
							$cycle->id,
							$product_id,
							$method,
							$now
						)
					);
				}
			}
			$methods[ $product_id ] = $method;
		}

		return $methods;
	}

	/**
	 * @return array<int, array> Product id => stored row.
	 */
	public static function rows( int $cycle_id ): array {
		global $wpdb;

		$rows = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->openbb_cycle_products} WHERE cycle_id = %d", $cycle_id ), ARRAY_A ) as $row ) {
			$rows[ (int) $row['product_id'] ] = $row;
		}
		return $rows;
	}

	/**
	 * Number of pre-ordered products packed into baskets in $cycle.
	 */
	public static function basket_products_count( OPENBB_Cycle $cycle ): int {
		return count( array_keys( self::methods_for( $cycle, array_keys( OPENBB_Inventory::get_cycle_demand( $cycle->id ) ) ), self::METHOD_BASKET, true ) );
	}

	/**
	 * Marks a product as set aside for the cycle, or clears it. $expected_ordered is the
	 * ordered quantity the user saw; a mismatch means orders changed in the meantime.
	 *
	 * @return true|WP_Error
	 */
	public static function set_prepared( int $cycle_id, int $product_id, bool $prepared, ?float $expected_ordered, int $actor_id ) {
		global $wpdb;

		if ( ! OPENBB_Inventory::lock() ) {
			return self::busy();
		}
		try {
			$cycle = OPENBB_Cycles::get( $cycle_id );
			if ( ! $cycle ) {
				return new WP_Error( 'openbb_cycle_not_found', __( 'Cycle not found.', 'open-bulk-buying' ), array( 'status' => 404 ) );
			}
			if ( ! in_array( $cycle->status, self::STAGES, true ) ) {
				return new WP_Error( 'openbb_preparation_closed', __( 'Products can be set aside only while the cycle is processing or distributing.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			$demand  = OPENBB_Inventory::get_cycle_demand( $cycle->id );
			$ordered = $demand[ $product_id ]['ordered'] ?? 0.0;
			if ( $prepared ) {
				if ( $ordered <= 0 ) {
					return new WP_Error( 'openbb_preparation_no_demand', __( 'This product has no pre-orders in this cycle.', 'open-bulk-buying' ), array( 'status' => 409 ) );
				}
				if ( null !== $expected_ordered && abs( $expected_ordered - $ordered ) > OPENBB_Order_Editor::EPSILON ) {
					return new WP_Error( 'openbb_preparation_changed', __( 'Orders for this product changed. Review the new quantity before marking it set aside.', 'open-bulk-buying' ), array( 'status' => 409 ) );
				}
			}
			$method = self::methods_for( $cycle, array( $product_id ) )[ $product_id ];
			$now    = current_time( 'mysql', true );
			$saved  = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->openbb_cycle_products} (cycle_id, product_id, prep_method, prepared_qty, prepared_at, prepared_by, updated_at)
					VALUES (%d, %d, %s, " . ( $prepared ? '%f, %s, %d' : 'NULL, NULL, NULL' ) . ', %s)
					ON DUPLICATE KEY UPDATE prepared_qty = VALUES(prepared_qty), prepared_at = VALUES(prepared_at), prepared_by = VALUES(prepared_by), updated_at = VALUES(updated_at)',
					...array_merge( array( $cycle->id, $product_id, $method ), $prepared ? array( round( $ordered, 3 ), $now, $actor_id ) : array(), array( $now ) )
				)
			);
			return false === $saved ? self::failed() : true;
		} finally {
			OPENBB_Inventory::unlock();
		}
	}

	/**
	 * Changes how a product is prepared in this cycle.
	 *
	 * @return true|WP_Error
	 */
	public static function set_method( int $cycle_id, int $product_id, string $method ) {
		global $wpdb;

		$method = self::sanitize_method( $method );
		if ( '' === $method ) {
			return new WP_Error( 'openbb_invalid_prep_method', __( 'Choose a preparation method.', 'open-bulk-buying' ), array( 'status' => 400 ) );
		}
		if ( ! OPENBB_Inventory::lock() ) {
			return self::busy();
		}
		try {
			$cycle = OPENBB_Cycles::get( $cycle_id );
			if ( ! $cycle ) {
				return new WP_Error( 'openbb_cycle_not_found', __( 'Cycle not found.', 'open-bulk-buying' ), array( 'status' => 404 ) );
			}
			if ( OPENBB_Cycle::STATUS_COMPLETE === $cycle->status ) {
				return new WP_Error( 'openbb_cycle_finalized', __( 'A completed cycle cannot be changed.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			$current = self::methods_for( $cycle, array( $product_id ) )[ $product_id ];
			if ( $current === $method ) {
				return true;
			}
			if ( self::METHOD_BASKET === $current && ( OPENBB_Inventory::get_cycle_demand( $cycle->id )[ $product_id ]['packed_lines'] ?? 0 ) > 0 ) {
				return new WP_Error( 'openbb_preparation_packed', __( 'Some baskets already contain this product. Unpack them before changing how it is prepared.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			$saved = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->openbb_cycle_products} (cycle_id, product_id, prep_method, updated_at) VALUES (%d, %d, %s, %s)
					ON DUPLICATE KEY UPDATE prep_method = VALUES(prep_method), updated_at = VALUES(updated_at)",
					$cycle->id,
					$product_id,
					$method,
					current_time( 'mysql', true )
				)
			);
			return false === $saved ? self::failed() : true;
		} finally {
			OPENBB_Inventory::unlock();
		}
	}

	/**
	 * Packs (or unpacks) the basket-method products of a committed order: each line's
	 * prepared quantity is set to what was requested, which locks the line in the editor.
	 *
	 * @return OPENBB_Order|WP_Error
	 */
	public static function pack_order( int $order_id, bool $packed, int $expected_revision, int $actor_id ) {
		if ( ! OPENBB_Inventory::lock() ) {
			return self::busy();
		}
		try {
			$order = OPENBB_Orders::get( $order_id );
			if ( ! $order ) {
				return new WP_Error( 'openbb_order_not_found', __( 'Order not found.', 'open-bulk-buying' ), array( 'status' => 404 ) );
			}
			if ( $expected_revision !== $order->revision ) {
				return new WP_Error( 'openbb_order_changed', __( 'This order changed. Reload it before packing.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			$cycle = $order->cycle_id ? OPENBB_Cycles::get( $order->cycle_id ) : null;
			if ( ! $cycle || ! in_array( $cycle->status, self::STAGES, true ) ) {
				return new WP_Error( 'openbb_preparation_closed', __( 'Baskets can be packed only while the cycle is processing or distributing.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			if ( ! OPENBB_Inventory::order_reserves( $order, $cycle ) ) {
				return new WP_Error( 'openbb_order_not_packable', __( 'Only submitted orders that are still held and not yet handed out can be packed.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			$lines   = self::basket_lines( $order, $cycle );
			$changed = array();
			foreach ( $lines as $line ) {
				if ( null !== $line->qty_final ) {
					continue;
				}
				if ( $packed && null === $line->qty_prepared ) {
					$line->qty_prepared = $line->qty_requested;
					$changed[]          = $line;
				} elseif ( ! $packed && null !== $line->qty_prepared ) {
					$line->qty_prepared = null;
					$changed[]          = $line;
				}
			}
			if ( ! $lines ) {
				return new WP_Error( 'openbb_order_not_packable', __( 'This order has no products packed into baskets.', 'open-bulk-buying' ), array( 'status' => 409 ) );
			}
			if ( ! $changed ) {
				return $order;
			}
			$saved = $order->save_with_lines(
				$changed,
				array(),
				array(
					'actor_id'    => $actor_id,
					'event_type'  => $packed ? 'packed' : 'unpacked',
					'from_status' => $order->status,
				)
			);
			return $saved ? OPENBB_Orders::get( $order->id ) : self::failed();
		} finally {
			OPENBB_Inventory::unlock();
		}
	}

	/**
	 * The order's product lines prepared with the basket method.
	 *
	 * @return OPENBB_Order_Line[]
	 */
	public static function basket_lines( OPENBB_Order $order, OPENBB_Cycle $cycle ): array {
		$lines   = array_values(
			array_filter(
				$order->get_lines(),
				static function ( OPENBB_Order_Line $line ): bool {
					return ! $line->is_membership_fee() && $line->qty_requested > 0;
				}
			)
		);
		$methods = self::methods_for( $cycle, wp_list_pluck( $lines, 'product_id' ) );

		return array_values(
			array_filter(
				$lines,
				static function ( OPENBB_Order_Line $line ) use ( $methods ): bool {
					return self::METHOD_BASKET === ( $methods[ $line->product_id ] ?? '' );
				}
			)
		);
	}

	/**
	 * Raw preparation data: every product pre-ordered in the cycle (or set aside
	 * before its orders went away), grouped by category like the order form.
	 *
	 * @return array{sections: array, products: array<int, array>}
	 *         Sections hold product ids; products hold product, method, demand, row, on_hand and reserved.
	 */
	public static function overview( OPENBB_Cycle $cycle ): array {
		$demand = OPENBB_Inventory::get_cycle_demand( $cycle->id );
		$ids    = array_keys( $demand );
		foreach ( self::rows( $cycle->id ) as $product_id => $row ) {
			if ( null !== $row['prepared_qty'] ) {
				$ids[] = $product_id;
			}
		}
		$ids      = array_values( array_unique( $ids ) );
		$methods  = self::methods_for( $cycle, $ids );
		$rows     = self::rows( $cycle->id );
		$on_hand  = OPENBB_Inventory::get_on_hand_for( $ids );
		$reserved = OPENBB_Inventory::get_reserved_for( $ids );

		$products = array();
		$data     = array();
		foreach ( $ids as $product_id ) {
			$product = OPENBB_Product::get( $product_id );
			if ( ! $product ) {
				continue;
			}
			$products[]          = $product;
			$data[ $product_id ] = array(
				'product'  => $product,
				'method'   => $methods[ $product_id ],
				'demand'   => $demand[ $product_id ] ?? array(),
				'row'      => $rows[ $product_id ] ?? array(),
				'on_hand'  => $on_hand[ $product_id ] ?? 0.0,
				'reserved' => $reserved[ $product_id ] ?? 0.0,
			);
		}

		$sections = array();
		foreach ( OPENBB_Catalog::sections( $products, true ) as $section ) {
			$sections[] = array(
				'id'          => $section['id'],
				'name'        => $section['name'],
				'depth'       => $section['depth'],
				'product_ids' => wp_list_pluck( $section['products'], 'id' ),
			);
		}

		return array(
			'sections' => $sections,
			'products' => $data,
		);
	}

	private static function busy(): WP_Error {
		return new WP_Error( 'openbb_busy', __( 'Stock is being updated. Please try again.', 'open-bulk-buying' ), array( 'status' => 503 ) );
	}

	private static function failed(): WP_Error {
		return new WP_Error( 'openbb_preparation_save_failed', __( 'The preparation change could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
	}
}
