<?php
/**
 * Active member welcome. $data: first_name.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

/* translators: 1: member's first name, 2: site name. */
$intro = sprintf( __( "Hi %1\$s,\n\nWelcome to %2\$s! You can place an order whenever an order cycle is open.", 'open-bulk-buying' ), $data['first_name'], $site );

return array(
	/* translators: %s: site name. */
	'subject' => sprintf( __( '[%s] Welcome to the co-op', 'open-bulk-buying' ), $site ),
	'message' => $intro . "\n\n" . ( require __DIR__ . '/member-login-instructions.php' ),
);
