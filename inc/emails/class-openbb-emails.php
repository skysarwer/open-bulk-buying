<?php
/**
 * Email templates. Sending and public filters stay with their services.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Emails {

	/**
	 * @return array{subject: string, message: string}
	 */
	public static function render( string $template, array $data = array() ): array {
		$templates = array( 'signup-code', 'account-exists', 'application-pending', 'welcome', 'login-code', 'password-required', 'order-event' );
		if ( ! in_array( $template, $templates, true ) ) {
			throw new InvalidArgumentException( 'Unknown co-op email template: ' . $template );
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$signature = self::signature();
		$mail = require __DIR__ . '/' . $template . '.php';

		// Full-document receipts place the signature inside their HTML layout.
		if ( 'order-event' !== $template && '' !== $signature ) {
			$mail['message'] .= in_array( $template, array( 'signup-code', 'login-code' ), true )
				? '<p>' . nl2br( esc_html( $signature ) ) . '</p>'
				: "\n\n" . $signature;
		}
		return $mail;
	}

	public static function signature(): string {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return apply_filters( 'openbb_email_signature', __( 'Thank you!', 'open-bulk-buying' ) . "\n" . $site );
	}
}
