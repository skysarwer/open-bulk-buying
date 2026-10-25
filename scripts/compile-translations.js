const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execSync } = require( 'child_process' );

const root = path.resolve( __dirname, '..' );
const temporary = fs.mkdtempSync( path.join( os.tmpdir(), 'openbb-i18n-' ) );
const mapFile = path.join( temporary, 'map.json' );
const map = {};

// The admin sources all ship in the same bundle, not as individual scripts.
const mapSources = ( directory ) => {
	for ( const entry of fs.readdirSync( directory, { withFileTypes: true } ) ) {
		const file = path.join( directory, entry.name );
		if ( entry.isDirectory() ) {
			mapSources( file );
		} else if ( entry.name.endsWith( '.js' ) ) {
			map[ path.relative( root, file ).split( path.sep ).join( '/' ) ] =
				'build/admin/index.js';
		}
	}
};

try {
	mapSources( path.join( root, 'src', 'admin' ) );
	fs.writeFileSync( mapFile, JSON.stringify( map ) );
	execSync( 'wp i18n make-mo languages', { cwd: root, stdio: 'inherit' } );
	execSync(
		`wp i18n make-json languages --no-purge --pretty-print --use-map="${ mapFile }"`,
		{ cwd: root, stdio: 'inherit' }
	);
} finally {
	if ( fs.existsSync( mapFile ) ) {
		fs.unlinkSync( mapFile );
	}
	fs.rmdirSync( temporary );
}
