const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const path = require( 'node:path' );
const { test } = require( 'node:test' );
const { runInNewContext } = require( 'node:vm' );
const { transformSync } = require( '@babel/core' );

const { code } = transformSync(
	readFileSync( path.join( __dirname, '..', 'src', 'admin', 'screens', 'orders', 'editor.js' ), 'utf8' ),
	{
		babelrc: false,
		configFile: false,
		plugins: [ '@babel/plugin-transform-react-jsx', '@babel/plugin-transform-modules-commonjs' ],
	}
);

async function editor( overrides = {}, failure = null ) {
	const order = {
		id: 42, member: { id: 7, name: 'Applicant' }, status: 'submitted',
		status_label: 'Submitted', donation: 0, staff_note: '', lines: [],
		subtotal: 2500, container_credit: 0, total: 2500, total_formatted: '$25.00',
		is_locked: true, reason_required_statuses: [], can_review_membership_payment: true,
		...overrides,
	};
	const states = [];
	const requests = [];
	const saved = [];
	let cursor = 0;
	let mounted = false;
	const exports = {};
	runInNewContext( code, {
		exports,
		React: { createElement: ( type, props, ...children ) => ( { type, props: { ...props, children } } ) },
		require: ( name ) => {
			switch ( name ) {
				case '@wordpress/components':
				case '../../components':
					return new Proxy( {}, { get: ( target, key ) => key } );
				case '@wordpress/element':
					return {
						useState: ( initial ) => {
							const index = cursor++;
							if ( ! ( index in states ) ) {
								states[ index ] = initial;
							}
							return [ states[ index ], ( value ) => {
								states[ index ] = typeof value === 'function' ? value( states[ index ] ) : value;
							} ];
						},
						useEffect: ( effect ) => { if ( ! mounted ) { effect(); } },
						useMemo: ( compute ) => compute(),
					};
				case '@wordpress/i18n':
					return { __: ( text ) => text, sprintf: ( text, ...args ) => text.replace( /%s|%[12]\$d|%[12]\$s/g, () => args.shift() ) };
				case '../../hooks':
					return { useUnsavedChanges: () => {} };
				case '../../utils/config':
					return { can: () => true, config: { urls: {}, orderStatuses: {} } };
				case '../../utils/format':
					return { toMinor: ( value ) => Number( value ) * 100, toMajor: ( value ) => value / 100, formatMoney: String };
				case '@wordpress/date':
					return { getSettings: () => ( { formats: {} } ) };
				case '../../utils/api':
					return { request: async ( route, options ) => {
						requests.push( { route, options } );
						if ( ! options ) {
							return order;
						}
						if ( failure ) {
							throw failure;
						}
						if ( options.method === 'PUT' ) {
							return { ...order, ...options.data, revision: ( order.revision || 0 ) + 1, updated_at: 'after-save' };
						}
						return { ...order, status: options.data.reject ? 'cancelled' : 'fulfilled', can_review_membership_payment: false };
					} };
				default:
					return {};
			}
		},
	} );
	const render = () => {
		cursor = 0;
		const tree = exports.default( { orderId: 42, onSaved: ( value ) => saved.push( value ), onClose: () => {} } );
		mounted = true;
		return tree;
	};
	render();
	await new Promise( ( resolve ) => setImmediate( resolve ) );
	return { render, requests, saved };
}

function nodes( tree, predicate ) {
	if ( Array.isArray( tree ) ) {
		return tree.flatMap( ( child ) => nodes( child, predicate ) );
	}
	if ( ! tree || typeof tree !== 'object' ) {
		return [];
	}
	return [ ...( predicate( tree ) ? [ tree ] : [] ), ...nodes( tree.props.children, predicate ) ];
}

function buttons( tree, label ) {
	return nodes( tree, ( node ) => node.type === 'Button' && node.props.children.includes( label ) );
}

test( 'submitted membership claims expose confirm and reject only when authorized', async () => {
	for ( const allowed of [ true, false ] ) {
		const view = await editor( { can_review_membership_payment: allowed } );
		assert.equal( buttons( view.render(), 'Confirm payment' ).length, allowed ? 1 : 0 );
		assert.equal( buttons( view.render(), 'Reject claimed payment' ).length, allowed ? 1 : 0 );
	}
} );

test( 'confirm and reject require a second confirmation and refresh the order after review', async () => {
	for ( const reject of [ false, true ] ) {
		const view = await editor();
		buttons( view.render(), reject ? 'Reject claimed payment' : 'Confirm payment' )[ 0 ].props.onClick();
		assert.equal( view.requests.length, 1, 'Opening confirmation must not write a payment.' );
		const confirmations = buttons( view.render(), reject ? 'Reject payment' : 'Confirm payment' );
		await confirmations.at( -1 ).props.onClick();
		assert.equal( view.requests[ 1 ].route, '/orders/42/membership-payment' );
		assert.equal( view.requests[ 1 ].options.method, 'POST' );
		assert.equal( view.requests[ 1 ].options.data.confirmed, true );
		assert.equal( view.requests[ 1 ].options.data.reject, reject );
		assert.equal( view.saved[ 0 ].status, reject ? 'cancelled' : 'fulfilled' );
		assert.equal( buttons( view.render(), 'Confirm payment' ).length, 0 );
		assert.equal( buttons( view.render(), 'Reject claimed payment' ).length, 0 );
	}
} );

test( 'failed review shows the error and retains the pending claim', async () => {
	const failure = { message: 'Payment could not be saved.' };
	const view = await editor( {}, failure );
	buttons( view.render(), 'Confirm payment' )[ 0 ].props.onClick();
	await buttons( view.render(), 'Confirm payment' ).at( -1 ).props.onClick();
	assert.equal( view.saved.length, 0 );
	assert.equal( nodes( view.render(), ( node ) => node.type === 'ErrorNotice' )[ 0 ].props.error, failure );
	assert.equal( buttons( view.render(), 'Reject claimed payment' ).length, 1 );
} );

test( 'unsaved notes disable payment review', async () => {
	const view = await editor( { can_edit: true } );
	nodes( view.render(), ( node ) => node.type === 'TextareaControl' && node.props.label === 'Staff note' )[ 0 ].props.onChange( 'Unsaved note' );
	assert.equal( buttons( view.render(), 'Confirm payment' )[ 0 ].props.disabled, true );
	assert.equal( buttons( view.render(), 'Reject claimed payment' )[ 0 ].props.disabled, true );
} );

test( 'cancelling review does not write a payment', async () => {
	const view = await editor();
	buttons( view.render(), 'Reject claimed payment' )[ 0 ].props.onClick();
	buttons( view.render(), 'Cancel' )[ 0 ].props.onClick();
	assert.equal( buttons( view.render(), 'Reject payment' ).length, 0 );
	assert.equal( view.requests.length, 1 );
	assert.equal( view.saved.length, 0 );
} );

test( 'cycle payment and fulfillment are chosen from the status dropdown and keep their routes and concurrency checks', async () => {
	for ( const action of [ 'prepay', 'fulfill' ] ) {
		const view = await editor( {
			can_review_membership_payment: false,
			can_prepay: action === 'prepay',
			can_fulfill: action === 'fulfill',
			editable_statuses: [ 'submitted' ],
			updated_at: '2026-11-01T10:00:00',
			revision: 3,
		} );
		const select = () => nodes( view.render(), ( node ) => node.type === 'SelectControl' && node.props.label === 'Status' )[ 0 ];
		const target = action === 'prepay' ? 'prepaid' : 'fulfilled';
		assert.equal( select().props.options.map( ( option ) => option.value ).join( ',' ), `submitted,${ target }` );
		select().props.onChange( target );
		assert.equal( view.requests.length, 1, 'Choosing a status must not write until confirmed.' );
		assert.equal( select().props.value, target );
		await buttons( view.render(), 'Confirm' )[ 0 ].props.onClick();
		assert.equal( view.requests[ 1 ].route, `/orders/42/${ action }` );
		assert.equal( view.requests[ 1 ].options.data.expected_revision, 3 );
		assert.equal( view.requests[ 1 ].options.data.expected_updated_at, '2026-11-01T10:00:00' );
	}
} );

test( 'cancelling a dropdown settlement restores the saved status', async () => {
	const view = await editor( { can_review_membership_payment: false, can_fulfill: true, editable_statuses: [ 'submitted' ] } );
	const select = () => nodes( view.render(), ( node ) => node.type === 'SelectControl' )[ 0 ];
	select().props.onChange( 'fulfilled' );
	buttons( view.render(), 'Cancel' )[ 0 ].props.onClick();
	assert.equal( select().props.value, 'submitted' );
	assert.equal( view.requests.length, 1 );
} );

test( 'fulfilling with unsaved edits saves them first, then confirms against the saved revision', async () => {
	const view = await editor( {
		can_review_membership_payment: false,
		can_edit: true,
		can_fulfill: true,
		editable_statuses: [ 'submitted' ],
		updated_at: '2026-11-01T10:00:00',
		revision: 3,
	} );
	nodes( view.render(), ( node ) => node.type === 'TextareaControl' && node.props.label === 'Staff note' )[ 0 ].props.onChange( 'Handed over at the door' );
	const select = nodes( view.render(), ( node ) => node.type === 'SelectControl' && node.props.label === 'Status' )[ 0 ];
	assert.ok( select.props.options.every( ( option ) => ! option.disabled ), 'Settlement stays available with unsaved edits.' );
	select.props.onChange( 'fulfilled' );
	const confirm = buttons( view.render(), 'Confirm' )[ 0 ];
	assert.equal( confirm.props.disabled, false );
	await confirm.props.onClick();
	assert.equal( view.requests[ 1 ].options.method, 'PUT' );
	assert.equal( view.requests[ 1 ].options.data.staff_note, 'Handed over at the door' );
	assert.equal( view.requests[ 2 ].route, '/orders/42/fulfill' );
	assert.equal( view.requests[ 2 ].options.data.expected_revision, 4 );
	assert.equal( view.requests[ 2 ].options.data.expected_updated_at, 'after-save' );
} );

test( 'settling with edits that need a reason waits for the reason', async () => {
	const view = await editor( {
		can_review_membership_payment: false,
		can_edit: true,
		is_locked: false,
		can_edit_amounts: true,
		can_fulfill: true,
		editable_statuses: [ 'submitted' ],
		reason_required_statuses: [ 'submitted' ],
	} );
	nodes( view.render(), ( node ) => node.type === '__experimentalNumberControl' && node.props.label === 'Donation' )[ 0 ].props.onChange( '5' );
	nodes( view.render(), ( node ) => node.type === 'SelectControl' && node.props.label === 'Status' )[ 0 ].props.onChange( 'fulfilled' );
	assert.equal( buttons( view.render(), 'Confirm' )[ 0 ].props.disabled, true );
	const reason = nodes( view.render(), ( node ) => node.type === 'TextareaControl' && node.props.label === 'Reason for the change (required)' );
	assert.equal( reason.length, 1, 'The reason field is shown once, beside the confirmation.' );
	reason[ 0 ].props.onChange( 'Member added a donation' );
	assert.equal( buttons( view.render(), 'Confirm' )[ 0 ].props.disabled, false );
	assert.equal( view.requests.length, 1 );
} );
