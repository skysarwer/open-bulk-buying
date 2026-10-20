<?php
/**
 * Shared fixtures for co-op users, cycles and orders.
 *
 * @package Open_Bulk_Buying
 */

abstract class OPENBB_TestCase extends WP_UnitTestCase {

	/** @var int Open plugin transactions, run as nested savepoints. */
	private $transaction_depth = 0;

	public function set_up(): void {
		parent::set_up();
		$this->transaction_depth = 0;
		add_filter( 'query', array( $this, 'nest_transaction' ) );
	}

	public function tear_down(): void {
		remove_filter( 'query', array( $this, 'nest_transaction' ) );
		parent::tear_down();
	}

	/**
	 * The plugin's own transactions would commit the per-test transaction (MySQL commits
	 * implicitly on START TRANSACTION), leaking data into later tests; run them as savepoints.
	 */
	public function nest_transaction( $query ) {
		switch ( strtoupper( trim( (string) $query, " \t\n\r;" ) ) ) {
			case 'START TRANSACTION':
				return 'SAVEPOINT openbb_test_' . ( ++$this->transaction_depth );
			case 'COMMIT':
				return $this->transaction_depth > 0 ? 'RELEASE SAVEPOINT openbb_test_' . ( $this->transaction_depth-- ) : 'DO 0';
			case 'ROLLBACK':
				return $this->transaction_depth > 0 ? 'ROLLBACK TO SAVEPOINT openbb_test_' . ( $this->transaction_depth-- ) : 'DO 0';
		}

		return $query;
	}

	/**
	 * Creates a user with a WordPress role and, written directly as the signup flow
	 * would, a co-op role and grants.
	 */
	protected function make_user( string $coop_role = '', string $wp_role = 'subscriber', array $grants = array() ): int {
		$user_id = self::factory()->user->create( array( 'role' => $wp_role ) );
		if ( '' !== $coop_role ) {
			openbb_update_member_field( $user_id, 'coop_role', $coop_role );
		}
		if ( $grants ) {
			openbb_update_member_field( $user_id, 'grants', $grants );
		}

		return $user_id;
	}

	protected function make_cycle( string $status ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->openbb_cycles,
			array(
				'name'       => 'Test cycle',
				'status'     => $status,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	protected function make_order( int $cycle_id, int $user_id ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->openbb_orders,
			array(
				'cycle_id'   => $cycle_id,
				'user_id'    => $user_id,
				'status'     => OPENBB_Order::STATUS_SUBMITTED,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}
}
