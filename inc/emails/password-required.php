<?php
/**
 * Login-link request for an account requiring a password. $data: page.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

return array(
	/* translators: %s: site name. */
	'subject' => sprintf( __( '[%s] Log in with your password', 'open-bulk-buying' ), $site ),
	'message' => sprintf(
		/* translators: 1: site name, 2: login page, 3: lost password page. */
		__( "Someone asked for a login link for your account on %1\$s.\n\nAccounts that can manage the site can't log in with an emailed link. Please log in with your password instead:\n\n%2\$s\n\nIf you've forgotten your password, you can reset it here:\n\n%3\$s\n\nIf you didn't ask to log in, you can ignore this email.", 'open-bulk-buying' ),
		$site,
		$data['page'],
		wp_lostpassword_url( $data['page'] )
	),
);
