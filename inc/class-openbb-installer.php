<?php
/**
 * Creates and upgrades the custom tables.
 *
 * Bump DB_VERSION whenever a schema() changes; dbDelta() applies the difference on
 * the next request. Activation hooks don't run on plugin updates, hence the version
 * check on every load.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OPENBB_Installer {

	const DB_VERSION        = '4';
	const DB_VERSION_OPTION = 'openbb_db_version';

	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			if ( ! self::install() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[open-bulk-buying] Database upgrade failed. Preparation cleanup remains disabled; the upgrade will retry on the next request.' );
			}
		}
	}

	public static function schema(): array {
		return array_merge(
			OPENBB_Cycles::schema(),
			OPENBB_Orders::schema(),
			OPENBB_Inventory::schema(),
			OPENBB_Rules::schema()
		);
	}

	/**
	 * Records the schema version only once every table exists, so a failed install retries on the next request.
	 */
	public static function install(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$schema = self::schema();
		foreach ( $schema as $sql ) {
			dbDelta( $sql );
		}

		foreach ( array_keys( $schema ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}

		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->openbb_cycles} LIKE 'cleanup_locked_at'" ) ) {
			return false;
		}
		if ( version_compare( (string) get_option( self::DB_VERSION_OPTION, '0' ), '3', '<' )
			&& ! self::protect_existing_cycles() ) {
			return false;
		}

		// Autoloaded because maybe_upgrade() reads it on every request.
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->openbb_order_lines} LIKE 'line_type'" ) ) {
			return false;
		}
		$cycle_column = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->openbb_orders} LIKE 'cycle_id'" );
		// dbDelta compares types and defaults, but does not detect nullability changes.
		if ( $cycle_column && 'YES' !== $cycle_column->Null ) {
			if ( false === $wpdb->query( "ALTER TABLE {$wpdb->openbb_orders} MODIFY cycle_id bigint(20) unsigned NULL DEFAULT NULL" ) ) {
				return false;
			}
			$cycle_column = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->openbb_orders} LIKE 'cycle_id'" );
		}
		if ( ! $cycle_column || 'YES' !== $cycle_column->Null ) {
			return false;
		}
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );

		return true;
	}

	/**
	 * Old events have no cycle-stage snapshot. Protect ambiguous historical orders
	 * rather than guessing that a cycle currently in Setup has never gone live.
	 */
	public static function protect_existing_cycles(): bool {
		global $wpdb;

		if ( ! OPENBB_Inventory::lock() ) {
			return false;
		}
		try {
			return false !== $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->openbb_cycles} c SET cleanup_locked_at = %s
					WHERE cleanup_locked_at IS NULL AND (
						EXISTS (SELECT 1 FROM {$wpdb->openbb_orders} o WHERE o.cycle_id = c.id
							AND (o.status != 'draft' OR o.submitted_at IS NOT NULL
								OR o.paid_at IS NOT NULL OR o.paid_by IS NOT NULL OR o.paid_amount IS NOT NULL OR o.paid_currency IS NOT NULL
								OR o.collected_at IS NOT NULL OR o.collected_by IS NOT NULL OR o.aid_approved > 0))
						OR EXISTS (SELECT 1 FROM {$wpdb->openbb_stock_moves} m WHERE m.cycle_id = c.id AND m.order_id IS NOT NULL)
						OR EXISTS (SELECT 1 FROM {$wpdb->openbb_orders} o
							INNER JOIN {$wpdb->openbb_stock_moves} m ON m.order_id = o.id WHERE o.cycle_id = c.id)
						OR EXISTS (SELECT 1 FROM {$wpdb->openbb_orders} o
							INNER JOIN {$wpdb->openbb_order_events} e ON e.order_id = o.id WHERE o.cycle_id = c.id
							AND (e.from_status IN ('submitted', 'cancelled', 'prepaid', 'fulfilled')
								OR e.to_status IN ('submitted', 'cancelled', 'prepaid', 'fulfilled')))
					)",
					current_time( 'mysql', true )
				)
			);
		} finally {
			OPENBB_Inventory::unlock();
		}
	}
}
