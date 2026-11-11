<?php
/**
 * PHPUnit bootstrap.
 *
 * Locates the WordPress test library and its configuration, then loads the plugin
 * as a must-use plugin before WordPress installs itself into the test database.
 *
 * Test library, in order of preference:
 *   1. WP_TESTS_DIR            – a wordpress-develop checkout's tests/phpunit directory.
 *   2. vendor/wp-phpunit       – the Composer-installed copy of the same library.
 *
 * Configuration (only needed for option 2):
 *   WP_PHPUNIT__TESTS_CONFIG   – path to a wp-tests-config.php; defaults to
 *                                tests/wp-tests-config.php, then the bundled sample
 *                                (which reads its settings from environment variables).
 *
 * See docs/testing.md.
 *
 * @package Open_Bulk_Buying
 */

$openbb_plugin_dir = dirname( __DIR__ );
$openbb_tests_dir  = getenv( 'WP_TESTS_DIR' );

if ( ! $openbb_tests_dir ) {
	$openbb_tests_dir = $openbb_plugin_dir . '/vendor/wp-phpunit/wp-phpunit';

	if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
		foreach ( array( __DIR__ . '/wp-tests-config.php', __DIR__ . '/wp-tests-config-sample.php' ) as $openbb_config ) {
			if ( file_exists( $openbb_config ) ) {
				putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $openbb_config );
				break;
			}
		}
	}
}

if ( ! file_exists( $openbb_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found. Run `composer install`, or set WP_TESTS_DIR to a wordpress-develop tests/phpunit directory. See docs/testing.md.\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $openbb_plugin_dir . '/vendor/yoast/phpunit-polyfills' );

require_once $openbb_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $openbb_plugin_dir ) {
		require $openbb_plugin_dir . '/open-bulk-buying.php';
	}
);

require $openbb_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-openbb-testcase.php';
