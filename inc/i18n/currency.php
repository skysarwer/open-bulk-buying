<?php
/**
 * Currency. Amounts are stored as integer minor units (e.g. cents).
 *
 * @package Open_Bulk_Buying
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OPENBB_CURRENCY_OPTION = 'openbb_currency';

/**
 * ISO 4217 code for new orders. Until the Settings page (012) manages it, set the
 * openbb_currency option or filter 'openbb_currency'.
 */
function openbb_currency(): string {
	$currency = strtoupper( (string) apply_filters( 'openbb_currency', get_option( OPENBB_CURRENCY_OPTION, 'CAD' ) ) );

	return preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'CAD';
}

/**
 * Formats an amount in minor units for display, e.g. 2500 => "$25.00" (in the current locale).
 */
function openbb_format_money( int $minor, ?string $currency = null ): string {
	$currency = $currency ?? openbb_currency();
	$digits   = openbb_currency_digits( $currency );

	if ( class_exists( 'NumberFormatter' ) ) {
		$formatter = new NumberFormatter( determine_locale(), NumberFormatter::CURRENCY );
		$formatter->setTextAttribute( NumberFormatter::CURRENCY_CODE, $currency );
		$formatted = $formatter->formatCurrency( $minor / ( 10 ** $digits ), $currency );
		if ( false !== $formatted ) {
			return $formatted;
		}
	}

	return number_format_i18n( $minor / ( 10 ** $digits ), $digits ) . ' ' . $currency;
}

/**
 * Minor-unit digits of $currency, e.g. 2 for CAD (cents), 0 for JPY.
 */
function openbb_currency_digits( ?string $currency = null ): int {
	$currency = $currency ?? openbb_currency();

	if ( class_exists( 'NumberFormatter' ) ) {
		$formatter = new NumberFormatter( determine_locale(), NumberFormatter::CURRENCY );
		$formatter->setTextAttribute( NumberFormatter::CURRENCY_CODE, $currency );

		return (int) $formatter->getAttribute( NumberFormatter::FRACTION_DIGITS );
	}

	$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	return in_array( $currency, $zero_decimal, true ) ? 0 : 2;
}
