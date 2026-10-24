<?php
/**
 * Site-local co-op settings. Fee amounts use integer currency minor units.
 *
 * @package Open_Bulk_Buying
 */

defined( 'ABSPATH' ) || exit;

final class OPENBB_Settings {

	const GENERAL_OPTION = 'openbb_general_settings';

	public static function get_general(): array {
		$settings = get_option( self::GENERAL_OPTION, array() );
		// Preserve the passwordless preference until the renamed setting is next saved.
		if ( ! array_key_exists( 'allow_login_codes', $settings ) && array_key_exists( 'allow_login_links', $settings ) ) {
			$settings['allow_login_codes'] = $settings['allow_login_links'];
		}
		unset( $settings['allow_login_links'] );

		return array_merge(
			array(
				'allow_signups'                 => false,
				'allow_login_codes'             => false,
				'captcha_provider'              => 'none',
				'turnstile_site_key'            => '',
				'require_member_approval'       => true,
				'membership_fee_type'           => 'one_time',
				'membership_fee_amount'         => 0,
				'membership_fee_order_interval' => 1,
			),
			$settings
		);
	}
}
