import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import { config } from './config';

// Order statuses that have something to hand out.
export const SLIP_STATUSES = [ 'submitted', 'prepaid', 'fulfilled' ];

/**
 * URL of the printable order slips page.
 *
 * @param {Object}   args
 * @param {number[]} args.orders   Order ids; takes precedence over the cycle.
 * @param {number}   args.cycleId  Every eligible order in this cycle.
 * @param {string[]} args.statuses Limit the cycle's orders to these statuses.
 * @param {string}   args.paid     'all', 'paid' or 'unpaid'.
 * @param {string}   args.search   Limit the cycle's orders to a search.
 * @return {string} URL.
 */
export function orderSlipUrl( {
	orders,
	cycleId,
	statuses,
	paid = 'all',
	search = '',
} ) {
	// The middleware nonce is refreshed by apiFetch; the boot nonce is the fallback.
	const nonce = apiFetch.nonceMiddleware?.nonce || config.restNonce;
	const args = { _wpnonce: nonce, autoprint: 1 };
	if ( orders?.length ) {
		args.orders = orders.join( ',' );
	} else {
		args.cycle_id = cycleId;
		if ( statuses?.length ) {
			args.status = statuses.join( ',' );
		}
		if ( paid !== 'all' ) {
			args.paid = paid;
		}
		if ( search ) {
			args.search = search;
		}
	}
	return addQueryArgs( config.urls.orderSlip, args );
}

export function openOrderSlips( args ) {
	window.open( orderSlipUrl( args ), '_blank', 'noopener' );
}
