import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { REPO_ROOT } from './lib/config.mjs';

const { values } = parseArgs( { options: { ref: { type: 'string', default: 'HEAD' }, zip: { type: 'string' } } } );

const git = ( ...args ) => execFileSync( 'git', args, { cwd: REPO_ROOT, encoding: 'utf8' } );
const SHIPPED = /^safety-net\/(safety-net\.php$|includes\/|assets\/)/;

const tmp = mkdtempSync( path.join( os.tmpdir(), 'safety-net-archive-' ) );
try {
	let zip = values.zip;
	if ( ! zip ) {
		zip = path.join( tmp, 'safety-net.zip' );
		// The same command .github/workflows/release.yml runs.
		git( 'archive', '--prefix=safety-net/', values.ref, '-o', zip );
	}
	const entries = execFileSync( 'unzip', [ '-Z1', zip ], { encoding: 'utf8' } ).split( '\n' ).filter( ( entry ) => entry && ! entry.endsWith( '/' ) );
	const problems = [];

	const unexpected = entries.filter( ( entry ) => ! SHIPPED.test( entry ) );
	if ( unexpected.length ) {
		problems.push( `Files that must not ship (add them to .gitattributes export-ignore, or to SHIPPED in tests/smoke/check-release-archive.mjs if they belong in the plugin):\n  ${ unexpected.join( '\n  ' ) }` );
	}

	const plugin = values.zip ? [] : git( 'ls-tree', '-r', '--name-only', values.ref, '--', 'safety-net.php', 'includes', 'assets' ).split( '\n' ).filter( Boolean ).map( ( file ) => `safety-net/${ file }` );
	const missing = [ ...new Set( [ 'safety-net/safety-net.php', ...plugin ] ) ].filter( ( file ) => ! entries.includes( file ) );
	if ( missing.length ) {
		problems.push( `Plugin files missing from the zip:\n  ${ missing.join( '\n  ' ) }` );
	}

	console.log( `${ zip }: ${ entries.length } files` );
	if ( problems.length ) {
		console.error( `\nRelease archive check failed:\n${ problems.join( '\n' ) }` );
		process.exitCode = 1;
	} else {
		console.log( 'Release archive check passed: only safety-net.php, includes/ and assets/ ship, and nothing is missing.' );
	}
} finally {
	rmSync( tmp, { recursive: true, force: true } );
}
