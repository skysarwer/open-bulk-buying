<?php
/**
 * Signup verification code. $data: code, minutes.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

return array(
	/* translators: 1: site name, 2: the code. */
	'subject' => sprintf( __( '[%1$s] Your sign-up code: %2$s', 'open-bulk-buying' ), $site, $data['code'] ),
	'message' => sprintf(
		/* translators: 1: site name, 2: the code, 3: minutes until the code expires. */
		__( "Enter this code to finish joining %1\$s:\n\n%2\$s\n\nThe code expires in %3\$d minutes.\n\nIf you didn't ask to join, you can ignore this email.", 'open-bulk-buying' ),
		$site,
		$data['code'],
		$data['minutes']
	),
);
