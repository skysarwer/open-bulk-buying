const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const path = require( 'node:path' );
const { test } = require( 'node:test' );
const { runInNewContext } = require( 'node:vm' );
const { transformSync } = require( '@babel/core' );

const { code } = transformSync(
	readFileSync(
		path.join( __dirname, '..', 'assets', 'js', 'order.js' ),
		'utf8'
	),
	{
		babelrc: false,
		configFile: false,
		plugins: [ '@babel/plugin-transform-modules-commonjs' ],
	}
);

function loadOrder( errorCode ) {
	const state = {
		items: [ { id: 1, qty: 2, own: false } ],
		products: { 1: { max: 10 } },
		orderId: 1,
		cycleId: 1,
		revision: 1,
		editable: true,
		conflict: false,
		messages: { network: 'Network error' },
	};
	let definition;
	let requests = 0;
	let reloads = 0;
	runInNewContext( code, {
		exports: {},
		require: ( name ) => {
			if ( name === '@wordpress/interactivity' ) {
				return {
					store: ( namespace, value ) => {
						assert.equal( namespace, 'openbb/order' );
						definition = value;
						Object.defineProperties( state, Object.getOwnPropertyDescriptors( value.state ) );
						return { state };
					},
				};
			}
			assert.equal( name, '@open-bulk-buying/forms' );
			return {};
		},
		clearTimeout,
		fetch: async () => {
			requests++;
			return {
				ok: false,
				json: async () => ( { code: errorCode, message: 'Order changed on the server.' } ),
			};
		},
		window: { location: { reload: () => reloads++ } },
	} );
	return {
		state,
		actions: definition.actions,
		requests: () => requests,
		reloads: () => reloads,
	};
}

test( 'a cancellation detected during autosave stops editing and exposes reload', async () => {
	const order = loadOrder( 'openbb_order_locked' );
	order.actions.retry();
	await new Promise( setImmediate );
	assert.equal( order.requests(), 1 );
	assert.equal( order.state.editable, false );
	assert.equal( order.state.locked, true );
	assert.equal( order.state.conflict, true );
	assert.equal( order.state.canFinalize, false );
	assert.equal( order.state.notice, 'Order changed on the server.' );
	order.actions.retry();
	await new Promise( setImmediate );
	assert.equal( order.requests(), 1, 'Locked orders must not be retried or autosaved.' );
	order.actions.reload();
	assert.equal( order.reloads(), 1 );
} );

test( 'revision conflicts still expose reload without resaving a stale basket', async () => {
	const order = loadOrder( 'openbb_order_changed' );
	order.actions.retry();
	await new Promise( setImmediate );
	assert.equal( order.state.conflict, true );
	assert.equal( order.state.canFinalize, false );
	order.actions.retry();
	await new Promise( setImmediate );
	assert.equal( order.requests(), 1 );
} );
