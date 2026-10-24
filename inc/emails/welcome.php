<?php
/**
 * Application received or active member welcome. $data: first_name, is_applicant.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

if ( $data['is_applicant'] ) {
	/* translators: %s: site name. */
	$subject = sprintf( __( '[%s] We received your membership application', 'open-bulk-buying' ), $site );
	/* translators: 1: member's first name, 2: site name. */
	$intro = sprintf( __( "Hi %1\$s,\n\nThanks for applying to join %2\$s. A coordinator will review your application. You'll be able to place orders once it's approved.", 'open-bulk-buying' ), $data['first_name'], $site );
} else {
	/* translators: %s: site name. */
	$subject = sprintf( __( '[%s] Welcome to the co-op', 'open-bulk-buying' ), $site );
	/* translators: 1: member's first name, 2: site name. */
	$intro = sprintf( __( "Hi %1\$s,\n\nWelcome to %2\$s! You can place an order whenever an order cycle is open.", 'open-bulk-buying' ), $data['first_name'], $site );
}

return array(
	'subject' => $subject,
	'message' => $intro . "\n\n" . sprintf(
		/* translators: 1: login URL, 2: lost password URL. */
		__( "Log in at any time:\n%1\$s\n\nTo choose a password, use \"Lost your password?\":\n%2\$s", 'open-bulk-buying' ),
		wp_login_url(),
		wp_lostpassword_url()
	),
);
