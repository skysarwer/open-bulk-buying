/**
 * Runs the Node-based unit tests (tests/*.cjs) with Node's built-in test runner.
 *
 * Node 20's runner only discovers files named *.test.*; this passes the files
 * explicitly so the existing names keep working on every platform.
 */
const { readdirSync } = require( 'node:fs' );
const path = require( 'node:path' );
const { spawnSync } = require( 'node:child_process' );

const dir = path.join( __dirname, '..', 'tests' );
const files = readdirSync( dir )
	.filter( ( name ) => name.endsWith( '.cjs' ) )
	.map( ( name ) => path.join( dir, name ) );

if ( ! files.length ) {
	console.log( 'No Node tests found in tests/.' );
	process.exit( 0 );
}

const result = spawnSync( process.execPath, [ '--test', ...files ], {
	stdio: 'inherit',
} );
process.exit( result.status === null ? 1 : result.status );
