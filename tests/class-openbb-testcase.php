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
		OPENBB_Roles::flush();
	}

	public function tear_down(): void {
		remove_filter( 'query', array( $this, 'nest_transaction' ) );
		parent::tear_down();
		// The rollback restores options without firing their hooks, and test filters are gone.
		OPENBB_Roles::flush();
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
	 * Creates a user with a WordPress role and, written directly as the signup flow would,
	 * a co-op membership. $membership is a status (applicant, active, inactive, declined), or
	 * 'member' for an active member with no team roles, or a role name (coordinator,
	 * manager, ...) meaning an active member holding that role; $roles are further roles held.
	 */
	protected function make_user( string $membership = '', string $wp_role = 'subscriber', array $roles = array() ): int {
		$user_id = self::factory()->user->create( array( 'role' => $wp_role ) );
		if ( '' === $membership ) {
			return $user_id;
		}

		$status = in_array( $membership, OPENBB_Access::STATUSES, true ) ? $membership : OPENBB_Access::STATUS_ACTIVE;
		if ( ! in_array( $membership, OPENBB_Access::STATUSES, true ) && 'member' !== $membership ) {
			array_unshift( $roles, $membership );
		}

		openbb_update_member_field( $user_id, 'status', $status );
		if ( in_array( $status, array( OPENBB_Access::STATUS_ACTIVE, OPENBB_Access::STATUS_INACTIVE ), true ) ) {
			openbb_update_member_field( $user_id, 'joined_at', '2024-01-01 00:00:00' );
		}
		foreach ( array_unique( $roles ) as $role ) {
			add_user_meta( $user_id, openbb_member_meta_key( 'roles' ), $role );
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
