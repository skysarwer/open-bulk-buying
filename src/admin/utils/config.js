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

export const queryArg = ( name ) =>
	new URLSearchParams( window.location.search ).get( name );
