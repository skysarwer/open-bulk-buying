<?php
/**
 * Single-use login link. $data: url, minutes.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

return array(
	/* translators: %s: site name. */
	'subject' => sprintf( __( '[%s] Your login link', 'open-bulk-buying' ), $site ),
	'message' => sprintf(
		/* translators: 1: site name, 2: login link, 3: minutes until the link expires. */
		__( "Use this link to log in to %1\$s:\n\n%2\$s\n\nThe link works once and expires in %3\$d minutes.\n\nIf you didn't ask to log in, you can ignore this email.", 'open-bulk-buying' ),
		$site,
		$data['url'],
		$data['minutes']
	),
);
