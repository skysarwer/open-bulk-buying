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
		return array_merge(
			array(
				'allow_signups'                 => false,
				'require_member_approval'       => true,
				'membership_fee_type'           => 'one_time',
				'membership_fee_amount'         => 0,
				'membership_fee_order_interval' => 1,
			),
			get_option( self::GENERAL_OPTION, array() )
		);
	}
}
