import { addQueryArgs } from '@wordpress/url';

import { config } from './config';

/**
 * URL of the printable order slips page.
 *
 * @param {Object}   args
 * @param {number[]} args.orders  Order ids; takes precedence over the cycle.
 * @param {number}   args.cycleId Every eligible order in this cycle.
 * @return {string} URL.
 */
export function orderSlipUrl( { orders, cycleId } ) {
	// The base URL carries a print-only nonce.
	const args = { autoprint: 1 };
	if ( orders?.length ) {
		args.orders = orders.join( ',' );
	} else {
		args.cycle_id = cycleId;
	}
	return addQueryArgs( config.urls.orderSlip, args );
}

export function openOrderSlips( args ) {
	window.open( orderSlipUrl( args ), '_blank', 'noopener' );
}
