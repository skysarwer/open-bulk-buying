<?php
/**
 * Configuration for the WordPress PHPUnit test library.
 *
 * Copy to tests/wp-tests-config.php (gitignored) or let bin/install-wp-tests
 * generate it. Every value can also be supplied through an environment variable,
 * so CI can use this sample file unchanged.
 *
 * THE TEST DATABASE IS DROPPED AND RECREATED ON EVERY RUN. Never point it at a
 * database that holds anything you want to keep.
 *
 * @package Open_Bulk_Buying
 */

// WordPress core files to test against. Defaults to the installation this plugin lives in.
$openbb_core_dir = getenv( 'WP_CORE_DIR' );
define( 'ABSPATH', ( $openbb_core_dir ? rtrim( $openbb_core_dir, '/\\' ) : dirname( __DIR__, 4 ) ) . '/' );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'openbb_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Open Bulk Buying Test Site' );
define( 'WP_PHP_BINARY', 'php' );

define( 'WP_DEBUG', true );
define( 'WPLANG', '' );
