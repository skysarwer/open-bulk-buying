<?php
/**
 * Passwordless login code. $data: code, minutes.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

return array(
	/* translators: 1: site name, 2: the code. */
	'subject' => sprintf( __( '[%1$s] Your login code: %2$s', 'open-bulk-buying' ), $site, $data['code'] ),
	'message' => sprintf(
		/* translators: 1: site name, 2: the code, 3: minutes until the code expires. */
		__( "Enter this code in the browser where you asked to log in to %1\$s:\n\n%2\$s\n\nThe code works once and expires in %3\$d minutes. Don't share it with anyone.\n\nIf you didn't ask to log in, you can ignore this email.", 'open-bulk-buying' ),
		$site,
		$data['code'],
		$data['minutes']
	),
);
