<?php
/**
 * Signup attempt for an existing account.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

return array(
	/* translators: %s: site name. */
	'subject' => sprintf( __( '[%s] You already have an account', 'open-bulk-buying' ), $site ),
	'message' => sprintf(
		/* translators: 1: site name, 2: login URL, 3: lost password URL. */
		__( "Someone, hopefully you, tried to sign up to %1\$s with this email address. You already have an account, so there's no code to enter.\n\nLog in to finish joining:\n%2\$s\n\nForgot your password? Reset it here:\n%3\$s\n\nIf this wasn't you, you can ignore this email.", 'open-bulk-buying' ),
		$site,
		wp_login_url(),
		wp_lostpassword_url()
	),
);
