<?php
/**
 * Plain-text email templates. Sending and public filters stay with their services.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Emails {

	/**
	 * @return array{subject: string, message: string}
	 */
	public static function render( string $template, array $data = array() ): array {
		$templates = array( 'signup-code', 'account-exists', 'welcome', 'login-link', 'password-required' );
		if ( ! in_array( $template, $templates, true ) ) {
			throw new InvalidArgumentException( 'Unknown co-op email template: ' . $template );
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		return require __DIR__ . '/' . $template . '.php';
	}
}
