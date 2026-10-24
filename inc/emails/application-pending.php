<?php
/**
 * Membership application awaiting approval. $data: first_name.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

/* translators: 1: member's first name, 2: site name. */
$intro = sprintf( __( "Hi %1\$s,\n\nThanks for applying to join %2\$s. A coordinator will review your application. You'll be able to place orders once it's approved.", 'open-bulk-buying' ), $data['first_name'], $site );

return array(
	/* translators: %s: site name. */
	'subject' => sprintf( __( '[%s] We received your membership application', 'open-bulk-buying' ), $site ),
	'message' => $intro . "\n\n" . ( require __DIR__ . '/member-login-instructions.php' ),
);
