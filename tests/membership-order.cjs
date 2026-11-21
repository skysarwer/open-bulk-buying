const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const path = require( 'node:path' );
const { test } = require( 'node:test' );
const { runInNewContext } = require( 'node:vm' );
const { transformSync } = require( '@babel/core' );

const { code } = transformSync(
	readFileSync( path.join( __dirname, '..', 'assets', 'js', 'order.js' ), 'utf8' ),
	{
		babelrc: false,
		configFile: false,
		plugins: [ '@babel/plugin-transform-modules-commonjs' ],
	}
);

function basket( overrides = {} ) {
	const state = {
		items: [ { id: 1, qty: 2, own: false } ],
		products: { 1: { price: 250, charge: 100, max: 10 } },
		membershipFee: 1000,
		donation: 200,
		aidApproved: 0,
		extraCredit: 0,
		currency: 'CAD',
		digits: 2,
		locale: 'en-CA',
		...overrides,
	};
	runInNewContext( code, {
		exports: {},
		require: ( name ) => {
			if ( name === '@wordpress/interactivity' ) {
				return {
					store: ( namespace, value ) => {
						Object.defineProperties( state, Object.getOwnPropertyDescriptors( value.state ) );
						return { state };
					},
				};
			}
			assert.equal( name, '@open-bulk-buying/forms' );
			return {};
		},
	} );
	return state;
}

test( 'membership fee is included exactly once in basket total', () => {
	const state = basket();
	assert.equal( state.totalText, '$19.00' );
	assert.equal( state.hasMembershipFee, true );
	assert.equal( state.membershipFeeText, '$10.00' );
	assert.equal( state.count, 2, 'The fee is not a product quantity.' );
} );

test( 'aid cannot discount containers, membership fees or donations', () => {
	const state = basket( { aidApproved: 10000 } );
	assert.equal( state.aidText, '-$5.00' );
	assert.equal( state.totalText, '$14.00' );
	state.extraCredit = 200;
	assert.equal( state.totalText, '$12.00' );
	assert.equal( state.aidText, '-$5.00', 'Waiving containers does not change food aid.' );
} );

test( 'pending or already settled fee is not included again', () => {
	const state = basket( { membershipFee: 0, membershipPending: true } );
	assert.equal( state.totalText, '$9.00' );
	assert.equal( state.hasMembershipFee, false );
} );

test( 'empty basket does not charge a membership fee', () => {
	const state = basket( { items: [], donation: 0 } );
	assert.equal( state.totalText, '$0.00' );
	assert.equal( state.hasMembershipFee, false );
} );

test( 'container credits reduce goods, not membership fee', () => {
	const state = basket( { items: [ { id: 1, qty: 2, own: true } ] } );
	assert.equal( state.totalText, '$17.00' );
} );

test( 'extra container credit shows the amount actually taken off remaining goods', () => {
	const state = basket( { items: [ { id: 1, qty: 2, own: true } ], aidApproved: 100, extraCredit: 350 } );
	assert.equal( state.creditText, '-$2.00' );
	assert.equal( state.extraCreditText, '-$3.50' );
	assert.equal( state.totalText, '$12.50' );
	state.items[ 0 ].qty = 1;
	assert.equal( state.extraCreditText, '-$1.50' );
	assert.equal( state.totalText, '$12.00' );
} );

test( 'previously settled aid is not rewritten by the food-only rule', () => {
	const state = basket( { status: 'prepaid', aidApproved: 700 } );
	assert.equal( state.aidText, '-$7.00' );
	assert.equal( state.totalText, '$12.00' );
} );
