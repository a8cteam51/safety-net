import { spawn } from 'node:child_process';
import { existsSync, readFileSync, rmSync } from 'node:fs';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { assertPluginDir, config, defaultConcurrency, SMOKE_DIR } from './lib/config.mjs';
import { GROUPS, SCENARIOS } from './scenarios.mjs';

const usage = `Usage: npm test -- [scenario or group ...] [--group=<group>[,...]] [--concurrency=<n>] [--list]
       node tests/smoke/run.mjs --check-groups=<group>[,...]   (fails unless the list matches the groups in scenarios.mjs)

Groups: ${ GROUPS.join( ', ' ) }
Environment: SAFETY_NET_PATH (plugin under test, default: repo root), SN_TEST_PHP, SN_TEST_WP, SN_TEST_WC_VERSION,
             SN_TEST_GROUP, SN_TEST_ONLY, SN_TEST_CONCURRENCY, SN_TEST_OUTPUT, SN_TEST_CACHE, SN_TEST_BOOT_TIMEOUT, SN_TEST_VERBOSE=1`;

const { values, positionals } = parseArgs( {
	allowPositionals: true,
	options: {
		group: { type: 'string' },
		concurrency: { type: 'string' },
		list: { type: 'boolean' },
		help: { type: 'boolean' },
		'check-groups': { type: 'string' },
	},
} );

const split = ( value ) => ( value ?? '' ).split( ',' ).map( ( v ) => v.trim() ).filter( Boolean );

if ( values.help ) {
	console.log( usage );
	process.exit( 0 );
}

const clashes = SCENARIOS.filter( ( s ) => GROUPS.includes( s.name ) ).map( ( s ) => s.name );
if ( clashes.length ) {
	console.error( `Scenario names must not equal a group name, or selecting the group would be ambiguous: ${ clashes.join( ', ' ) }` );
	process.exit( 2 );
}

if ( values[ 'check-groups' ] !== undefined ) {
	const listed = split( values[ 'check-groups' ] );
	const missing = GROUPS.filter( ( group ) => ! listed.includes( group ) );
	const unknown = listed.filter( ( group ) => ! GROUPS.includes( group ) );
	if ( missing.length || unknown.length ) {
		console.error( [ missing.length && `Groups in tests/smoke/scenarios.mjs that the CI matrix never runs: ${ missing.join( ', ' ) }`, unknown.length && `CI matrix groups with no scenarios: ${ unknown.join( ', ' ) }` ].filter( Boolean ).join( '\n' ) );
		process.exit( 1 );
	}
	console.log( `The CI matrix covers every scenario group: ${ GROUPS.join( ', ' ) }` );
	process.exit( 0 );
}

if ( values.list ) {
	for ( const group of GROUPS ) {
		console.log( `${ group }:` );
		for ( const scenario of SCENARIOS.filter( ( s ) => s.group === group ) ) {
			console.log( `  ${ scenario.name.padEnd( 32 ) } ${ scenario.covers }` );
		}
	}
	process.exit( 0 );
}

const wanted = [ ...positionals, ...split( values.group ?? process.env.SN_TEST_GROUP ), ...split( process.env.SN_TEST_ONLY ) ];
const unknown = wanted.filter( ( name ) => ! GROUPS.includes( name ) && ! SCENARIOS.some( ( s ) => s.name === name ) );
if ( unknown.length ) {
	console.error( `Unknown scenario or group: ${ unknown.join( ', ' ) }\n\n${ usage }` );
	process.exit( 2 );
}
const selected = wanted.length ? SCENARIOS.filter( ( s ) => wanted.includes( s.name ) || wanted.includes( s.group ) ) : SCENARIOS;

// Loaded only now, so --check-groups and --list work without npm ci.
const { ensureWooCommerce, ensureWpCli } = await import( './lib/downloads.mjs' );
const { bootSite } = await import( './lib/site.mjs' );

assertPluginDir();
const concurrency = Number( values.concurrency ) || defaultConcurrency();
console.log( `Safety Net smoke tests: ${ selected.length } scenarios, concurrency ${ concurrency }` );
console.log( `  plugin:  ${ config.pluginDir }\n  PHP ${ config.php }, WordPress ${ config.wp }, WooCommerce ${ config.wooVersion }\n  output:  ${ config.outputDir }\n  cache:   ${ config.cacheDir }` );

const needs = new Set( selected.flatMap( ( s ) => s.needs ?? [] ) );
let wooVersion = config.wooVersion;
if ( needs.has( 'woocommerce' ) ) {
	wooVersion = ( await ensureWooCommerce() ).version;
	console.log( `  WooCommerce ${ wooVersion } ready` );
}
if ( needs.has( 'wp-cli' ) ) {
	await ensureWpCli();
}

// One boot before the parallel run downloads WordPress once, so concurrent first downloads cannot corrupt the cache.
const warmStarted = Date.now();
try {
	const warm = await bootSite( { name: '_warm-up' } );
	console.log( `  booted PHP ${ warm.versions.php }, WordPress ${ warm.versions.wp } in ${ ( ( Date.now() - warmStarted ) / 1000 ).toFixed( 1 ) }s\n` );
	await warm.stop();
} catch ( error ) {
	console.error( `\nWordPress Playground could not boot PHP ${ config.php } / WordPress ${ config.wp }:\n${ error.stack ?? error }` );
	process.exit( 1 );
}

const summaryFile = path.join( config.outputDir, 'summary.json' );
rmSync( summaryFile, { force: true } );

const files = selected.map( ( s ) => path.join( SMOKE_DIR, 'scenarios', `${ s.name }.test.mjs` ) );
const child = spawn(
	process.execPath,
	[
		'--experimental-wasm-jspi',
		'--test',
		`--test-concurrency=${ concurrency }`,
		'--test-timeout=900000',
		`--test-reporter=${ path.join( SMOKE_DIR, 'lib/reporter.mjs' ) }`,
		'--test-reporter-destination=stdout',
		...files,
	],
	{
		stdio: 'inherit',
		env: {
			...process.env,
			SAFETY_NET_PATH: config.pluginDir,
			SN_TEST_PHP: config.php,
			SN_TEST_WP: config.wp,
			SN_TEST_WC_VERSION: wooVersion,
			SN_TEST_OUTPUT: config.outputDir,
			SN_TEST_CACHE: config.cacheDir,
		},
	}
);
for ( const signal of [ 'SIGINT', 'SIGTERM' ] ) {
	process.on( signal, () => child.kill( signal ) );
}
child.on( 'exit', ( code, signal ) => {
	const exitCode = code ?? ( signal ? 1 : 0 );
	// node:test passes a todo test that passes, so without this CI never notices a fixed bug still marked as known.
	if ( exitCode === 0 && process.env.CI ) {
		let fixedKnownIssues;
		try {
			( { fixedKnownIssues = [] } = JSON.parse( readFileSync( summaryFile, 'utf8' ) ) );
		} catch ( error ) {
			console.error( `Failing the run: ${ summaryFile } was not written, so fixed known issues cannot be checked (${ error.message }).` );
			process.exit( 1 );
		}
		if ( fixedKnownIssues.length ) {
			console.error( `Failing the run: ${ fixedKnownIssues.length } known issue(s) are fixed but still marked todo.` );
			process.exit( 1 );
		}
	}
	process.exit( exitCode );
} );
