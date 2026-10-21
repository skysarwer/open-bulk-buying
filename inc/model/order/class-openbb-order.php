<?php
/**
 * One member's order for one cycle. Amounts are integer minor units (e.g. cents).
 * language is the member's language when the order was submitted; line snapshots use it.
 * member_note is written by and shown to the member; staff_note is internal.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Order extends OPENBB_Entity {

	const STATUS_DRAFT     = 'draft';
	const STATUS_SUBMITTED = 'submitted';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_PREPAID   = 'prepaid';
	const STATUS_FULFILLED = 'fulfilled';
	const COMMITTED_STATUSES = array( self::STATUS_SUBMITTED, self::STATUS_PREPAID );

	const AID_NONE     = 'none';
	const AID_PENDING  = 'pending';
	const AID_APPROVED = 'approved';
	const AID_DECLINED = 'declined';

	public $id               = 0;
	public $cycle_id         = 0;
	public $user_id          = 0;
	public $status           = self::STATUS_DRAFT;
	public $language         = '';
	public $currency         = '';
	public $subtotal         = 0;
	public $container_credit = 0;
	public $donation         = 0;
	public $aid_status       = self::AID_NONE;
	public $aid_reason       = '';
	public $aid_requested    = 0;
	public $aid_approved     = 0;
	public $aid_decided_by   = null;
	public $aid_decided_at   = null;
	public $total            = 0;
	public $member_note      = '';
	public $staff_note       = '';
	public $submitted_at     = null;
	public $collected_at     = null;
	public $collected_by     = null;
	public $paid_at          = null;
	public $paid_by          = null;
	public $paid_amount      = null;
	public $paid_currency    = null;
	public $created_at       = null;
	public $updated_at       = null;
	public $revision         = 0;

	private $lines = null;

	protected function cast(): void {
		$this->id               = (int) $this->id;
		$this->revision         = (int) $this->revision;
		$this->cycle_id         = (int) $this->cycle_id;
		$this->user_id          = (int) $this->user_id;
		$this->language         = (string) $this->language;
		$this->subtotal         = (int) $this->subtotal;
		$this->container_credit = (int) $this->container_credit;
		$this->donation         = (int) $this->donation;
		$this->aid_reason       = (string) $this->aid_reason;
		$this->aid_requested    = (int) $this->aid_requested;
		$this->aid_approved     = (int) $this->aid_approved;
		$this->aid_decided_by   = self::nullable_int( $this->aid_decided_by );
		$this->total            = (int) $this->total;
		$this->collected_by     = self::nullable_int( $this->collected_by );
		$this->paid_by          = self::nullable_int( $this->paid_by );
		$this->paid_amount      = self::nullable_int( $this->paid_amount );
		$this->member_note      = (string) $this->member_note;
		$this->staff_note       = (string) $this->staff_note;
	}

	/**
	 * @return OPENBB_Order_Line[]
	 */
	public function get_lines(): array {
		if ( null === $this->lines ) {
			$this->lines = $this->id ? OPENBB_Orders::get_lines( $this->id ) : array();
		}
		return $this->lines;
	}

	/**
	 * Translated status labels. Only call from UI code.
	 */
	public static function status_labels(): array {
		return array(
			self::STATUS_DRAFT     => __( 'Draft', 'open-bulk-buying' ),
			self::STATUS_SUBMITTED => __( 'Submitted', 'open-bulk-buying' ),
			self::STATUS_CANCELLED => __( 'Cancelled', 'open-bulk-buying' ),
			self::STATUS_PREPAID   => __( 'Prepaid', 'open-bulk-buying' ),
			self::STATUS_FULFILLED => __( 'Fulfilled', 'open-bulk-buying' ),
		);
	}

	/**
	 * Translated aid status labels. Only call from UI code.
	 */
	public static function aid_status_labels(): array {
		return array(
			self::AID_NONE     => __( 'None', 'open-bulk-buying' ),
			self::AID_PENDING  => __( 'Pending', 'open-bulk-buying' ),
			self::AID_APPROVED => __( 'Approved', 'open-bulk-buying' ),
			self::AID_DECLINED => __( 'Declined', 'open-bulk-buying' ),
		);
	}

	public function is_collected(): bool {
		return null !== $this->collected_at;
	}

	public function is_abandoned( ?OPENBB_Cycle $cycle = null, ?int $timestamp = null ): bool {
		if ( ! in_array( $this->status, self::COMMITTED_STATUSES, true ) || $this->is_collected() ) {
			return false;
		}
		$cycle = $cycle ?? OPENBB_Cycles::get( $this->cycle_id );
		$until = $cycle ? $cycle->hold_until( $this->status ) : null;

		return null !== $until && ( $timestamp ?? time() ) >= strtotime( $until . ' UTC' );
	}

	/**
	 * Persists this order. Persist order lines separately with OPENBB_Order_Line::save().
	 */
	public function save(): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );
		if ( ! $this->created_at ) {
			$this->created_at = $now;
		}
		$this->updated_at = $now;
		++$this->revision;
		$this->recalculate_totals();

		return $this->save_row(
			$wpdb->openbb_orders,
			array(
				'cycle_id'         => $this->cycle_id,
				'user_id'          => $this->user_id,
				'status'           => $this->status,
				'language'         => $this->language,
				'currency'         => $this->currency,
				'subtotal'         => $this->subtotal,
				'container_credit' => $this->container_credit,
				'donation'         => $this->donation,
				'aid_status'       => $this->aid_status,
				'aid_reason'       => $this->aid_reason,
				'aid_requested'    => $this->aid_requested,
				'aid_approved'     => $this->aid_approved,
				'aid_decided_by'   => $this->aid_decided_by,
				'aid_decided_at'   => $this->aid_decided_at,
				'total'            => $this->total,
				'member_note'      => $this->member_note,
				'staff_note'       => $this->staff_note,
				'submitted_at'     => $this->submitted_at,
				'collected_at'     => $this->collected_at,
				'collected_by'     => $this->collected_by,
				'paid_at'          => $this->paid_at,
				'paid_by'          => $this->paid_by,
				'paid_amount'      => $this->paid_amount,
				'paid_currency'    => $this->paid_currency,
				'created_at'       => $this->created_at,
				'updated_at'       => $this->updated_at,
				'revision'         => $this->revision,
			),
			array(
				'%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d',
				'%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d',
			)
		);
	}

	/**
	 * Saves this order, writes $lines and deletes $delete_line_ids in one transaction,
	 * then recalculates the stored totals from the resulting lines. New orders are
	 * inserted first so new lines can reference them.
	 *
	 * Persistence only: callers check permissions, availability and line eligibility.
	 *
	 * @param OPENBB_Order_Line[] $lines           Lines to insert or update.
	 * @param int[]               $delete_line_ids Existing lines of this order to remove.
	 */
	public function save_with_lines( array $lines, array $delete_line_ids = array(), ?array $event = null ): bool {
		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

		$was_new     = empty( $this->id );
		$this->lines = null;
		$saved       = $this->save();
		foreach ( $lines as $line ) {
			if ( ! $saved ) {
				break;
			}
			if ( $line->id && $line->order_id !== (int) $this->id ) {
				$saved = false;
				break;
			}
			$line->order_id = (int) $this->id;
			$saved          = $line->write_row();
		}
		foreach ( $delete_line_ids as $line_id ) {
			if ( ! $saved ) {
				break;
			}
			$saved = 1 === $wpdb->delete(
				$wpdb->openbb_order_lines,
				array(
					'id'       => (int) $line_id,
					'order_id' => (int) $this->id,
				),
				array( '%d', '%d' )
			);
		}

		// Reload the lines inside the transaction so totals reflect exactly what was written.
		$this->lines = null;
		if ( ! $saved || ! $this->save()
			|| ( null !== $event && ! OPENBB_Orders::record_event( $this, $event ) )
			|| false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			$this->lines = null;
			if ( $was_new ) {
				$this->id = 0;
			}
			return false;
		}

		$this->lines = null;

		return true;
	}

	/**
	 * Removes a draft order and its lines. Submitted/history-bearing
	 * orders are preserved; stock moves referring to the order also prevent deletion.
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

		if ( ! $this->id ) {
			return new WP_Error( 'openbb_order_not_saved', __( 'This order has not been saved.', 'open-bulk-buying' ) );
		}

		$order = OPENBB_Orders::get( (int) $this->id );
		if ( ! $order ) {
			return new WP_Error( 'openbb_order_not_found', __( 'This order no longer exists.', 'open-bulk-buying' ) );
		}
		if ( ! OPENBB_Order_Policy::can_delete( $order ) ) {
			return new WP_Error( 'openbb_order_not_deletable', __( 'Only drafts without operational history can be deleted.', 'open-bulk-buying' ) );
		}
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_stock_moves} WHERE order_id = %d", $order->id ) ) > 0 ) {
			return new WP_Error( 'openbb_order_has_stock_history', __( 'An order with stock history cannot be deleted.', 'open-bulk-buying' ) );
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'openbb_order_delete_failed', __( 'The order could not be deleted.', 'open-bulk-buying' ) );
		}

		$events_deleted = false !== $wpdb->delete( $wpdb->openbb_order_events, array( 'order_id' => $order->id ), array( '%d' ) );
		$lines_deleted = $events_deleted && false !== $wpdb->delete( $wpdb->openbb_order_lines, array( 'order_id' => $order->id ), array( '%d' ) );
		$order_deleted = $lines_deleted && 1 === $wpdb->delete( $wpdb->openbb_orders, array( 'id' => $order->id ), array( '%d' ) );
		if ( ! $order_deleted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'openbb_order_delete_failed', __( 'The order could not be deleted.', 'open-bulk-buying' ) );
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'openbb_order_delete_failed', __( 'The order could not be deleted.', 'open-bulk-buying' ) );
		}

		return true;
	}

	/**
	 * Recomputes subtotal, container_credit and total from the lines; call before
	 * every save so the stored amounts never drift from the lines. Approved aid can
	 * reduce the amount owed for goods to zero but not below, and never offsets the donation.
	 *
	 * @param OPENBB_Order_Line[]|null $lines Defaults to the saved lines.
	 */
	public function recalculate_totals( ?array $lines = null ): void {
		$subtotal         = 0;
		$container_credit = 0;
		foreach ( $lines ?? $this->get_lines() as $line ) {
			$subtotal         += $line->calculate_total();
			$container_credit += $line->calculate_container_credit();
		}

		$this->subtotal         = $subtotal;
		$this->container_credit = $container_credit;
		$this->total            = max( 0, $subtotal - $container_credit - $this->aid_approved ) + $this->donation;
	}
}
