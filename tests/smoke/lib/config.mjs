import { existsSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const SMOKE_DIR = fileURLToPath( new URL( '..', import.meta.url ) );
export const REPO_ROOT = path.resolve( SMOKE_DIR, '../..' );
export const FIXTURES_DIR = path.join( SMOKE_DIR, 'fixtures' );

function env( name, fallback ) {
	const value = process.env[ name ];
	return value === undefined || value === '' ? fallback : value;
}

// Outside the repo, so tools that scan the repo, like phpcs, never pick up the downloaded plugins.
function defaultCacheDir() {
	try {
		const home = env( 'XDG_CACHE_HOME', path.join( os.homedir(), '.cache' ) );
		if ( home && path.isAbsolute( home ) ) {
			return path.join( home, 'safety-net-tests' );
		}
	} catch {}
	return path.join( os.tmpdir(), 'safety-net-tests' );
}

export const config = {
	pluginDir: path.resolve( env( 'SAFETY_NET_PATH', REPO_ROOT ) ),
	php: env( 'SN_TEST_PHP', '8.3' ),
	wp: env( 'SN_TEST_WP', 'latest' ),
	wooVersion: env( 'SN_TEST_WC_VERSION', '11.1.2' ),
	mailpoetVersion: env( 'SN_TEST_MAILPOET_VERSION', '5.41.0' ),
	outputDir: path.resolve( env( 'SN_TEST_OUTPUT', path.join( REPO_ROOT, 'tests/_output' ) ) ),
	cacheDir: path.resolve( env( 'SN_TEST_CACHE', defaultCacheDir() ) ),
	workers: Number( env( 'SN_TEST_WORKERS', '2' ) ),
	// macOS ignores O_APPEND on positioned writes, so concurrent PHP workers there overwrite each other's debug.log and probe.jsonl lines.
	pageConcurrency: Math.max( 1, Number( env( 'SN_TEST_PAGE_CONCURRENCY', process.platform === 'linux' ? env( 'SN_TEST_WORKERS', '2' ) : '1' ) ) || 1 ),
	wooSite: env( 'SN_TEST_WOO_SITE', '' ),
	bootTimeoutMs: Number( env( 'SN_TEST_BOOT_TIMEOUT', '240000' ) ),
	verbose: env( 'SN_TEST_VERBOSE', '' ) === '1',
};

export function assertPluginDir() {
	if ( ! existsSync( path.join( config.pluginDir, 'safety-net.php' ) ) ) {
		throw new Error( `SAFETY_NET_PATH (${ config.pluginDir }) does not contain safety-net.php.` );
	}
}

export function defaultConcurrency() {
	const fromEnv = Number( env( 'SN_TEST_CONCURRENCY', '0' ) );
	if ( fromEnv > 0 ) {
		return fromEnv;
	}
	// Each scenario runs its own WordPress with config.workers PHP threads, so leave headroom on small CI runners.
	return Math.max( 1, Math.min( 4, Math.floor( os.availableParallelism() / 2 ) ) );
}
