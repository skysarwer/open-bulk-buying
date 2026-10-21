<?php
/**
 * Cycle record.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Cycle extends OPENBB_Entity {

	const STATUS_SETUP        = 'setup';
	const STATUS_ORDERING     = 'ordering';
	const STATUS_PROCESSING   = 'processing';
	const STATUS_DISTRIBUTION = 'distribution';
	const STATUS_COMPLETE     = 'complete';

	/**
	 * Stages reserving committed orders before hold deadlines are frozen at completion.
	 * Staff can submit orders in setup; several cycles may be active at once.
	 */
	const ACTIVE_STATUSES = array( self::STATUS_SETUP, self::STATUS_ORDERING, self::STATUS_PROCESSING, self::STATUS_DISTRIBUTION );

	public $id                  = 0;
	public $name                = '';
	public $status              = self::STATUS_SETUP;
	public $opens_at            = null;
	public $closes_at           = null;
	public $pickup_opens_at     = null;
	public $pickup_closes_at    = null;
	public $pickup_location     = '';
	public $pickup_instructions = array();
	public $created_at          = null;
	public $updated_at          = null;
	public $finalized_at        = null;
	public $finalized_by        = null;
	public $unpaid_hold_until   = null;
	public $prepaid_hold_until  = null;

	protected function cast(): void {
		$this->id                  = (int) $this->id;
		$this->finalized_by        = self::nullable_int( $this->finalized_by );
		$this->pickup_location     = (string) $this->pickup_location;
		$this->pickup_instructions = openbb_normalize_localized( self::decode_json( $this->pickup_instructions ) );
	}

	public function save(): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );
		if ( ! $this->created_at ) {
			$this->created_at = $now;
		}
		$this->updated_at = $now;

		$instructions = wp_json_encode( $this->pickup_instructions );
		if ( false === $instructions ) {
			return false;
		}

		return $this->save_row(
			$wpdb->openbb_cycles,
			array(
				'name'                => $this->name,
				'status'              => $this->status,
				'opens_at'            => $this->opens_at,
				'closes_at'           => $this->closes_at,
				'pickup_opens_at'     => $this->pickup_opens_at,
				'pickup_closes_at'    => $this->pickup_closes_at,
				'pickup_location'     => $this->pickup_location,
				'pickup_instructions' => $instructions,
				'created_at'          => $this->created_at,
				'updated_at'          => $this->updated_at,
				'finalized_at'        => $this->finalized_at,
				'finalized_by'        => $this->finalized_by,
				'unpaid_hold_until'   => $this->unpaid_hold_until,
				'prepaid_hold_until'  => $this->prepaid_hold_until,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Cycles with orders are retained as history and cannot be hard-deleted.
	 *
	 * @return true|WP_Error
	 */
	public function delete() {
		global $wpdb;

		if ( ! $this->id || ! OPENBB_Cycles::get( (int) $this->id ) ) {
			return new WP_Error( 'openbb_cycle_not_found', __( 'This cycle no longer exists.', 'open-bulk-buying' ) );
		}
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->openbb_orders} WHERE cycle_id = %d", $this->id ) ) > 0 ) {
			return new WP_Error( 'openbb_cycle_has_orders', __( 'A cycle with orders cannot be deleted.', 'open-bulk-buying' ) );
		}
		if ( ! $this->delete_row( $wpdb->openbb_cycles ) ) {
			return new WP_Error( 'openbb_cycle_delete_failed', __( 'The cycle could not be deleted.', 'open-bulk-buying' ) );
		}

		return true;
	}

	/**
	 * Checks the cycle's own rules before saving: a name, dates in order, an
	 * ordering window before leaving setup, and no overlap with another upcoming
	 * or open ordering window (members order from one cycle at a time).
	 *
	 * @return true|WP_Error
	 */
	public function validate() {
		global $wpdb;

		if ( '' === trim( $this->name ) ) {
			return self::invalid( __( 'Enter a cycle name.', 'open-bulk-buying' ) );
		}
		$statuses = array( self::STATUS_SETUP, self::STATUS_ORDERING, self::STATUS_PROCESSING, self::STATUS_DISTRIBUTION, self::STATUS_COMPLETE );
		if ( ! in_array( $this->status, $statuses, true ) ) {
			return self::invalid( __( 'Choose a valid cycle status.', 'open-bulk-buying' ) );
		}
		if ( $this->opens_at && $this->closes_at && strtotime( $this->closes_at ) <= strtotime( $this->opens_at ) ) {
			return self::invalid( __( 'Ordering must close after it opens.', 'open-bulk-buying' ) );
		}
		if ( $this->pickup_opens_at && $this->pickup_closes_at && strtotime( $this->pickup_closes_at ) <= strtotime( $this->pickup_opens_at ) ) {
			return self::invalid( __( 'Pickup must end after it starts.', 'open-bulk-buying' ) );
		}
		if ( self::STATUS_SETUP !== $this->status && ( ! $this->opens_at || ! $this->closes_at ) ) {
			return self::invalid( __( 'Set when ordering opens and closes before opening the cycle.', 'open-bulk-buying' ) );
		}

		if ( $this->opens_at && $this->closes_at && in_array( $this->status, array( self::STATUS_SETUP, self::STATUS_ORDERING ), true ) ) {
			$overlap = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT name FROM {$wpdb->openbb_cycles}
					WHERE id != %d AND status IN (%s, %s)
						AND opens_at IS NOT NULL AND closes_at IS NOT NULL
						AND opens_at < %s AND closes_at > %s
					LIMIT 1",
					(int) $this->id,
					self::STATUS_SETUP,
					self::STATUS_ORDERING,
					$this->closes_at,
					$this->opens_at
				)
			);
			if ( null !== $overlap ) {
				return new WP_Error(
					'openbb_cycle_overlap',
					/* translators: %s: name of the other cycle. */
					sprintf( __( 'This ordering window overlaps with "%s".', 'open-bulk-buying' ), $overlap ),
					array( 'status' => 409 )
				);
			}
		}

		return true;
	}

	/**
	 * Translated status labels in lifecycle order. Only call from UI code.
	 */
	public static function status_labels(): array {
		return array(
			self::STATUS_SETUP        => __( 'Setup', 'open-bulk-buying' ),
			self::STATUS_ORDERING     => __( 'Ordering', 'open-bulk-buying' ),
			self::STATUS_PROCESSING   => __( 'Processing', 'open-bulk-buying' ),
			self::STATUS_DISTRIBUTION => __( 'Distribution', 'open-bulk-buying' ),
			self::STATUS_COMPLETE     => __( 'Complete', 'open-bulk-buying' ),
		);
	}

	public function is_active(): bool {
		return in_array( $this->status, self::ACTIVE_STATUSES, true );
	}

	public function hold_until( string $order_status ): ?string {
		return $this->{self::hold_field( $order_status )};
	}

	public static function hold_field( string $order_status ): string {
		return OPENBB_Order::STATUS_PREPAID === $order_status ? 'prepaid_hold_until' : 'unpaid_hold_until';
	}

	/**
	 * Whether members can submit or edit orders at $timestamp (default: now).
	 */
	public function accepts_orders( ?int $timestamp = null ): bool {
		if ( self::STATUS_ORDERING !== $this->status || ! $this->opens_at || ! $this->closes_at ) {
			return false;
		}

		$timestamp = $timestamp ?? time();

		return $timestamp >= strtotime( $this->opens_at . ' UTC' )
			&& $timestamp < strtotime( $this->closes_at . ' UTC' );
	}

	public function get_pickup_instructions( ?string $language = null ): string {
		return openbb_localized( $this->pickup_instructions, $language );
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'openbb_invalid_cycle', $message, array( 'status' => 400 ) );
	}
}
