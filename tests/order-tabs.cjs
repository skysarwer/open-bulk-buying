const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const path = require( 'node:path' );
const { test } = require( 'node:test' );
const { runInNewContext } = require( 'node:vm' );
const { transformSync } = require( '@babel/core' );

function load( filename, requireModule = require, suffix = '' ) {
	const { code } = transformSync(
		readFileSync(
			path.join(
				__dirname,
				'..',
				'src',
				'admin',
				'screens',
				'orders',
				filename
			),
			'utf8'
		),
		{
			babelrc: false,
			configFile: false,
			plugins: [
				'@babel/plugin-transform-react-jsx',
				'@babel/plugin-transform-modules-commonjs',
			],
		}
	);
	const exports = {};
	runInNewContext( code + suffix, { exports, require: requireModule } );
	return exports;
}

const views = load( 'list-view.js' );
const { buildFields } = load(
	'index.js',
	( name ) => {
		if ( name === './list-view' ) {
			return views;
		}
		if ( name === '@wordpress/i18n' ) {
			return { __: ( text ) => text };
		}
		if ( name === '../../utils/config' ) {
			return {
				can: () => true,
				config: {
					orderStatuses: {
						draft: 'Draft',
						submitted: 'Submitted',
						prepaid: 'Prepaid',
						fulfilled: 'Fulfilled',
						cancelled: 'Cancelled',
					},
				},
				toElements: ( map ) =>
					Object.entries( map ).map( ( [ value, label ] ) => ( {
						value,
						label,
					} ) ),
			};
		}
		return {};
	},
	'\nexports.buildFields = buildFields;'
);

test( 'orders expose All, Cycle Orders, and Membership Payments scopes', () => {
	assert.deepEqual( Array.from( views.ORDER_TABS ), [
		'all',
		'cycle',
		'membership',
	] );
} );

test( 'membership view resets pagination and excludes cycle-specific columns and filters', () => {
	const view = views.createOrderView( 'membership', 42 );
	assert.equal( view.page, 1 );
	assert.equal( view.titleField, 'member_name' );
	assert.deepEqual( Array.from( view.filters ), [] );
	assert.deepEqual( Array.from( view.fields ), [
		'id',
		'status',
		'total',
		'submitted_at',
		'paid_at',
		'payment_reference',
	] );
	const fields = buildFields( [], 'membership' );
	assert.deepEqual(
		Array.from( fields, ( field ) => field.id ),
		Array.from( views.MEMBERSHIP_FIELDS )
	);
	for ( const id of view.fields ) {
		assert.ok( fields.some( ( field ) => field.id === id ) );
	}
} );

test( 'membership columns use payment labels, the snapshot amount, and Paid status', () => {
	const fields = buildFields( [], 'membership' );
	const field = ( id ) => fields.find( ( entry ) => entry.id === id );
	assert.equal( field( 'id' ).label, 'Payment' );
	assert.equal( field( 'total' ).label, 'Amount' );
	assert.equal( field( 'paid_at' ).label, 'Paid on' );
	assert.equal( field( 'payment_reference' ).label, 'Reference' );
	assert.equal(
		field( 'total' ).render( {
			item: { total: 2500, total_formatted: 'US$25.00' },
		} ),
		'US$25.00'
	);
	assert.equal(
		field( 'status' ).elements.find(
			( option ) => option.value === 'fulfilled'
		).label,
		'Paid'
	);
	assert.ok(
		field( 'status' ).elements.some(
			( option ) => option.value === 'submitted'
		)
	);
	assert.equal(
		field( 'status' ).elements.some(
			( option ) => option.value === 'prepaid'
		),
		false
	);
} );

test( 'All and Cycle Orders preserve grocery columns and cycle deep-link filtering', () => {
	for ( const tab of [ 'all', 'cycle' ] ) {
		const view = views.createOrderView( tab, 42 );
		assert.equal( view.filters[ 0 ].field, 'cycle_id' );
		assert.equal( view.filters[ 0 ].value, 42 );
		const fields = buildFields( [ { id: 42, name: 'Fall cycle' } ], tab );
		for ( const id of [ 'cycle_id', 'lines_count', 'aid_status' ] ) {
			assert.ok( view.fields.includes( id ) );
			assert.ok( fields.some( ( field ) => field.id === id ) );
		}
		assert.equal(
			fields.find( ( field ) => field.id === 'id' ).label,
			'Order'
		);
		assert.equal(
			fields
				.find( ( field ) => field.id === 'status' )
				.elements.find( ( option ) => option.value === 'fulfilled' )
				.label,
			'Fulfilled'
		);
	}
} );
