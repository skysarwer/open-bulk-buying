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
			if ( name === '@wordpress/components' ) {
				return {};
			}
			if ( name === '../../utils/config' ) {
				return {
					config: { translationLanguages: [], unitTypes: { unit: 'Unit' } },
					localizedFieldLabel: ( label ) => label,
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
