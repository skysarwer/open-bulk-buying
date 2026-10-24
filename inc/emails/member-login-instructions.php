<?php
/**
 * Shared login instructions for membership emails.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

return sprintf(
	/* translators: 1: login URL, 2: lost password URL. */
	__( "Log in at any time:\n%1\$s\n\nTo choose a password, use \"Lost your password?\":\n%2\$s", 'open-bulk-buying' ),
	wp_login_url(),
	wp_lostpassword_url()
);
