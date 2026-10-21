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

	const DB_VERSION        = '2';
	const DB_VERSION_OPTION = 'openbb_db_version';

	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
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

		// Autoloaded because maybe_upgrade() reads it on every request.
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );

		return true;
	}
}
