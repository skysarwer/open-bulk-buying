const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const path = require( 'node:path' );
const { test } = require( 'node:test' );
const { runInNewContext } = require( 'node:vm' );
const { transformSync } = require( '@babel/core' );

const { code } = transformSync(
	readFileSync(
		path.join( __dirname, '..', 'src', 'admin', 'utils', 'config.js' ),
		'utf8'
	),
	{
		babelrc: false,
		configFile: false,
		plugins: [ '@babel/plugin-transform-modules-commonjs' ],
	}
);

function loadConfig( boot ) {
	const exports = {};
	runInNewContext( code, {
		exports,
		require,
		window: { openbbAdmin: boot },
	} );
	return exports;
}

test( 'English admin marks the French default, not the English translation', () => {
	const { localizedFieldLabel } = loadConfig( {
		locale: 'en-US',
		defaultLanguage: 'fr',
		translationLanguages: [ 'en', 'de' ],
	} );
	assert.equal( localizedFieldLabel( 'Name' ), 'Name (FR)' );
	assert.equal( localizedFieldLabel( 'Name', 'default' ), 'Name (FR)' );
	assert.equal( localizedFieldLabel( 'Name', 'en' ), 'Name' );
	assert.equal( localizedFieldLabel( 'Name', 'de' ), 'Name (DE)' );
} );

test( 'French admin leaves the French default unmarked', () => {
	const { localizedFieldLabel } = loadConfig( {
		locale: 'fr-CA',
		defaultLanguage: 'fr',
	} );
	assert.equal( localizedFieldLabel( 'Nom' ), 'Nom' );
	assert.equal( localizedFieldLabel( 'Nom', 'en' ), 'Nom (EN)' );
} );

test( 'labels follow the admin locale rather than the default or language order', () => {
	const { config, localizedFieldLabel } = loadConfig( {
		locale: 'DE_de',
		defaultLanguage: 'en',
		languages: [ 'en', 'fr', 'de' ],
		translationLanguages: [ 'fr', 'de' ],
	} );
	assert.equal( localizedFieldLabel( 'Name' ), 'Name (EN)' );
	assert.equal( localizedFieldLabel( 'Name', 'fr' ), 'Name (FR)' );
	assert.equal( localizedFieldLabel( 'Name', 'de' ), 'Name' );
	assert.equal( config.defaultLanguage, 'en' );
	assert.equal( Array.from( config.translationLanguages ).join(), 'fr,de' );
} );

test( 'an admin language outside the content languages marks every language', () => {
	const { localizedFieldLabel } = loadConfig( {
		locale: 'es-ES',
		defaultLanguage: 'fr',
	} );
	assert.equal( localizedFieldLabel( 'Name' ), 'Name (FR)' );
	assert.equal( localizedFieldLabel( 'Name', 'en' ), 'Name (EN)' );
} );

test( 'list text follows the admin locale with default fallback', () => {
	for ( const locale of [ 'en-US', 'EN_us' ] ) {
		const { localized, localizedRecordField } = loadConfig( {
			locale,
			defaultLanguage: 'fr',
			translationLanguages: [ 'de', 'en' ],
		} );
		assert.equal(
			localized( { default: 'Nom', en: 'Name', de: 'Name (DE)' } ),
			'Name'
		);
		assert.equal( localized( { default: 'Nom', en: '' } ), 'Nom' );
		assert.equal( localized( { default: 'Nom' } ), 'Nom' );
		assert.equal( localized( undefined ), '' );
		assert.equal(
			localized( { default: 'Nom', en: 'Name' }, 'default' ),
			'Nom'
		);

		const item = Object.freeze( { name: 'Huile', name_en: 'Oil' } );
		assert.equal( localizedRecordField( item, 'name' ), 'Oil' );
		assert.equal( item.name, 'Huile' );
		assert.equal(
			localizedRecordField( { name: 'Huile', name_en: '' }, 'name' ),
			'Huile'
		);
		assert.equal( localizedRecordField( undefined, 'name' ), '' );
	}
} );

test( 'default and unsupported admin languages retain default list text', () => {
	for ( const locale of [ 'fr-CA', 'es-ES', undefined ] ) {
		const { localized, localizedRecordField } = loadConfig( {
			locale,
			defaultLanguage: 'fr',
		} );
		assert.equal( localized( { default: 'Huile', en: 'Oil' } ), 'Huile' );
		assert.equal(
			localizedRecordField( { name: 'Huile', name_en: 'Oil' }, 'name' ),
			'Huile'
		);
	}
} );

test( 'product list cells translate without changing editable field values', () => {
	const configExports = loadConfig( {
		locale: 'en-US',
		defaultLanguage: 'fr',
		translationLanguages: [ 'en' ],
	} );
	const { code: fieldsCode } = transformSync(
		readFileSync(
			path.join( __dirname, '..', 'src', 'admin', 'screens', 'products', 'fields.js' ),
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
	runInNewContext( fieldsCode, {
		exports,
		require: ( name ) => {
			if ( name === '../../utils/config' ) {
				return configExports;
			}
			if ( name === '../../utils/format' ) {
				return { minorFactor: () => 100 };
			}
			if ( name === '@wordpress/components' ) {
				return {};
			}
			return require( name );
		},
	} );
	const fields = exports.buildProductFields( { categories: [], suppliers: [] } );
	const item = Object.freeze( {
		name: 'Huile',
		name_en: 'Oil',
		description: 'Description FR',
		description_en: 'Description EN',
	} );
	for ( const key of [ 'name', 'description' ] ) {
		const field = fields.find( ( candidate ) => candidate.id === key );
		assert.equal( field.render( { item } ), item[ `${ key }_en` ] );
		assert.equal( field.render( { item: { [ key ]: 'Default' } } ), 'Default' );
		assert.equal( field.getValue, undefined );
		assert.equal( field.setValue, undefined );
	}
	assert.equal( item.name, 'Huile' );
	assert.ok( exports.WRITABLE.includes( 'name_en' ) );
} );
