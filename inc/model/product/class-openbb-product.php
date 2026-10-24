<?php
/**
 * Product record.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Product extends OPENBB_Entity {

	public $id               = 0;
	public $name             = array();
	public $description      = array();
	public $brand            = '';
	public $unit_amount      = OPENBB_UNIT_AMOUNT_DEFAULT;
	public $unit_type        = OPENBB_UNIT_TYPE_DEFAULT;
	public $price            = 0;
	public $container_charge = 0;
	public $active           = false;
	public $category_ids     = array();
	public $supplier_id      = 0;
	public $image_id         = 0;
	public $menu_order       = 0;

	public static function get( int $id ): ?self {
		$post = get_post( $id );
		if ( ! $post || OPENBB_PRODUCT_POST_TYPE !== $post->post_type ) {
			return null;
		}

		$data = array( 'id' => $post->ID, 'menu_order' => $post->menu_order );
		foreach ( array_keys( openbb_product_meta_fields() ) as $field ) {
			$data[ $field ] = get_post_meta( $post->ID, OPENBB_META_PREFIX . $field, true );
		}

		$data['name'] = openbb_normalize_localized( $data['name'] );
		if ( '' === $data['name']['default'] ) {
			$data['name']['default'] = $post->post_title;
		}

		$category_ids         = wp_get_post_terms( $post->ID, OPENBB_PRODUCT_TAXONOMY, array( 'fields' => 'ids' ) );
		$data['category_ids'] = is_wp_error( $category_ids ) ? array() : $category_ids;
		$supplier_ids         = wp_get_post_terms( $post->ID, OPENBB_SUPPLIER_TAXONOMY, array( 'fields' => 'ids' ) );
		$data['supplier_id']  = is_wp_error( $supplier_ids ) || ! $supplier_ids ? 0 : reset( $supplier_ids );
		$data['image_id']     = (int) get_post_thumbnail_id( $post );

		return new self( $data );
	}

	protected function cast(): void {
		$this->id               = (int) $this->id;
		$this->name             = openbb_normalize_localized( $this->name );
		$this->description      = openbb_normalize_localized( $this->description );
		$this->brand            = (string) $this->brand;
		$this->unit_amount      = openbb_sanitize_unit_amount( $this->unit_amount );
		$this->unit_type        = openbb_sanitize_unit_type( $this->unit_type );
		$this->price            = (int) $this->price;
		$this->container_charge = (int) $this->container_charge;
		$this->active           = (bool) $this->active;
		$this->category_ids     = array_map( 'intval', (array) $this->category_ids );
		$this->supplier_id      = (int) $this->supplier_id;
		$this->image_id         = (int) $this->image_id;
		$this->menu_order       = (int) $this->menu_order;
	}

	public function get_name( ?string $language = null ): string {
		return openbb_localized( $this->name, $language );
	}

	public function get_description( ?string $language = null ): string {
		return openbb_localized( $this->description, $language );
	}

	/**
	 * The quantity the price covers, e.g. "50 g".
	 */
	public function get_unit_label(): string {
		return openbb_format_unit( $this->unit_amount, $this->unit_type );
	}

	/**
	 * Inventory base units (g, ml or units) in one selling unit, e.g. 1000 for "per 1 kg".
	 */
	public function get_base_per_unit(): float {
		return openbb_unit_to_base( $this->unit_amount, $this->unit_type );
	}

	/**
	 * Checks whether the selling unit may change to $unit_amount of $unit_type.
	 *
	 * Any change requires the product to be inactive, so it can't change while
	 * members are ordering. Stock is counted in base units, so changes within a
	 * dimension (50 g to 1 kg) leave inventory intact; changes across dimensions
	 * (pieces to kg) have no conversion and also require no on-hand or reserved stock.
	 *
	 * @return true|WP_Error
	 */
	public function validate_unit_change( $unit_amount, $unit_type ) {
		$unit_amount = openbb_sanitize_unit_amount( $unit_amount );
		$unit_type   = openbb_sanitize_unit_type( $unit_type );

		if ( $unit_amount === $this->unit_amount && $unit_type === $this->unit_type ) {
			return true;
		}

		if ( $this->is_active() ) {
			return new WP_Error( 'openbb_unit_change_active', __( 'Deactivate the product before changing its unit.', 'open-bulk-buying' ) );
		}

		if ( ! openbb_units_share_dimension( $unit_type, $this->unit_type )
			&& ( 0.0 !== (float) OPENBB_Inventory::get_on_hand( $this->id ) || 0.0 !== (float) OPENBB_Inventory::get_reserved( $this->id ) ) ) {
			return new WP_Error( 'openbb_unit_change_stock', __( 'This unit measures something different. Adjust on-hand stock to zero and wait for open orders to be collected before changing it.', 'open-bulk-buying' ) );
		}

		return true;
	}

	public function is_active(): bool {
		return $this->active;
	}

	/**
	 * Permanently deletes a product that was never ordered or stocked. Products with
	 * order lines or ledger moves are history and can only be deactivated.
	 *
	 * @return true|WP_Error
	 */
	public function delete() {
		global $wpdb;

		$in_orders = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_order_lines} WHERE product_id = %d", $this->id ) );
		if ( $in_orders > 0 || OPENBB_Inventory::count_moves( $this->id ) > 0 ) {
			return new WP_Error( 'openbb_product_has_history', __( 'This product has orders or stock history. Deactivate it instead of deleting it.', 'open-bulk-buying' ), array( 'status' => 409 ) );
		}

		if ( ! wp_delete_post( $this->id, true ) ) {
			return new WP_Error( 'openbb_product_delete_failed', __( 'The product could not be deleted.', 'open-bulk-buying' ), array( 'status' => 500 ) );
		}

		return true;
	}
}
