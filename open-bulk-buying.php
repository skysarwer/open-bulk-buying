<?php 
/**
 * Plugin Name: Open Bulk Buying
 * Description: Inventory management, order cycles and order forms for Food Coops
 * Version: 1.1
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Evan Buckiewicz
 * Author URI: https://evan.works
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: open-bulk-buying
 * Domain Path: /languages
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define('OPENBB_VERSION', '1.1');
define('OPENBB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OPENBB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OPENBB_META_PREFIX', 'openbb_');

// Listing tables in $wpdb->tables makes switch_to_blog() re-prefix them on multisite.
global $wpdb;
foreach ( array( 'openbb_cycles', 'openbb_orders', 'openbb_order_lines', 'openbb_order_events', 'openbb_stock_moves', 'openbb_rules_versions' ) as $openbb_table ) {
	$wpdb->tables[]      = $openbb_table;
	$wpdb->$openbb_table = $wpdb->prefix . $openbb_table;
}

require_once OPENBB_PLUGIN_DIR . 'inc/i18n/localized-text.php';
require_once OPENBB_PLUGIN_DIR . 'inc/i18n/currency.php';
require_once OPENBB_PLUGIN_DIR . 'inc/class-openbb-settings.php';
require_once OPENBB_PLUGIN_DIR . 'inc/class-openbb-rate-limit.php';
require_once OPENBB_PLUGIN_DIR . 'inc/class-openbb-captcha.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/class-openbb-entity.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/member/user-meta.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/member/class-openbb-member.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/member/class-openbb-profile-fields.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/member/class-openbb-email-code.php';
require_once OPENBB_PLUGIN_DIR . 'inc/emails/class-openbb-emails.php';
require_once OPENBB_PLUGIN_DIR . 'inc/emails/class-openbb-order-emails.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/member/class-openbb-signup.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/member/class-openbb-login-code.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/product/units.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/product/post-type.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/product/class-openbb-product.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/product/class-openbb-catalog.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/cycle/class-openbb-cycle.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/cycle/class-openbb-cycles.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/order/class-openbb-order.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/order/class-openbb-order-line.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/order/class-openbb-orders.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/member/class-openbb-membership-fees.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/inventory/class-openbb-stock-move.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/inventory/class-openbb-inventory.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/order/class-openbb-order-policy.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/order/class-openbb-order-editor.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/order/class-openbb-order-lifecycle.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/order/class-openbb-order-aid.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/order/class-openbb-member-orders.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/rules/class-openbb-rules-version.php';
require_once OPENBB_PLUGIN_DIR . 'inc/model/rules/class-openbb-rules.php';
require_once OPENBB_PLUGIN_DIR . 'inc/access/class-openbb-roles.php';
require_once OPENBB_PLUGIN_DIR . 'inc/access/class-openbb-access.php';
require_once OPENBB_PLUGIN_DIR . 'inc/access/user-profile.php';
require_once OPENBB_PLUGIN_DIR . 'inc/class-openbb-installer.php';
require_once OPENBB_PLUGIN_DIR . 'inc/class-openbb-maintenance.php';
require_once OPENBB_PLUGIN_DIR . 'inc/class-openbb-demo-catalog.php';
require_once OPENBB_PLUGIN_DIR . 'inc/privacy/class-openbb-privacy-provider.php';
require_once OPENBB_PLUGIN_DIR . 'inc/privacy/class-openbb-privacy-profile-fields.php';
require_once OPENBB_PLUGIN_DIR . 'inc/privacy/class-openbb-privacy.php';
require_once OPENBB_PLUGIN_DIR . 'inc/admin/class-openbb-admin.php';
require_once OPENBB_PLUGIN_DIR . 'inc/rest/class-openbb-rest-controller.php';
require_once OPENBB_PLUGIN_DIR . 'inc/rest/admin/class-openbb-rest-products.php';
require_once OPENBB_PLUGIN_DIR . 'inc/rest/admin/class-openbb-rest-cycles.php';
require_once OPENBB_PLUGIN_DIR . 'inc/rest/admin/class-openbb-rest-members.php';
require_once OPENBB_PLUGIN_DIR . 'inc/rest/admin/class-openbb-rest-orders.php';
require_once OPENBB_PLUGIN_DIR . 'inc/rest/admin/class-openbb-rest-settings.php';
require_once OPENBB_PLUGIN_DIR . 'inc/rest/frontend/class-openbb-rest-signup.php';
require_once OPENBB_PLUGIN_DIR . 'inc/rest/frontend/class-openbb-rest-login.php';
require_once OPENBB_PLUGIN_DIR . 'inc/rest/frontend/class-openbb-rest-order.php';
require_once OPENBB_PLUGIN_DIR . 'inc/frontend/class-openbb-frontend.php';
require_once OPENBB_PLUGIN_DIR . 'inc/frontend/fields.php';
require_once OPENBB_PLUGIN_DIR . 'inc/frontend/class-openbb-signup-view.php';
require_once OPENBB_PLUGIN_DIR . 'inc/frontend/class-openbb-login-view.php';
require_once OPENBB_PLUGIN_DIR . 'inc/frontend/class-openbb-order-form-view.php';

register_activation_hook( __FILE__, array( 'OPENBB_Installer', 'install' ) );
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'open-bulk-buying', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	},
	0
);
add_action( 'plugins_loaded', array( 'OPENBB_Installer', 'maybe_upgrade' ) );
add_filter( 'user_has_cap', array( 'OPENBB_Access', 'filter_user_has_cap' ), 10, 4 );
add_filter( 'map_meta_cap', array( 'OPENBB_Access', 'filter_map_meta_cap' ), 10, 4 );
add_action( 'openbb_member_status_changed', array( 'OPENBB_Signup', 'on_member_status_changed' ), 10, 3 );
add_action( 'openbb_order_saved', array( 'OPENBB_Order_Emails', 'on_saved' ), 10, 2 );
foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $openbb_meta_action ) {
	add_action( $openbb_meta_action, array( 'OPENBB_Access', 'on_user_meta_change' ) );
}
foreach ( array( 'add_option_' . OPENBB_Roles::OPTION, 'update_option_' . OPENBB_Roles::OPTION, 'delete_option_' . OPENBB_Roles::OPTION, 'switch_blog' ) as $openbb_roles_action ) {
	add_action( $openbb_roles_action, array( 'OPENBB_Roles', 'flush' ) );
}
unset( $openbb_meta_action, $openbb_roles_action );
add_filter( 'manage_users_columns', 'openbb_user_access_column' );
add_filter( 'manage_users_custom_column', 'openbb_user_access_column_value', 10, 3 );
add_action( 'init', 'openbb_register_product_post_type' );
add_action( 'init', 'openbb_register_member_meta' );
add_action( 'init', array( 'OPENBB_Profile_Fields', 'register_meta' ) );
OPENBB_Admin::register_hooks();
OPENBB_REST_Controller::register_hooks();
OPENBB_Frontend::register_hooks();
OPENBB_Privacy::register_hooks();
add_action( 'set_object_terms', 'openbb_enforce_single_term', 10, 4 );
add_filter( 'add_post_metadata', 'openbb_guard_product_unit_meta', 10, 4 );
add_filter( 'update_post_metadata', 'openbb_guard_product_unit_meta', 10, 4 );
add_filter( 'delete_post_metadata', 'openbb_guard_product_unit_meta', 10, 4 );
