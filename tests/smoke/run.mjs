import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import os from 'node:os';
import path from 'node:path';
import { pipeline } from 'node:stream/promises';
import { run } from 'node:test';
import { parseArgs } from 'node:util';
import { assertPluginDir, config, defaultConcurrency, SMOKE_DIR } from './lib/config.mjs';
import smokeReporter from './lib/reporter.mjs';
import { GROUPS, SCENARIOS } from './scenarios.mjs';

const usage = `Usage: npm test -- [scenario or group ...] [--group=<group>[,...]] [--concurrency=<n>] [--list]
       node tests/smoke/run.mjs --check-groups=<group>[,...]   (fails unless the list matches the groups in scenarios.mjs)

Groups: ${ GROUPS.join( ', ' ) }
Environment: SAFETY_NET_PATH (plugin under test, default: repo root), SN_TEST_PHP, SN_TEST_WP, SN_TEST_WC_VERSION,
             SN_TEST_GROUP, SN_TEST_ONLY, SN_TEST_CONCURRENCY, SN_TEST_PAGE_CONCURRENCY, SN_TEST_OUTPUT, SN_TEST_CACHE, SN_TEST_BOOT_TIMEOUT,
             SN_TEST_VERBOSE=1`;

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

const unweighted = SCENARIOS.filter( ( s ) => ! ( s.seconds > 0 ) ).map( ( s ) => s.name );
if ( unweighted.length ) {
	console.error( `Give every scenario in tests/smoke/scenarios.mjs "seconds", roughly how long it takes, so runs can start the longest first: ${ unweighted.join( ', ' ) }` );
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

// site.mjs runs enableMultisite from @wp-playground/blueprints on a site booted by @wp-playground/cli, which brings its own copy.
const requireHere = createRequire( import.meta.url );
let playground;
try {
	playground = Object.fromEntries( [ 'cli', 'blueprints' ].map( ( name ) => [ name, JSON.parse( readFileSync( requireHere.resolve( `@wp-playground/${ name }/package.json` ), 'utf8' ) ).version ] ) );
} catch ( error ) {
	console.error( `Could not read the installed @wp-playground packages; run npm ci first (${ error.message.split( '\n' )[ 0 ] })` );
	process.exit( 1 );
}
if ( playground.blueprints !== playground.cli ) {
	console.error( `@wp-playground/blueprints ${ playground.blueprints } must match @wp-playground/cli ${ playground.cli }; update both together in package.json, then run npm ci.` );
	process.exit( 1 );
}

// Loaded only now, so --check-groups and --list work without npm ci.
const { ensureWooCommerce, ensureWpCli } = await import( './lib/downloads.mjs' );
const { bootSite, buildWooCommerceSite } = await import( './lib/site.mjs' );

assertPluginDir();
const concurrency = Number( values.concurrency ) || defaultConcurrency();
console.log( `Safety Net smoke tests: ${ selected.length } scenarios, concurrency ${ concurrency }` );
console.log( `  plugin:  ${ config.pluginDir }\n  PHP ${ config.php }, WordPress ${ config.wp }, WooCommerce ${ config.wooVersion }\n  output:  ${ config.outputDir }\n  cache:   ${ config.cacheDir }` );

const needs = new Set( selected.flatMap( ( s ) => s.needs ?? [] ) );
let wooVersion = config.wooVersion;
if ( needs.has( 'woocommerce' ) ) {
	wooVersion = ( await ensureWooCommerce() ).version;
	config.wooVersion = wooVersion;
	console.log( `  WooCommerce ${ wooVersion } ready` );
}
if ( needs.has( 'wp-cli' ) ) {
	await ensureWpCli();
}

// Building it takes about one boot longer than the warm-up it replaces, and each scenario that starts from a copy saves about one boot.
const fromWooSite = selected.filter( ( s ) => s.needs?.includes( 'woocommerce-site' ) );
const buildWooSite = fromWooSite.length >= 2;
// Not in the output directory, which CI uploads on failure, since every copy of the site is over 100 MB.
const tempDir = buildWooSite ? mkdtempSync( path.join( os.tmpdir(), 'sn-smoke-' ) ) : '';
if ( tempDir ) {
	process.on( 'exit', () => {
		try {
			rmSync( tempDir, { recursive: true, force: true, maxRetries: 3 } );
		} catch ( error ) {
			console.error( `Could not delete ${ tempDir }: ${ error.message }` );
		}
	} );
}
// Aborting the run stops the scenario processes, which a SIGTERM sent only to this process would leave running.
let abortRun = null;
let interrupted = '';
for ( const signal of [ 'SIGINT', 'SIGTERM', 'SIGHUP' ] ) {
	process.on( signal, () => {
		if ( abortRun && ! interrupted ) {
			interrupted = signal;
			abortRun();
		} else {
			process.exit( 128 + os.constants.signals[ signal ] );
		}
	} );
}
let wooSite = '';

// One boot before the parallel run downloads WordPress once, so concurrent first downloads cannot corrupt the cache.
const warmStarted = Date.now();
const took = () => `${ ( ( Date.now() - warmStarted ) / 1000 ).toFixed( 1 ) }s`;
if ( buildWooSite ) {
	try {
		const built = await buildWooCommerceSite( tempDir );
		wooSite = built.dir;
		console.log( `  booted PHP ${ built.versions.php }, WordPress ${ built.versions.wp } and activated WooCommerce ${ built.woocommerce } in ${ took() }; ${ fromWooSite.map( ( s ) => s.name ).join( ', ' ) } start from copies of that site\n` );
	} catch ( error ) {
		// Each scenario then activates WooCommerce itself and reports its own failure, while the other scenarios still run.
		console.error( `  Building WordPress with WooCommerce ${ wooVersion } failed (logs in ${ path.join( config.outputDir, '_woocommerce-site' ) }), so every scenario installs its own:\n${ error.stack ?? error }\n` );
	}
}
if ( ! wooSite ) {
	try {
		const warm = await bootSite( { name: '_warm-up' } );
		console.log( `  booted PHP ${ warm.versions.php }, WordPress ${ warm.versions.wp } in ${ took() }\n` );
		await warm.stop();
	} catch ( error ) {
		console.error( `\nWordPress Playground could not boot PHP ${ config.php } / WordPress ${ config.wp }:\n${ error.stack ?? error }` );
		process.exit( 1 );
	}
}

const summaryFile = path.join( config.outputDir, 'summary.json' );
rmSync( summaryFile, { force: true } );

Object.assign( process.env, {
	SAFETY_NET_PATH: config.pluginDir,
	SN_TEST_PHP: config.php,
	SN_TEST_WP: config.wp,
	SN_TEST_WC_VERSION: wooVersion,
	SN_TEST_OUTPUT: config.outputDir,
	SN_TEST_CACHE: config.cacheDir,
	SN_TEST_WOO_SITE: wooSite,
} );
// Longest first, so the parallel lanes finish close together.
const files = selected.toSorted( ( a, b ) => b.seconds - a.seconds ).map( ( s ) => path.join( SMOKE_DIR, 'scenarios', `${ s.name }.test.mjs` ) );
const controller = new AbortController();
abortRun = () => controller.abort();
let passed = true;
try {
	await pipeline(
		run( {
			files,
			concurrency,
			timeout: 900_000,
			signal: controller.signal,
			execArgv: [ '--experimental-wasm-jspi' ].filter( ( flag ) => ! process.execArgv.includes( flag ) ),
		} ).on( 'test:summary', ( data ) => {
			passed &&= data.success;
		} ),
		smokeReporter,
		process.stdout,
		{ end: false }
	);
} catch ( error ) {
	// node:test's in-process handlers would otherwise swallow this and let every scenario run to the end.
	controller.abort();
	console.error( `The smoke test reporter failed: ${ error.stack ?? error }` );
	process.exit( 1 );
}
abortRun = null;
await new Promise( ( resolve ) => process.stdout.write( '', resolve ) );

if ( interrupted ) {
	console.error( `Interrupted by ${ interrupted }: the scenarios still running were stopped, so the counts above are incomplete.` );
	process.exit( 128 + os.constants.signals[ interrupted ] );
}
// node:test passes a todo test that passes, so without this CI never notices a fixed bug still marked as known.
if ( passed && process.env.CI ) {
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
process.exit( passed ? 0 : 1 );
