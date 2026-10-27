<?php
/**
 * Order line. Product details and prices are snapshots taken when the line was
 * written, so later catalogue edits don't change past orders. product_name is in
 * the order's language. Amounts are integer minor units; unit_price covers
 * unit_amount of unit_type, and quantities count those units as decimals so weighed
 * items remain possible. base_per_unit snapshots how many inventory base units one
 * of those units holds (see units.php).
 *
 * The container charge is added to the line total without being shown separately;
 * members who bring their own container get it back as a visible per-unit credit.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Order_Line extends OPENBB_Entity {

	const SOURCE_MEMBER  = 'member';
	const SOURCE_WALK_UP = 'walk_up';
	const TYPE_PRODUCT = 'product';
	const TYPE_MEMBERSHIP_FEE = 'membership_fee';

	public $id               = 0;
	public $order_id         = 0;
	public $product_id       = 0;
	public $source           = self::SOURCE_MEMBER;
	public $line_type        = self::TYPE_PRODUCT;
	public $product_name     = '';
	public $brand            = '';
	public $unit_amount      = OPENBB_UNIT_AMOUNT_DEFAULT;
	public $unit_type        = OPENBB_UNIT_TYPE_DEFAULT;
	public $base_per_unit    = OPENBB_UNIT_AMOUNT_DEFAULT;
	public $unit_price       = 0;
	public $container_charge = 0;
	public $own_container    = false;
	public $qty_requested    = 0.0;
	public $qty_prepared     = null;
	public $qty_final        = null;
	public $line_total       = 0;
	public $created_at       = null;
	public $updated_at       = null;

	protected function cast(): void {
		$this->id               = (int) $this->id;
		$this->order_id         = (int) $this->order_id;
		$this->product_id       = (int) $this->product_id;
		$this->product_name     = (string) $this->product_name;
		$this->brand            = (string) $this->brand;
		$this->unit_amount      = (float) $this->unit_amount;
		$this->unit_type        = (string) $this->unit_type;
		$this->base_per_unit    = (float) $this->base_per_unit;
		$this->unit_price       = (int) $this->unit_price;
		$this->container_charge = (int) $this->container_charge;
		$this->own_container    = (bool) (int) $this->own_container;
		$this->qty_requested    = (float) $this->qty_requested;
		$this->qty_prepared     = self::nullable_float( $this->qty_prepared );
		$this->qty_final        = self::nullable_float( $this->qty_final );
		$this->line_total       = (int) $this->line_total;
	}

	/**
	 * Persists this line and keeps the parent order totals in sync.
	 */
	public function save(): bool {
		global $wpdb;

		$order = OPENBB_Orders::get( $this->order_id );
		if ( ! $order ) {
			return false;
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

		if ( ! $this->write_row() || ! $order->save() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		return true;
	}

	/**
	 * Writes only this line's row. The caller owns the transaction and must save the
	 * parent order afterwards so its totals match (see OPENBB_Order::save_with_lines()).
	 */
	public function write_row(): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );
		if ( ! $this->created_at ) {
			$this->created_at = $now;
		}
		$this->updated_at = $now;
		$this->line_total = $this->calculate_total();

		return $this->save_row(
			$wpdb->openbb_order_lines,
			array(
				'order_id'         => $this->order_id,
				'product_id'       => $this->product_id,
				'source'           => $this->source,
				'line_type'        => $this->line_type,
				'product_name'     => $this->product_name,
				'brand'            => $this->brand,
				'unit_amount'      => $this->unit_amount,
				'unit_type'        => $this->unit_type,
				'base_per_unit'    => $this->base_per_unit,
				'unit_price'       => $this->unit_price,
				'container_charge' => $this->container_charge,
				'own_container'    => (int) $this->own_container,
				'qty_requested'    => $this->qty_requested,
				'qty_prepared'     => $this->qty_prepared,
				'qty_final'        => $this->qty_final,
				'line_total'       => $this->line_total,
				'created_at'       => $this->created_at,
				'updated_at'       => $this->updated_at,
			),
			array(
				'%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%f', '%d', '%d',
				'%d', '%f', '%f', '%f', '%d', '%s', '%s',
			)
		);
	}

	/**
	 * Order lines can only be hard-deleted on drafts without submission history.
	 *
	 * @return true|WP_Error
	 */
	public function delete() {
		if ( ! OPENBB_Inventory::lock() ) {
			return new WP_Error( 'openbb_busy', __( 'Stock is being updated. Please try again.', 'open-bulk-buying' ), array( 'status' => 503 ) );
		}
		try {
			return $this->delete_locked();
		} finally {
			OPENBB_Inventory::unlock();
		}
	}

	private function delete_locked() {
		global $wpdb;

		$order = OPENBB_Orders::get( $this->order_id );
		if ( ! $this->id || ! $order ) {
			return new WP_Error( 'openbb_order_line_not_found', __( 'This order line no longer exists.', 'open-bulk-buying' ) );
		}
		if ( OPENBB_Order::STATUS_DRAFT !== $order->status || $order->submitted_at ) {
			return new WP_Error( 'openbb_order_line_not_deletable', __( 'Lines on submitted orders cannot be deleted.', 'open-bulk-buying' ) );
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'openbb_order_line_delete_failed', __( 'The order line could not be deleted.', 'open-bulk-buying' ) );
		}
		if ( 1 !== $wpdb->delete( $wpdb->openbb_order_lines, array( 'id' => $this->id ), array( '%d' ) )
			|| ! $order->save()
			|| false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'openbb_order_line_delete_failed', __( 'The order line could not be deleted.', 'open-bulk-buying' ) );
		}

		return true;
	}

	public function get_unit_label(): string {
		return openbb_format_unit( $this->unit_amount, $this->unit_type );
	}

	/**
	 * The latest known quantity: final, else prepared, else requested.
	 */
	public function get_quantity(): float {
		return $this->qty_final ?? $this->qty_prepared ?? $this->qty_requested;
	}

	public function get_base_quantity(): float {
		if ( $this->is_membership_fee() ) {
			return 0.0;
		}
		return round( $this->get_quantity() * $this->base_per_unit, 3 );
	}

	public function is_membership_fee(): bool {
		return self::TYPE_MEMBERSHIP_FEE === $this->line_type;
	}

	/**
	 * Price plus container charge, per unit, times the latest quantity.
	 */
	public function calculate_total(): int {
		return (int) round( $this->get_quantity() * ( $this->unit_price + $this->container_charge ) );
	}

	public function calculate_container_credit(): int {
		return $this->own_container ? (int) round( $this->get_quantity() * $this->container_charge ) : 0;
	}
}
