import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { config } from './config.mjs';

const ATTEMPTS = 4;

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

// A 404 will not fix itself, so only network errors, timeouts, 429 and 5xx answers are retried.
async function fetchWithRetry( url, { timeoutMs, what } ) {
	let lastError;
	for ( let attempt = 1; attempt <= ATTEMPTS; attempt++ ) {
		let res;
		try {
			res = await fetch( url, { signal: AbortSignal.timeout( timeoutMs ) } );
		} catch ( error ) {
			lastError = error;
		}
		if ( res?.ok ) {
			return res;
		}
		if ( res ) {
			await res.arrayBuffer().catch( () => {} );
			lastError = new Error( `HTTP ${ res.status }` );
			if ( res.status < 500 && res.status !== 429 ) {
				throw new Error( `${ what } failed (HTTP ${ res.status }): ${ url }` );
			}
		}
		if ( attempt < ATTEMPTS ) {
			const delay = 2000 * 2 ** ( attempt - 1 );
			console.error( `  ${ what } attempt ${ attempt } failed (${ lastError?.message ?? lastError }); retrying in ${ delay / 1000 }s` );
			await sleep( delay );
		}
	}
	throw new Error( `${ what } failed after ${ ATTEMPTS } attempts (${ lastError?.message ?? lastError }): ${ url }` );
}

async function download( url, destination ) {
	const partial = `${ destination }.${ process.pid }.partial`;
	for ( let attempt = 1; ; attempt++ ) {
		const res = await fetchWithRetry( url, { timeoutMs: 300_000, what: 'Download' } );
		try {
			writeFileSync( partial, Buffer.from( await res.arrayBuffer() ) );
			break;
		} catch ( error ) {
			if ( attempt >= ATTEMPTS ) {
				throw new Error( `Download of ${ url } kept breaking off: ${ error.message }` );
			}
			await sleep( 2000 * 2 ** ( attempt - 1 ) );
		}
	}
	renameSync( partial, destination );
}

async function resolveWooVersion( version ) {
	if ( version !== 'latest' ) {
		return version;
	}
	const res = await fetchWithRetry( 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=woocommerce&request[fields][sections]=0', { timeoutMs: 60_000, what: 'Resolving the latest WooCommerce version' } );
	return ( await res.json() ).version;
}

export async function ensureWooCommerce( version = config.wooVersion ) {
	const resolved = await resolveWooVersion( version );
	const root = path.join( config.cacheDir, 'woocommerce' );
	const dir = path.join( root, resolved );
	const pluginDir = path.join( dir, 'woocommerce' );
	if ( existsSync( path.join( pluginDir, 'woocommerce.php' ) ) ) {
		return { version: resolved, pluginDir };
	}
	mkdirSync( root, { recursive: true } );
	const zip = path.join( root, `woocommerce.${ resolved }.zip` );
	if ( ! existsSync( zip ) ) {
		await download( `https://downloads.wordpress.org/plugin/woocommerce.${ resolved }.zip`, zip );
	}
	const staging = `${ dir }.${ process.pid }.tmp`;
	rmSync( staging, { recursive: true, force: true } );
	mkdirSync( staging, { recursive: true } );
	const unzip = spawnSync( 'unzip', [ '-q', zip, '-d', staging ], { encoding: 'utf8' } );
	if ( unzip.status !== 0 ) {
		throw new Error( `unzip failed for ${ zip }: ${ unzip.stderr || unzip.error }` );
	}
	try {
		renameSync( staging, dir );
	} catch ( error ) {
		// Another scenario unpacked the same version first.
		rmSync( staging, { recursive: true, force: true } );
		if ( ! existsSync( path.join( pluginDir, 'woocommerce.php' ) ) ) {
			throw error;
		}
	}
	return { version: resolved, pluginDir };
}

// The same phar Playground's own wp-cli blueprint step downloads.
export async function ensureWpCli() {
	const dir = path.join( config.cacheDir, 'wp-cli' );
	const phar = path.join( dir, 'wp-cli.phar' );
	if ( ! existsSync( phar ) ) {
		mkdirSync( dir, { recursive: true } );
		await download( 'https://playground.wordpress.net/wp-cli.phar', phar );
	}
	return dir;
}
