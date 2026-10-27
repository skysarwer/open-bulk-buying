import { __, sprintf } from '@wordpress/i18n';

/**
 * Boot data printed by OPENBB_Admin::boot_data() as window.openbbAdmin.
 */
const boot = window.openbbAdmin || {};

export const config = {
	screen: boot.screen || '',
	currency: boot.currency || 'CAD',
	locale: boot.locale || undefined,
	unitTypes: boot.unitTypes || {},
	unitConversions: boot.unitConversions || {},
	languages: boot.languages || [],
	defaultLanguage: boot.defaultLanguage || '',
	currentLanguage: ( boot.locale || '' ).split( /[-_]/ )[ 0 ].toLowerCase(),
	translationLanguages: boot.translationLanguages || [],
	memberStatuses: boot.memberStatuses || {},
	// Team role key => label, in display order.
	coopRoles: boot.coopRoles || {},
	cycleStatuses: boot.cycleStatuses || {},
	orderStatuses: boot.orderStatuses || {},
	stockReasons: boot.stockReasons || {},
	manualStockReasons: boot.manualStockReasons || [],
	caps: boot.caps || {},
	urls: boot.urls || {},
	// Screens the user can open, in menu order: { name, slug, title, url }.
	screens: boot.screens || [],
};

export const can = ( cap ) => !! config.caps[ cap ];

/**
 * Turns a { value: label } map into DataViews elements.
 *
 * @param {Object} map
 * @return {Array<{value: string, label: string}>} Elements.
 */
export const toElements = ( map ) =>
	Object.entries( map ).map( ( [ value, label ] ) => ( { value, label } ) );

export const languageLabel = ( code ) => code.toUpperCase();

/**
 * A localized map's text, falling back to the default.
 *
 * @param {Object} value    Map of language keys to text.
 * @param {string} language Language code; defaults to the admin language.
 * @return {string} Display text.
 */
export const localized = ( value, language = config.currentLanguage ) =>
	( language && value?.[ language ] ) || value?.default || '';

/**
 * Display text for a flattened REST field, without changing its editable value.
 *
 * @param {Object} item  REST record.
 * @param {string} field Field name.
 * @return {string} Display text in the admin language.
 */
export const localizedRecordField = ( item, field ) =>
	localized( {
		default: item?.[ field ],
		[ config.currentLanguage ]: item?.[ `${ field }_${ config.currentLanguage }` ],
	} );

/**
 * Labels only: the default storage key continues to represent the site language.
 *
 * @param {string} label    Field label.
 * @param {string} language Storage language key.
 * @return {string} Label relative to the current admin interface language.
 */
export const localizedFieldLabel = ( label, language = 'default' ) => {
	const code = language === 'default' ? config.defaultLanguage : language;
	return ! code || code === config.currentLanguage
		? label
		: sprintf(
				/* translators: 1: field name, 2: language code. */
				__( '%1$s (%2$s)', 'open-bulk-buying' ),
				label,
				languageLabel( code )
		  );
};

export const queryArg = ( name ) =>
	new URLSearchParams( window.location.search ).get( name );
