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
	'message' => '<p>' . esc_html(
		sprintf(
			/* translators: 1: site name. */
			__( 'Enter this code to finish joining %s:', 'open-bulk-buying' ),
			$site
		)
	) . '</p><p style="font-family: monospace; font-size: 2em; font-weight: bold; letter-spacing: 0.1em;">' . esc_html( $data['code'] ) . '</p><p>' . esc_html(
		sprintf(
			/* translators: %d: minutes until the code expires. */
			__( 'The code expires in %d minutes.', 'open-bulk-buying' ),
			$data['minutes']
		)
	) . '</p><p>' . esc_html__( "If you didn't ask to join, you can ignore this email.", 'open-bulk-buying' ) . '</p>',
);
