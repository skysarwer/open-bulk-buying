<?php
/**
 * Products, their stock ledger, and product categories/suppliers.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_REST_Products extends OPENBB_REST_Controller {

	const TERM_TAXONOMIES = array(
		'categories' => OPENBB_PRODUCT_TAXONOMY,
		'suppliers'  => OPENBB_SUPPLIER_TAXONOMY,
	);

	public function register_routes(): void {
		$can_manage = static function () {
			return self::authorize( 'openbb_manage_products' );
		};

		register_rest_route(
			self::API_NAMESPACE,
			'/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $can_manage,
					'args'                => self::collection_args(
						array( 'title', 'date', 'modified', 'price', 'menu_order' ),
						'asc',
						array(
							'active'   => array(
								'type'    => 'string',
								'default' => '',
								'enum'    => array( '', 'active', 'inactive' ),
							),
							'category' => array(
								'type'    => 'array',
								'items'   => array( 'type' => 'integer' ),
								'default' => array(),
							),
							'supplier' => array(
								'type'    => 'array',
								'items'   => array( 'type' => 'integer' ),
								'default' => array(),
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $can_manage,
					'args'                => $this->write_args(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/products/(?P<id>\d+)',
			array(
				'args' => self::id_arg(),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $can_manage,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $can_manage,
					'args'                => $this->write_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $can_manage,
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/products/(?P<id>\d+)/moves',
			array(
				'args' => self::id_arg(),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_moves' ),
					'permission_callback' => static function () {
						return self::authorize_any( array( 'openbb_manage_inventory', 'openbb_manage_products' ) );
					},
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_move' ),
					'permission_callback' => static function () {
						return self::authorize( 'openbb_manage_inventory' );
					},
					'args'                => array(
						'quantity'    => array(
							'type'     => 'number',
							'required' => true,
						),
						'unit'        => array(
							'type'    => 'string',
							'default' => 'selling',
							'enum'    => array_merge( array( 'selling' ), array_keys( openbb_unit_types() ) ),
						),
						'reason'      => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => OPENBB_Stock_Move::MANUAL_REASONS,
						),
						'note'        => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'request_key' => array(
							'type'    => 'string',
							'default' => '',
							'pattern' => '^[A-Za-z0-9-]{0,64}$',
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/product-terms/(?P<taxonomy>categories|suppliers)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_terms' ),
					'permission_callback' => $can_manage,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_term' ),
					'permission_callback' => $can_manage,
					'args'                => array(
						'name' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'order' => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/product-terms/categories/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_category' ),
				'permission_callback' => $can_manage,
				'args'                => array_merge(
					self::id_arg(),
					array( 'order' => array( 'type' => 'integer', 'required' => true ) )
				),
			)
		);
	}

	private function write_args(): array {
		return array_merge(
			self::localized_args( 'name' ),
			self::localized_args( 'description' ),
			array(
				'brand'            => array( 'type' => 'string' ),
				'menu_order'       => array( 'type' => 'integer' ),
				'unit_amount'      => array(
					'type'             => 'number',
					'exclusiveMinimum' => true,
					'minimum'          => 0,
				),
				'unit_type'        => array(
					'type' => 'string',
					'enum' => array_keys( openbb_unit_types() ),
				),
				'price'            => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'container_charge' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'active'           => array( 'type' => 'boolean' ),
				'category_ids'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'supplier_id'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'image_id'         => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			)
		);
	}

	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request['per_page'];
		$args     = array(
			'post_type'      => OPENBB_PRODUCT_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => (int) $request['page'],
			'order'          => strtoupper( $request['order'] ),
			'orderby'        => $request['orderby'],
			's'              => $request['search'],
		);
		if ( 'price' === $request['orderby'] ) {
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = OPENBB_META_PREFIX . 'price';
		}
		if ( 'menu_order' === $request['orderby'] ) {
			$args['orderby'] = array( 'menu_order' => $args['order'], 'title' => 'ASC', 'ID' => 'ASC' );
		}

		$active_key = OPENBB_META_PREFIX . 'active';
		if ( 'active' === $request['active'] ) {
			$args['meta_query'] = array(
				array(
					'key'   => $active_key,
					'value' => '1',
				),
			);
		} elseif ( 'inactive' === $request['active'] ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => $active_key,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => $active_key,
					'value'   => '1',
					'compare' => '!=',
				),
			);
		}

		$tax_query = array();
		foreach ( array( 'category' => OPENBB_PRODUCT_TAXONOMY, 'supplier' => OPENBB_SUPPLIER_TAXONOMY ) as $param => $taxonomy ) {
			$ids = array_filter( array_map( 'intval', (array) $request[ $param ] ) );
			if ( $ids ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $ids,
				);
			}
		}
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query;
		}

		$query    = new WP_Query( $args );
		$products = array_filter( array_map( array( 'OPENBB_Product', 'get' ), wp_list_pluck( $query->posts, 'ID' ) ) );

		return self::paginated( $this->prepare_products( $products ), (int) $query->found_posts, $per_page );
	}

	public function get_item( WP_REST_Request $request ) {
		$product = OPENBB_Product::get( (int) $request['id'] );
		if ( ! $product ) {
			return self::not_found( __( 'Product not found.', 'open-bulk-buying' ) );
		}

		return rest_ensure_response( $this->prepare_products( array( $product ) )[0] );
	}

	public function create_item( WP_REST_Request $request ) {
		return $this->save( $request, null );
	}

	public function update_item( WP_REST_Request $request ) {
		$product = OPENBB_Product::get( (int) $request['id'] );
		if ( ! $product ) {
			return self::not_found( __( 'Product not found.', 'open-bulk-buying' ) );
		}

		return $this->save( $request, $product );
	}

	/**
	 * Products referenced by orders or the stock ledger are history and can only be deactivated.
	 */
	public function delete_item( WP_REST_Request $request ) {
		$product = OPENBB_Product::get( (int) $request['id'] );
		if ( ! $product ) {
			return self::not_found( __( 'Product not found.', 'open-bulk-buying' ) );
		}
		if ( ! current_user_can( 'delete_post', $product->id ) ) {
			return self::authorize( 'delete_post', $product->id );
		}

		$deleted = $product->delete();
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $product->id,
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	private function save( WP_REST_Request $request, ?OPENBB_Product $existing ) {
		$current = $existing ?? new OPENBB_Product();

		$name = openbb_sanitize_localized_text( self::localized_in( $request, 'name', $current->name ) );
		if ( '' === trim( $name['default'] ) ) {
			return self::invalid( __( 'Enter a product name.', 'open-bulk-buying' ) );
		}
		$description      = openbb_sanitize_localized_html( self::localized_in( $request, 'description', $current->description ) );
		$brand            = $request->has_param( 'brand' ) ? sanitize_text_field( (string) $request['brand'] ) : $current->brand;
		$unit_amount      = $request->has_param( 'unit_amount' ) ? openbb_sanitize_unit_amount( $request['unit_amount'] ) : $current->unit_amount;
		$unit_type        = $request->has_param( 'unit_type' ) ? openbb_sanitize_unit_type( $request['unit_type'] ) : $current->unit_type;
		$price            = $request->has_param( 'price' ) ? (int) $request['price'] : $current->price;
		$container_charge = $request->has_param( 'container_charge' ) ? (int) $request['container_charge'] : $current->container_charge;
		$active           = $request->has_param( 'active' ) ? (bool) $request['active'] : $current->active;
		$category_ids     = $request->has_param( 'category_ids' ) ? array_values( array_unique( array_filter( array_map( 'intval', (array) $request['category_ids'] ) ) ) ) : $current->category_ids;
		$supplier_id      = $request->has_param( 'supplier_id' ) ? (int) $request['supplier_id'] : $current->supplier_id;
		$image_id         = $request->has_param( 'image_id' ) ? (int) $request['image_id'] : $current->image_id;
		$menu_order       = $request->has_param( 'menu_order' ) ? (int) $request['menu_order'] : $current->menu_order;

		foreach ( $category_ids as $term_id ) {
			if ( ! term_exists( $term_id, OPENBB_PRODUCT_TAXONOMY ) ) {
				return self::invalid( __( 'Choose an existing category.', 'open-bulk-buying' ) );
			}
		}
		if ( $supplier_id && ! term_exists( $supplier_id, OPENBB_SUPPLIER_TAXONOMY ) ) {
			return self::invalid( __( 'Choose an existing supplier.', 'open-bulk-buying' ) );
		}
		if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
			return self::invalid( __( 'Choose an image from the media library.', 'open-bulk-buying' ) );
		}

		// Validate the unit change as it will be written: after any deactivation, before any activation.
		if ( $existing ) {
			$probe         = clone $existing;
			$probe->active = $existing->active && $active;
			$unit_check    = $probe->validate_unit_change( $unit_amount, $unit_type );
			if ( is_wp_error( $unit_check ) ) {
				$unit_check->add_data( array( 'status' => 409 ) );
				return $unit_check;
			}
		}

		$postarr = array(
			'post_type'   => OPENBB_PRODUCT_POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name['default'],
			'menu_order'  => $menu_order,
		);
		if ( $existing ) {
			$postarr['ID'] = $existing->id;
			$product_id    = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$product_id = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $product_id ) ) {
			$product_id->add_data( array( 'status' => 500 ) );
			return $product_id;
		}
		$product_id = (int) $product_id;

		$meta = array(
			'name'             => $name,
			'description'      => $description,
			'brand'            => $brand,
			'price'            => $price,
			'container_charge' => $container_charge,
		);
		// Units may only change while inactive: deactivate first, activate last.
		if ( ! $active ) {
			$meta['active'] = false;
		}
		$meta['unit_amount'] = $unit_amount;
		$meta['unit_type']   = $unit_type;
		if ( $active ) {
			$meta['active'] = true;
		}
		foreach ( $meta as $field => $value ) {
			update_post_meta( $product_id, OPENBB_META_PREFIX . $field, wp_slash( $value ) );
		}

		$terms = wp_set_object_terms( $product_id, $category_ids, OPENBB_PRODUCT_TAXONOMY );
		if ( ! is_wp_error( $terms ) ) {
			$terms = wp_set_object_terms( $product_id, $supplier_id ? array( $supplier_id ) : array(), OPENBB_SUPPLIER_TAXONOMY );
		}
		if ( is_wp_error( $terms ) ) {
			$terms->add_data( array( 'status' => 500 ) );
			return $terms;
		}

		if ( $image_id ) {
			set_post_thumbnail( $product_id, $image_id );
		} else {
			delete_post_thumbnail( $product_id );
		}

		$saved = OPENBB_Product::get( $product_id );
		if ( ! $saved
			|| $saved->name != $name // phpcs:ignore Universal.Operators.StrictComparisons -- key order may differ.
			|| $saved->brand !== $brand
			|| $saved->price !== $price
			|| $saved->container_charge !== $container_charge
			|| $saved->unit_amount !== $unit_amount
			|| $saved->unit_type !== $unit_type
			|| $saved->active !== $active
			|| $saved->menu_order !== $menu_order
		) {
			return new WP_Error( 'openbb_product_save_incomplete', __( 'Some product changes could not be saved. Reload the product and check its details.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}

		$response = rest_ensure_response( $this->prepare_products( array( $saved ) )[0] );
		if ( ! $existing ) {
			$response->set_status( 201 );
		}

		return $response;
	}

	public function get_moves( WP_REST_Request $request ) {
		$product = OPENBB_Product::get( (int) $request['id'] );
		if ( ! $product ) {
			return self::not_found( __( 'Product not found.', 'open-bulk-buying' ) );
		}

		$per_page = (int) $request['per_page'];
		$moves    = OPENBB_Inventory::get_moves( $product->id, $per_page, ( (int) $request['page'] - 1 ) * $per_page );

		return self::paginated( array_map( array( $this, 'prepare_move' ), $moves ), OPENBB_Inventory::count_moves( $product->id ), $per_page );
	}

	public function create_move( WP_REST_Request $request ) {
		$product = OPENBB_Product::get( (int) $request['id'] );
		if ( ! $product ) {
			return self::not_found( __( 'Product not found.', 'open-bulk-buying' ) );
		}

		$quantity = (float) $request['quantity'];
		$unit     = (string) $request['unit'];
		if ( 'selling' === $unit ) {
			$base = $quantity * $product->get_base_per_unit();
		} elseif ( openbb_units_share_dimension( $unit, $product->unit_type ) ) {
			$base = openbb_unit_to_base( abs( $quantity ), $unit ) * ( $quantity < 0 ? -1 : 1 );
		} else {
			return self::invalid( __( 'That unit measures something different from this product.', 'open-bulk-buying' ) );
		}

		$note = trim( (string) $request['note'] );
		if ( OPENBB_Stock_Move::REASON_ADJUSTMENT === $request['reason'] && '' === $note ) {
			return self::invalid( __( 'Explain why the stock is being adjusted.', 'open-bulk-buying' ) );
		}

		$move = OPENBB_Inventory::record_manual_move( $product, $base, (string) $request['reason'], $note, (string) $request['request_key'] );
		if ( is_wp_error( $move ) ) {
			return $move;
		}

		$response = rest_ensure_response(
			array(
				'move'    => $this->prepare_move( $move ),
				'product' => $this->prepare_products( array( $product ) )[0],
			)
		);
		$response->set_status( 201 );

		return $response;
	}

	public function get_terms( WP_REST_Request $request ) {
		$taxonomy = self::TERM_TAXONOMIES[ $request['taxonomy'] ];
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		if ( OPENBB_PRODUCT_TAXONOMY === $taxonomy ) {
			usort(
				$terms,
				static function ( $a, $b ) {
					$order = (int) get_term_meta( $a->term_id, OPENBB_META_PREFIX . 'order', true ) <=> (int) get_term_meta( $b->term_id, OPENBB_META_PREFIX . 'order', true );
					return $order ?: strnatcasecmp( $a->name, $b->name ) ?: $a->term_id <=> $b->term_id;
				}
			);
		}

		return rest_ensure_response( array_map( array( $this, 'prepare_term' ), $terms ) );
	}

	public function create_term( WP_REST_Request $request ) {
		$taxonomy = self::TERM_TAXONOMIES[ $request['taxonomy'] ];
		$name     = trim( (string) $request['name'] );
		if ( '' === $name ) {
			return self::invalid( __( 'Enter a name.', 'open-bulk-buying' ) );
		}

		if ( OPENBB_SUPPLIER_TAXONOMY === $taxonomy && $request->has_param( 'order' ) ) {
			return self::invalid( __( 'Only categories have a display order.', 'open-bulk-buying' ) );
		}
		$existing = term_exists( $name, $taxonomy );
		if ( $existing ) {
			return rest_ensure_response( $this->prepare_term( get_term( (int) $existing['term_id'], $taxonomy ) ) );
		}

		$term = wp_insert_term( wp_slash( $name ), $taxonomy );
		if ( is_wp_error( $term ) ) {
			$term->add_data( array( 'status' => 400 ) );
			return $term;
		}
		if ( OPENBB_PRODUCT_TAXONOMY === $taxonomy && $request->has_param( 'order' ) ) {
			$saved = $this->save_category_order( (int) $term['term_id'], (int) $request['order'] );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		$response = rest_ensure_response( $this->prepare_term( get_term( (int) $term['term_id'], $taxonomy ) ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_category( WP_REST_Request $request ) {
		$term = get_term( (int) $request['id'], OPENBB_PRODUCT_TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return self::not_found( __( 'Category not found.', 'open-bulk-buying' ) );
		}
		$result = $this->save_category_order( $term->term_id, (int) $request['order'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $this->prepare_term( $term ) );
	}

	private function save_category_order( int $term_id, int $order ) {
		$result = update_term_meta( $term_id, OPENBB_META_PREFIX . 'order', $order );
		if ( is_wp_error( $result ) ) {
			return self::with_status( $result, 500 );
		}
		if ( (int) get_term_meta( $term_id, OPENBB_META_PREFIX . 'order', true ) !== $order ) {
			return new WP_Error( 'openbb_category_order_failed', __( 'The category order could not be saved.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}
		return true;
	}

	private function prepare_term( WP_Term $term ): array {
		$data = array(
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'count'  => (int) $term->count,
			'parent' => (int) $term->parent,
		);
		if ( OPENBB_PRODUCT_TAXONOMY === $term->taxonomy ) {
			$data['order'] = (int) get_term_meta( $term->term_id, OPENBB_META_PREFIX . 'order', true );
		}
		return $data;
	}

	/**
	 * @param OPENBB_Product[] $products
	 */
	private function prepare_products( array $products ): array {
		$ids      = wp_list_pluck( $products, 'id' );
		$on_hand  = OPENBB_Inventory::get_on_hand_for( $ids );
		$reserved = OPENBB_Inventory::get_reserved_for( $ids );

		$items = array();
		foreach ( $products as $product ) {
			$items[] = self::prepare_product( $product, $on_hand[ $product->id ] ?? 0.0, $reserved[ $product->id ] ?? 0.0 );
		}

		return $items;
	}

	/**
	 * Stock quantities are base units; *_units are the same in selling units.
	 */
	public static function prepare_product( OPENBB_Product $product, float $on_hand, float $reserved ): array {
		$base_per_unit = $product->get_base_per_unit();
		$base_unit     = openbb_base_unit_type( $product->unit_type );
		$available     = round( $on_hand - $reserved, 3 );
		$post          = get_post( $product->id );
		$image         = $product->image_id ? wp_get_attachment_image_url( $product->image_id, 'thumbnail' ) : '';

		return array_merge(
			array( 'id' => $product->id ),
			self::localized_out( 'name', $product->name ),
			self::localized_out( 'description', $product->description ),
			array(
				'brand'            => $product->brand,
				'menu_order'       => $product->menu_order,
				'unit_amount'      => $product->unit_amount,
				'unit_type'        => $product->unit_type,
				'unit_label'       => $product->get_unit_label(),
				'base_unit'        => $base_unit,
				'base_per_unit'    => $base_per_unit,
				'price'            => $product->price,
				'container_charge' => $product->container_charge,
				'active'           => $product->active,
				'category_ids'     => $product->category_ids,
				'supplier_id'      => $product->supplier_id,
				'image_id'         => $product->image_id,
				'image_url'        => $image ? $image : '',
				'on_hand'          => round( $on_hand, 3 ),
				'reserved'         => round( $reserved, 3 ),
				'available'        => $available,
				'on_hand_units'    => round( $on_hand / $base_per_unit, 3 ),
				'reserved_units'   => round( $reserved / $base_per_unit, 3 ),
				'available_units'  => round( $available / $base_per_unit, 3 ),
				'modified'         => $post ? self::to_local_datetime( $post->post_modified_gmt ) : null,
			)
		);
	}

	public function prepare_move( OPENBB_Stock_Move $move ): array {
		$labels = OPENBB_Stock_Move::reason_labels();
		$user   = self::user_summary( $move->user_id );

		return array(
			'id'             => $move->id,
			'created_at'     => self::to_local_datetime( $move->created_at ),
			'reason'         => $move->reason,
			'reason_label'   => $labels[ $move->reason ] ?? $move->reason,
			'quantity'       => $move->quantity,
			'quantity_label' => openbb_format_unit( $move->quantity, openbb_base_unit_type( $move->unit_type ) ),
			'quantity_units' => $move->get_unit_quantity(),
			'unit_label'     => $move->get_unit_label(),
			'user_name'      => $move->user_id ? $user['name'] : '',
			'note'           => $move->note,
			'order_id'       => $move->order_id,
			'cycle_id'       => $move->cycle_id,
		);
	}
}
