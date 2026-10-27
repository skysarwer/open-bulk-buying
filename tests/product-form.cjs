const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const path = require( 'node:path' );
const { test } = require( 'node:test' );
const { runInNewContext } = require( 'node:vm' );
const { transformSync } = require( '@babel/core' );

function loadModule( filename, options = {} ) {
	const { code } = transformSync( readFileSync( filename, 'utf8' ), {
		babelrc: false,
		configFile: false,
		filename,
		...options,
		plugins: [
			'@babel/plugin-transform-react-jsx',
			'@babel/plugin-transform-modules-commonjs',
		],
	} );
	const exports = {};
	runInNewContext( code, {
		exports,
		require: ( name ) => {
			if ( name === '../constants' ) {
				return {};
			}
			if ( name.startsWith( './utils/' ) ) {
				return loadModule(
					path.resolve( path.dirname( filename ), `${ name }.ts` ),
					{ presets: [ '@babel/preset-typescript' ] }
				);
			}
			if ( name === '@wordpress/components' ) {
				return {};
			}
			if ( name === '../../utils/config' ) {
				return {
					config: { translationLanguages: [], unitTypes: { unit: 'Unit' } },
					localizedFieldLabel: ( label ) => label,
					localizedRecordField: ( item, key ) => item[ key ],
					toElements: ( map ) =>
						Object.entries( map ).map( ( [ value, label ] ) => ( { value, label } ) ),
				};
			}
			if ( name === '../../utils/format' ) {
				return { minorFactor: () => 100 };
			}
			return require( name );
		},
	} );
	return exports;
}

const { buildProductFields, NEW_PRODUCT } = loadModule(
	path.join( __dirname, '..', 'src', 'admin', 'screens', 'products', 'fields.js' )
);
const { default: isValidElements } = loadModule(
	path.join(
		__dirname, '..', 'node_modules', '@wordpress', 'dataviews',
		'src', 'field-types', 'utils', 'is-valid-elements.ts'
	),
	{ presets: [ '@babel/preset-typescript' ] }
);
const { default: arrayType } = loadModule(
	path.join(
		__dirname, '..', 'node_modules', '@wordpress', 'dataviews',
		'src', 'field-types', 'array.tsx'
	),
	{ presets: [ '@babel/preset-typescript' ] }
);

function supplierField( suppliers ) {
	const field = buildProductFields( { categories: [], suppliers } )
		.find( ( candidate ) => candidate.id === 'supplier_id' );
	return { ...field, getValue: ( { item } ) => item.supplier_id };
}

test( 'new and existing products without a supplier pass DataForm element validation', () => {
	for ( const suppliers of [ [], [ { id: 12, name: 'Local farm' } ] ] ) {
		const field = supplierField( suppliers );
		for ( const id of [ 0, 27 ] ) {
			const product = { ...NEW_PRODUCT, id, name: 'Carrots' };
			assert.equal( isValidElements( product, field ), true );
		}
		assert.equal( field.elements[ 0 ].value, 0 );
		assert.equal( field.elements[ 0 ].label, 'None' );
	}
} );

test( 'existing supplier IDs stay valid and unknown IDs stay invalid', () => {
	const field = supplierField( [ { id: 12, name: 'Local farm' } ] );
	assert.equal( isValidElements( { supplier_id: 12 }, field ), true );
	assert.equal( isValidElements( { supplier_id: 99 }, field ), false );
	assert.equal( isValidElements( { supplier_id: '12' }, field ), false );
} );

test( 'numeric category IDs pass DataForm array and element validation', () => {
	const field = buildProductFields( {
		categories: [ { id: 30, name: 'Coffee' }, { id: 31, name: 'Pantry' } ],
		suppliers: [],
	} ).find( ( candidate ) => candidate.id === 'category_ids' );
	for ( const category_ids of [ [], [ 30 ], [ 30, 31 ] ] ) {
		const item = { ...NEW_PRODUCT, category_ids };
		assert.equal( arrayType.validate.custom( item, field ), null );
		assert.equal( isValidElements( item, field ), true );
		assert.deepEqual(
			Array.from( field.setValue( { value: field.getValue( { item } ) } ).category_ids ),
			category_ids
		);
	}
	assert.equal( field.render( { item: { category_ids: [ 30, 31 ] } } ), 'Coffee, Pantry' );
	assert.equal( isValidElements( { category_ids: [ 99 ] }, field ), false );
	assert.equal(
		arrayType.validate.custom(
			{ category_ids: [ 30 ] },
			{ ...field, getValue: ( { item } ) => item.category_ids }
		),
		'Every value must be a string.'
	);
} );

test( 'category edits keep integer IDs in REST payloads', () => {
	const field = buildProductFields( {
		categories: [ { id: 30, name: 'Coffee' } ],
		suppliers: [],
	} ).find( ( candidate ) => candidate.id === 'category_ids' );
	assert.deepEqual( Array.from( field.setValue( { value: [ '30' ] } ).category_ids ), [ 30 ] );
	assert.deepEqual( Array.from( field.setValue( { value: [] } ).category_ids ), [] );
} );
