import { createServer } from 'node:net';
import { CRITICAL_ERROR_PAGE } from './debug-log.mjs';

export function freePort() {
	return new Promise( ( resolve, reject ) => {
		const srv = createServer();
		srv.unref();
		srv.on( 'error', reject );
		srv.listen( 0, '127.0.0.1', () => {
			const { port } = srv.address();
			srv.close( () => resolve( port ) );
		} );
	} );
}

export class CookieJar {
	constructor() {
		this.cookies = new Map();
	}

	store( res ) {
		for ( const line of res.headers.getSetCookie() ) {
			const [ pair, ...attrs ] = line.split( ';' );
			const eq = pair.indexOf( '=' );
			const name = pair.slice( 0, eq ).trim();
			const value = pair.slice( eq + 1 ).trim();
			const expired = attrs.some( ( attr ) => {
				const [ key, val ] = attr.trim().split( '=' );
				if ( /^max-age$/i.test( key ) ) {
					return Number( val ) <= 0;
				}
				if ( /^expires$/i.test( key ) ) {
					return Date.parse( attr.trim().slice( 8 ) ) < Date.now();
				}
				return false;
			} );
			if ( expired || value === 'deleted' ) {
				this.cookies.delete( name );
			} else {
				this.cookies.set( name, value );
			}
		}
	}

	header() {
		return [ ...this.cookies ].map( ( [ k, v ] ) => `${ k }=${ v }` ).join( '; ' );
	}
}

// Redirects are followed by hand to keep cookies set on a 302 and to send site-URL redirects back to the local server.
export async function request( base, pathOrUrl, { jar, method = 'GET', form, headers = {}, follow = true, maxRedirects = 10, siteUrl } = {} ) {
	let target = new URL( pathOrUrl, base );
	let body = form ? new URLSearchParams( form ).toString() : undefined;
	const chain = [];
	for ( let i = 0; i <= maxRedirects; i++ ) {
		const h = { ...headers };
		if ( jar && jar.cookies.size ) {
			h.cookie = jar.header();
		}
		if ( body ) {
			h[ 'content-type' ] = 'application/x-www-form-urlencoded';
		}
		const res = await fetch( target, { method, body, headers: h, redirect: 'manual', signal: AbortSignal.timeout( 120_000 ) } );
		jar?.store( res );
		chain.push( `${ method } ${ target.pathname }${ target.search } -> ${ res.status }` );
		const location = res.headers.get( 'location' );
		if ( follow && res.status >= 300 && res.status < 400 && location ) {
			await res.arrayBuffer();
			let next = new URL( location, target );
			if ( siteUrl && next.origin === new URL( siteUrl ).origin ) {
				next = new URL( next.pathname + next.search, base );
			}
			target = next;
			if ( res.status !== 307 && res.status !== 308 ) {
				method = 'GET';
				body = undefined;
			}
			continue;
		}
		const text = await res.text();
		return { status: res.status, headers: res.headers, text, url: target.toString(), path: target.pathname + target.search, chain, location };
	}
	throw new Error( `Too many redirects: ${ chain.join( ', ' ) }` );
}

export function findFatalSigns( res ) {
	const problems = [];
	if ( res.status >= 500 ) {
		problems.push( `HTTP ${ res.status }` );
	}
	if ( CRITICAL_ERROR_PAGE.test( res.text ) ) {
		problems.push( 'WordPress "critical error" page' );
	}
	const match = res.text.match( /(?:PHP )?(?:Fatal error|Parse error|Recoverable fatal error|Core error|Compile error|Uncaught [A-Za-z\\_]+(?:Error|Exception))[^\n<]*/ );
	if ( match ) {
		problems.push( match[ 0 ].slice( 0, 400 ) );
	}
	return problems;
}
