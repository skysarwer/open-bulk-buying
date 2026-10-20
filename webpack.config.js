const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );
const { Compilation } = require( 'webpack' );

const lifecycleEvent = process.env.npm_lifecycle_event || '';
const isBuildLifecycle = /^build(?::|$)/.test( lifecycleEvent );
const isBuildCommand = process.argv.includes( 'build' ) || isBuildLifecycle;
const isWatchMode = process.argv.includes( '--watch' );

const shouldDisableSourceMaps = ( config ) =>
	( isBuildCommand && ! isWatchMode ) ||
	( config?.mode === 'production' && ! isWatchMode );

// With Script Module support wp-scripts exports [ classic, module ]. Admin scripts use the
// classic config; frontend Interactivity API modules are plain files in assets/js/ with no
// build step, so the module config is not used.
const classicConfig = Array.isArray( defaultConfig )
	? defaultConfig[ 0 ]
	: defaultConfig;

// Entries that only compile styles (e.g. build/frontend.css).
const STYLE_ENTRIES = {
	frontend: path.resolve( process.cwd(), 'src/sass', 'frontend.scss' ),
};

// Webpack still emits an empty script (and wp-scripts its .asset.php) for a style-only
// entry; nothing loads them, so they're dropped.
class RemoveStyleEntryScripts {
	apply( compiler ) {
		compiler.hooks.thisCompilation.tap( 'RemoveStyleEntryScripts', ( compilation ) => {
			compilation.hooks.processAssets.tap(
				{
					name: 'RemoveStyleEntryScripts',
					stage: Compilation.PROCESS_ASSETS_STAGE_REPORT,
				},
				() => {
					for ( const entry of Object.keys( STYLE_ENTRIES ) ) {
						for ( const file of [ `${ entry }.js`, `${ entry }.asset.php` ] ) {
							if ( compilation.getAsset( file ) ) {
								compilation.deleteAsset( file );
							}
						}
					}
				}
			);
		} );
	}
}

module.exports = {
	...classicConfig,
	devtool: shouldDisableSourceMaps( classicConfig )
		? false
		: classicConfig.devtool,
	entry: {
		'admin/index': path.resolve( process.cwd(), 'src/admin', 'index.js' ),
		...STYLE_ENTRIES,
	},
	output: {
		...classicConfig.output,
		chunkFilename: 'vendors/[name].js',
	},
	plugins: [ ...classicConfig.plugins, new RemoveStyleEntryScripts() ],
};
