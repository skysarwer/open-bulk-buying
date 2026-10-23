<?php
/**
 * Removes co-op user data (membership status, co-op roles and member fields) and
 * role changes from every site.
 * WordPress roles are never touched, so no user is left without a role.
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function openbb_uninstall_site(): void {
	global $wpdb;

	delete_option( 'openbb_general_settings' );
	delete_option( 'openbb_roles' );

	// Per-site keys only: the main site's "wp_openbb_" prefix doesn't match a subsite's "wp_2_openbb_".
	$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $wpdb->get_blog_prefix() . 'openbb_' ) . '%' ) );
	foreach ( $keys as $key ) {
		delete_metadata( 'user', 0, $key, '', true );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( $site_id );
		openbb_uninstall_site();
		restore_current_blog();
	}
} else {
	openbb_uninstall_site();
}
