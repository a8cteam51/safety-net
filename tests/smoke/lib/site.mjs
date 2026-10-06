import assert from 'node:assert/strict';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { defaultWpCliPath, enableMultisite } from '@wp-playground/blueprints';
import { runCLI } from '@wp-playground/cli';
import { SCENARIOS } from '../scenarios.mjs';
import { assertPluginDir, config, FIXTURES_DIR } from './config.mjs';
import { CRITICAL_ERROR_PAGE, findLogProblems, LOG_CANARY, readLogEntries } from './debug-log.mjs';
import { ensureMailPoet, ensureWooCommerce, ensureWpCli } from './downloads.mjs';
import { CookieJar, findFatalSigns, freePort, request } from './http.mjs';

export const MULTISITE_URL = 'http://sn-multisite.test';

const MODES = [ 'plugin', 'mu', 'network', 'duplicate' ];

function phpString( value ) {
	return `'${ String( value ).replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) }'`;
}

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

function withTimeout( promise, ms, label ) {
	let timer;
	const timeout = new Promise( ( resolve, reject ) => {
		timer = setTimeout( () => reject( new Error( `${ label } did not finish within ${ ms / 1000 }s` ) ), ms );
	} );
	return Promise.race( [ promise, timeout ] ).finally( () => clearTimeout( timer ) );
}

async function readStream( stream ) {
	let text = '';
	const decoder = new TextDecoder();
	for await ( const chunk of stream ) {
		text += typeof chunk === 'string' ? chunk : decoder.decode( chunk, { stream: true } );
	}
	return text;
}

const isLoginPage = ( value ) => /\/wp-login\.php(?:$|\?)/.test( value );

export class Site {
	constructor( { name, outDir, server, siteUrl, mode } ) {
		this.name = name;
		this.outDir = outDir;
		this.server = server;
		this.playground = server.playground;
		this.url = server.serverUrl;
		this.siteUrl = siteUrl;
		this.mode = mode;
		this.logFile = path.join( outDir, 'debug.log' );
		this.logChecked = 0;
		this.adminJar = new CookieJar();
	}

	where() {
		return `scenario "${ this.name }", logs in ${ this.outDir }`;
	}

	// Only entries since the last call, so a fatal is pinned to the request that caused it.
	newLogEntries() {
		const entries = readLogEntries( this.logFile );
		const fresh = entries.slice( this.logChecked );
		this.logChecked = entries.length;
		return fresh;
	}

	logText() {
		return existsSync( this.logFile ) ? readFileSync( this.logFile, 'utf8' ) : '';
	}

	logMark() {
		return readLogEntries( this.logFile ).length;
	}

	logEntriesSince( mark ) {
		return readLogEntries( this.logFile ).slice( mark );
	}

	// Every request logs the canary, so a missing one means debug.log is not being written and "no errors" proves nothing.
	assertLogAlive() {
		assert.ok( existsSync( this.logFile ), `debug.log was never written, so the log checks cannot prove anything (${ this.where() })` );
		assert.ok( this.logText().includes( LOG_CANARY ), `debug.log has no "${ LOG_CANARY }" line from the helper mu-plugin, so the log checks cannot prove anything (${ this.where() })` );
	}

	logEntriesMatching( pattern ) {
		this.assertLogAlive();
		return readLogEntries( this.logFile ).filter( ( entry ) => pattern.test( entry ) ).map( ( entry ) => entry.slice( 0, 400 ) );
	}

	assertNoNewFatals( context ) {
		const { fatals } = findLogProblems( this.newLogEntries() );
		assert.deepEqual( fatals, [], `${ context } logged a PHP fatal error (${ this.where() }):\n${ fatals.join( '\n' ) }` );
	}

	async http( pathOrUrl, options = {} ) {
		const sent = await this.#request( pathOrUrl, options );
		this.#assertNoFatal( sent, findLogProblems( this.newLogEntries() ).fatals );
		this.#assertResponse( sent, options );
		return sent.res;
	}

	async #request( pathOrUrl, { jar, method = 'GET', form, headers, follow = true } = {} ) {
		const target = typeof pathOrUrl === 'string' && pathOrUrl.startsWith( 'http' ) ? new URL( pathOrUrl ).pathname + new URL( pathOrUrl ).search : pathOrUrl;
		const res = await request( this.url, target, { jar, method, form, headers, follow, siteUrl: this.siteUrl } );
		return { res, target, label: `${ method } ${ target }`, signs: findFatalSigns( res ) };
	}

	#assertNoFatal( { res, label, signs }, fatals ) {
		assert.deepEqual( [ ...signs, ...fatals ], [], `${ label } failed with a fatal error (${ this.where() }). Redirects: ${ res.chain.join( ', ' ) }\n${ [ ...signs, ...fatals ].join( '\n' ) }` );
	}

	#assertResponse( { res, target, label }, { expect = 200, jar } = {} ) {
		if ( expect !== null ) {
			const expected = Array.isArray( expect ) ? expect : [ expect ];
			assert.ok( expected.includes( res.status ), `${ label } returned HTTP ${ res.status }, expected ${ expected.join( ' or ' ) } (${ this.where() }). Redirects: ${ res.chain.join( ', ' ) }\nBody starts: ${ res.text.slice( 0, 500 ) }` );
		}
		if ( jar && ! isLoginPage( new URL( target, this.url ).pathname ) ) {
			const onLogin = isLoginPage( new URL( res.url ).pathname ) || isLoginPage( res.location ?? '' ) || /id="loginform"/.test( res.text );
			assert.ok( ! onLogin, `${ label } ended on the login form, so the cookie jar is not logged in (${ this.where() }). Redirects: ${ res.chain.join( ', ' ) }` );
		}
	}

	get( pathOrUrl, options = {} ) {
		return this.http( pathOrUrl, options );
	}

	// Only for GETs that change nothing and do not depend on each other's order, such as pages loaded after the first load.
	async getAll( requests, { concurrency = config.pageConcurrency } = {} ) {
		const items = requests.map( ( item ) => ( typeof item === 'string' ? { path: item } : item ) );
		assert.ok( items.every( ( item ) => item.method === undefined && item.form === undefined ), 'getAll only sends GET requests' );
		const probeMark = this.probe().length;
		const logMark = this.logMark();
		const sent = new Array( items.length );
		let next = 0;
		const lane = async () => {
			for ( let i = next++; i < items.length; i = next++ ) {
				const { path: target, ...options } = items[ i ];
				try {
					sent[ i ] = { ...( await this.#request( target, options ) ), options };
				} catch ( error ) {
					sent[ i ] = { error, label: `GET ${ target }` };
				}
			}
		};
		await Promise.all( Array.from( { length: Math.max( 1, Math.min( concurrency, items.length ) ) }, lane ) );

		const { fatals } = findLogProblems( this.newLogEntries() );
		const probes = this.probe().slice( probeMark );
		const booted = this.logEntriesSince( logMark ).map( ( entry ) => entry.split( `] ${ LOG_CANARY }` ) ).filter( ( parts ) => parts.length === 2 ).map( ( [ , uri ] ) => uri.trim() );
		// Concurrent requests interleave in debug.log, so a fatal is pinned through the probe line that recorded it, or the boot line of a request that died before writing one.
		const unfinished = [ ...booted ];
		for ( const { uri } of probes ) {
			const index = unfinished.indexOf( uri );
			if ( index !== -1 ) {
				unfinished.splice( index, 1 );
			}
		}
		const culprits = fatals.length ? [ ...probes.filter( ( line ) => line.fatal ).map( ( line ) => line.uri ), ...unfinished ] : [];
		const labels = sent.map( ( { label } ) => label ).join( ', ' );
		const failures = [];
		let fatalsReported = false;
		for ( const result of sent ) {
			if ( result.error ) {
				failures.push( { error: result.error, label: result.label } );
				continue;
			}
			const hops = result.res.chain.map( ( hop ) => hop.replace( /^\S+ /, '' ).replace( / -> \d+$/, '' ) );
			const blamed = culprits.some( ( uri ) => hops.includes( uri ) );
			fatalsReported ||= blamed || result.signs.length > 0;
			try {
				this.#assertNoFatal( result, blamed || result.signs.length ? fatals : [] );
				this.#assertResponse( result, result.options );
			} catch ( error ) {
				failures.push( { error } );
			}
		}
		if ( fatals.length && ! fatalsReported ) {
			failures.push( { error: new assert.AssertionError( { message: `One of ${ labels } failed with a fatal error (${ this.where() }):\n${ fatals.join( '\n' ) }` } ) } );
		}
		if ( booted.length !== probes.length ) {
			failures.push( { error: new assert.AssertionError( { message: `debug.log has ${ booted.length } "${ LOG_CANARY }" lines but probe.jsonl has ${ probes.length } for ${ labels }, so a request died before writing its probe line or lines were lost while ${ concurrency } requests ran at once; where appends are not atomic, set SN_TEST_PAGE_CONCURRENCY=1 (${ this.where() })` } ) } );
		}
		if ( failures.length === 1 ) {
			throw failures[ 0 ].error;
		}
		if ( failures.length > 1 ) {
			const messages = failures.map( ( { error, label } ) => ( label ? `${ label } failed: ${ error.message }` : error.message ) );
			assert.fail( [ messages[ 0 ], `${ messages.length - 1 } more failed in the same batch:`, ...messages.slice( 1 ) ].join( '\n\n' ) );
		}
		return sent.map( ( { res } ) => res );
	}

	post( pathOrUrl, form, options = {} ) {
		return this.http( pathOrUrl, { ...options, method: 'POST', form } );
	}

	// A network user without a role on the main site needs redirectTo their own site's dashboard, since the main one refuses them.
	async login( { user = 'admin', password = 'password', jar = this.adminJar, redirectTo = '/wp-admin/' } = {} ) {
		jar.cookies.set( 'wordpress_test_cookie', 'WP%20Cookie%20check' );
		const res = await this.post( '/wp-login.php', { log: user, pwd: password, 'wp-submit': 'Log In', redirect_to: redirectTo, testcookie: '1' }, { jar } );
		assert.ok( [ ...jar.cookies.keys() ].some( ( name ) => name.startsWith( 'wordpress_logged_in_' ) ), `Logging in as ${ user } did not set an auth cookie (${ this.where() }). Redirects: ${ res.chain.join( ', ' ) }` );
		return jar;
	}

	// With load, this is a page load like any other, so Safety Net's automatic pass may run in it.
	async php( body, { load = true, path: requestPath = '/', label = 'PHP snippet', raw = false } = {} ) {
		const host = new URL( this.siteUrl ).host;
		const code = `<?php
$_SERVER['HTTP_HOST'] = ${ phpString( host ) };
$_SERVER['SERVER_NAME'] = ${ phpString( new URL( this.siteUrl ).hostname ) };
$_SERVER['REQUEST_URI'] = ${ phpString( requestPath ) };
$_SERVER['SN_TEST_PHP_RUN'] = '1';
${ load ? "require '/wordpress/wp-load.php';" : '' }
require_once '/fixtures/php/helpers.php';
require_once '/fixtures/php/seed.php';
require_once '/fixtures/php/mailpoet.php';
$sn_test_result = ( function () {
${ body }
} )();
echo "\\n@@SN_TEST_RESULT@@" . json_encode( $sn_test_result ) . "@@SN_TEST_END@@";
`;
		let response;
		try {
			response = await withTimeout( this.playground.run( { code } ), 180_000, label );
		} catch ( error ) {
			const { fatals } = findLogProblems( this.newLogEntries() );
			throw new Error( `${ label } failed in PHP (${ this.where() }): ${ String( error.message ).slice( 0, 3000 ) }${ fatals.length ? `\nFatal errors in debug.log:\n${ fatals.join( '\n' ) }` : '' }` );
		}
		this.assertNoNewFatals( label );
		const text = response.text;
		assert.doesNotMatch( text, CRITICAL_ERROR_PAGE, `${ label } produced the WordPress critical error page (${ this.where() }):\n${ text.slice( 0, 1000 ) }` );
		if ( raw ) {
			return text;
		}
		const match = text.match( /@@SN_TEST_RESULT@@([\s\S]*)@@SN_TEST_END@@/ );
		if ( ! match ) {
			throw new Error( `${ label } produced no result (${ this.where() }). Output:\n${ text.slice( 0, 3000 ) }` );
		}
		return JSON.parse( match[ 1 ] );
	}

	async wp( args, { url } = {} ) {
		const argv = [ '/internal/shared/bin/php', '/sn-wp-cli/wp-cli.phar', '--path=/wordpress', ...( url ? [ `--url=${ url }` ] : [] ), ...args ];
		const proc = await this.playground.cli( argv );
		const [ exitCode, stdout, stderr ] = await withTimeout( Promise.all( [ proc.exitCode, readStream( proc.stdout ), readStream( proc.stderr ) ] ), 180_000, `wp ${ args.join( ' ' ) }` );
		this.assertNoNewFatals( `wp ${ args.join( ' ' ) }` );
		return { exitCode, stdout, stderr };
	}

	probe() {
		const file = path.join( this.outDir, 'probe.jsonl' );
		if ( ! existsSync( file ) ) {
			return [];
		}
		return readFileSync( file, 'utf8' ).split( '\n' ).filter( Boolean ).map( ( line ) => JSON.parse( line ) );
	}

	setHttpMocks( mocks ) {
		writeFileSync( path.join( this.outDir, 'http-mock.json' ), JSON.stringify( mocks, null, 2 ) );
	}

	assertCleanLog( allow = [] ) {
		this.assertLogAlive();
		const { fatals, safetyNet, allowed } = findLogProblems( readLogEntries( this.logFile ), allow );
		writeFileSync( path.join( this.outDir, 'log-summary.json' ), JSON.stringify( { fatals, safetyNet, allowed: allowed.length }, null, 2 ) );
		assert.deepEqual( fatals, [], `debug.log has PHP fatal errors (${ this.where() }):\n${ fatals.join( '\n' ) }` );
		assert.deepEqual( safetyNet, [], `debug.log has warnings or database errors from Safety Net (${ this.where() }):\n${ safetyNet.join( '\n' ) }` );
	}

	async enableSafetyNet() {
		const enable = {
			plugin: "sn_test_activate_plugins( array( 'safety-net/safety-net.php' ) );",
			mu: 'sn_test_write_mu_loader();',
			network: "update_site_option( 'active_sitewide_plugins', array_merge( (array) get_site_option( 'active_sitewide_plugins', array() ), array( 'safety-net/safety-net.php' => time() ) ) );",
			duplicate: "sn_test_write_mu_loader(); sn_test_activate_plugins( array( 'safety-net/safety-net.php' ) );",
		}[ this.mode ];
		await this.php( `${ enable } return true;`, { label: `enabling Safety Net (${ this.mode } mode)` } );
	}

	async stop() {
		await this.server?.[ Symbol.asyncDispose ]();
		if ( this.copyDir ) {
			rmSync( this.copyDir, { recursive: true, force: true } );
		}
	}
}

// update_option() would skip these, since WP_SITEURL and WP_HOME already filter the old value to the new URL.
const setSiteUrl = ( url ) => `global $wpdb; foreach ( array( 'siteurl', 'home' ) as $option ) { $wpdb->update( $wpdb->options, array( 'option_value' => ${ phpString( url ) } ), array( 'option_name' => $option ) ); }`;

// Safety Net is copied in but not enabled, so each scenario decides which request is the first load.
export async function bootSite( { name, env = 'staging', mode = 'plugin', multisite = false, woocommerce = false, mailpoet = false, wpCli = false, beforeInstall = false, constants = {}, buildIn = null } ) {
	assertPluginDir();
	assert.ok( MODES.includes( mode ), `Unknown mode ${ mode }` );
	assert.ok( ! beforeInstall || mode === 'mu', 'beforeInstall only supports mu mode' );
	const fromWooSite = ! buildIn && config.wooSite !== '' && Boolean( SCENARIOS.find( ( s ) => s.name === name )?.needs?.includes( 'woocommerce-site' ) );
	assert.ok( ! fromWooSite || ( woocommerce === 'active' && ! multisite && ! beforeInstall ), `Scenario "${ name }" needs the prebuilt WooCommerce site, which only fits WooCommerce active on a single site` );
	const outDir = path.join( config.outputDir, name );
	rmSync( outDir, { recursive: true, force: true } );
	mkdirSync( outDir, { recursive: true } );

	const baseMounts = [
		...( buildIn ? [] : [ { hostPath: config.pluginDir, vfsPath: '/sn-src' } ] ),
		{ hostPath: FIXTURES_DIR, vfsPath: '/fixtures' },
		{ hostPath: outDir, vfsPath: '/out' },
	];
	const mounts = beforeInstall ? [] : [ ...baseMounts ];
	const mountBeforeInstall = beforeInstall ? [ ...baseMounts, { hostPath: stageMuPlugins( outDir ), vfsPath: '/wordpress/wp-content/mu-plugins' } ] : [];
	let woo = null;
	if ( woocommerce ) {
		woo = await ensureWooCommerce();
		mounts.push( { hostPath: woo.pluginDir, vfsPath: '/wordpress/wp-content/plugins/woocommerce' } );
	}
	let mailPoet = null;
	if ( mailpoet ) {
		mailPoet = await ensureMailPoet();
		mounts.push( { hostPath: mailPoet.pluginDir, vfsPath: '/wordpress/wp-content/plugins/mailpoet' } );
	}
	if ( wpCli ) {
		mounts.push( { hostPath: await ensureWpCli(), vfsPath: '/sn-wp-cli' } );
	}
	const wpCliPhar = multisite ? readFileSync( path.join( await ensureWpCli(), 'wp-cli.phar' ) ) : null;

	// A new directory per attempt, since a stalled attempt may still be writing to the previous one.
	let wordpressDir = null;
	const newWordpressDir = () => {
		if ( buildIn ) {
			wordpressDir = mkdtempSync( path.join( buildIn, 'woocommerce-site-' ) );
		} else if ( fromWooSite ) {
			wordpressDir = mkdtempSync( path.join( path.dirname( config.wooSite ), `${ name }-` ) );
			cpSync( config.wooSite, wordpressDir, { recursive: true } );
		}
		return wordpressDir;
	};

	const siteUrl = multisite ? MULTISITE_URL : null;
	const started = Date.now();
	const startServer = async ( port ) => {
		const dir = newWordpressDir();
		return runCLI( {
			command: 'server',
			port,
			php: config.php,
			wp: config.wp,
			'site-url': siteUrl ?? `http://127.0.0.1:${ port }`,
			verbosity: config.verbose ? 'normal' : 'quiet',
			workers: config.workers,
			blueprint: {
				preferredVersions: { php: config.php, wp: config.wp },
				steps: [],
			},
			// Copies, because runCLI adds mounts of its own per-boot temp dir to these arrays, and a retry must not reuse them.
			mount: structuredClone( mounts ),
			'mount-before-install': [ ...( dir ? [ { hostPath: dir, vfsPath: '/wordpress' } ] : [] ), ...structuredClone( mountBeforeInstall ) ],
			...( fromWooSite ? { wordpressInstallMode: 'do-not-attempt-installing' } : {} ),
			define: {
				WP_DEBUG_LOG: '/out/debug.log',
				...( env === null ? {} : { WP_ENVIRONMENT_TYPE: env } ),
			},
			'define-bool': {
				WP_DEBUG: true,
				WP_DEBUG_DISPLAY: false,
				// Keeps state still between requests; wp-cron.php can still be requested directly.
				DISABLE_WP_CRON: true,
				...constants,
			},
		} );
	};
	// The enableMultisite blueprint step would download wp-cli.phar from playground.wordpress.net on every boot.
	const boot = async ( port ) => {
		const server = await startServer( port );
		if ( wpCliPhar ) {
			try {
				await server.playground.writeFile( defaultWpCliPath, wpCliPhar );
				await enableMultisite( server.playground, {} );
			} catch ( error ) {
				await server[ Symbol.asyncDispose ]();
				throw error;
			}
		}
		return server;
	};
	const bootOnce = async () => {
		const pending = boot( await freePort() );
		try {
			return await withTimeout( pending, config.bootTimeoutMs, `Booting WordPress Playground for scenario "${ name }"` );
		} catch ( error ) {
			// A stalled boot can still finish later; shut it down then so it does not keep its port and workers.
			pending.then( ( late ) => late?.[ Symbol.asyncDispose ]?.() ).catch( () => {} );
			throw error;
		}
	};
	// Safety Net is only active this early with beforeInstall, where a failed boot may be the bug under test, so only other boots are retried.
	const attempts = beforeInstall ? 1 : 3;
	let server;
	for ( let attempt = 1; ! server; attempt++ ) {
		try {
			server = await bootOnce();
		} catch ( error ) {
			if ( wordpressDir ) {
				try {
					rmSync( wordpressDir, { recursive: true, force: true } );
				} catch {}
			}
			if ( attempt >= attempts ) {
				throw new Error( `WordPress Playground did not boot for scenario "${ name }" (${ attempt } attempt${ attempt > 1 ? 's' : '' }): ${ error.stack ?? error }${ beforeInstall ? installDiagnostics( outDir ) : '' }` );
			}
			for ( const file of [ 'debug.log', 'probe.jsonl', 'http.jsonl' ] ) {
				rmSync( path.join( outDir, file ), { force: true } );
			}
			await sleep( 2000 * attempt );
		}
	}

	const site = new Site( { name, outDir, server, siteUrl: siteUrl ?? server.serverUrl, mode } );
	site.woocommerce = woo?.version ?? null;
	site.wordpressDir = wordpressDir;
	site.copyDir = fromWooSite ? wordpressDir : null;
	try {
		// Playground answers the first HTTP request with a bare redirect to itself without running PHP.
		await fetch( server.serverUrl + '/', { redirect: 'manual' } ).then( ( r ) => r.arrayBuffer() );

		site.versions = await site.php( "include '/wordpress/wp-includes/version.php'; return array( 'php' => PHP_VERSION, 'wp' => $wp_version );", { load: false, label: 'version check' } );
		site.bootMs = Date.now() - started;
		assert.ok( site.versions.php.startsWith( `${ config.php }.` ), `Requested PHP ${ config.php } but the site runs ${ site.versions.php } (${ site.where() })` );
		if ( /^\d+\.\d+(\.\d+)?$/.test( config.wp ) ) {
			assert.ok( site.versions.wp === config.wp || site.versions.wp.startsWith( `${ config.wp }.` ), `Requested WordPress ${ config.wp } but the site runs ${ site.versions.wp } (${ site.where() })` );
		}
		writeFileSync( path.join( outDir, 'environment.json' ), JSON.stringify( { ...site.versions, woocommerce: site.woocommerce, mailpoet: mailPoet?.version ?? null, env, mode, multisite, plugin: buildIn ? null : config.pluginDir, fromWooCommerceSite: fromWooSite, bootMs: site.bootMs }, null, 2 ) );

		const installTo = mode === 'mu' ? 'WPMU_PLUGIN_DIR' : 'WP_PLUGIN_DIR';
		await ( beforeInstall ? Promise.resolve() : site.php(
			`
sn_test_install_helper_mu_plugin();
${ buildIn ? '' : `sn_test_install_safety_net( ${ installTo } . '/safety-net' );` }
${ mode === 'duplicate' ? "sn_test_install_safety_net( WPMU_PLUGIN_DIR . '/safety-net' );" : '' }
${ fromWooSite ? `${ setSiteUrl( site.siteUrl ) } if ( ! function_exists( 'WC' ) ) { throw new RuntimeException( 'WooCommerce is not active in the copy of the prebuilt site' ); }` : '' }
return true;`,
			{ label: buildIn ? 'installing the test helper' : 'installing Safety Net and the test helper' }
		) );

		if ( mailpoet && mailpoet !== 'inactive' ) {
			await site.php(
				`
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$result = activate_plugin( 'mailpoet/mailpoet.php' );
if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
return true;`,
				{ label: 'activating MailPoet' }
			);
		}

		if ( woocommerce && woocommerce !== 'inactive' && ! fromWooSite ) {
			await site.php(
				`
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$result = activate_plugin( 'woocommerce/woocommerce.php', '', ${ woocommerce === 'network' ? 'true' : 'false' } );
if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
return true;`,
				{ label: 'activating WooCommerce' }
			);
		}
	} catch ( error ) {
		await site.stop();
		throw error;
	}
	return site;
}

// Never holds Safety Net, and never gets a wp-admin request, since WooCommerce finishes setting up a new store on the first admin_init.
export async function buildWooCommerceSite( parentDir ) {
	const site = await bootSite( { name: '_woocommerce-site', woocommerce: 'active', buildIn: parentDir } );
	try {
		const state = await site.php(
			"global $wpdb; return array( 'woocommerce' => WC()->version, 'db_version' => get_option( 'woocommerce_db_version' ), 'needs_db_update' => WC_Install::needs_db_update(), 'safety_net_options' => $wpdb->get_col( $wpdb->prepare( \"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s\", $wpdb->esc_like( 'safety_net' ) . '%' ) ) );",
			{ label: 'checking the WooCommerce install' }
		);
		assert.deepEqual( state, { woocommerce: site.woocommerce, db_version: site.woocommerce, needs_db_update: false, safety_net_options: [] }, `WooCommerce did not install as expected (${ site.where() })` );
		site.assertCleanLog();
		return { dir: site.wordpressDir, versions: site.versions, woocommerce: site.woocommerce };
	} finally {
		await site.stop();
	}
}

function installDiagnostics( outDir ) {
	const probeFile = path.join( outDir, 'probe.jsonl' );
	const probe = existsSync( probeFile ) ? readFileSync( probeFile, 'utf8' ).split( '\n' ).filter( Boolean ).map( ( line ) => JSON.parse( line ) ) : [];
	const lines = probe
		.filter( ( line ) => line.at_load?.installing && line.at_load?.installed === false )
		.map( ( line ) => `${ line.method || 'PHP' } ${ line.uri || '(PHP run)' }: HTTP ${ line.code }${ line.sn_path ? `, Safety Net loaded during the core install (sn_path=${ line.sn_path })` : ', Safety Net stepped aside' }${ line.fatal ? `, fatal: ${ JSON.stringify( line.fatal ) }` : '' }` );
	const entries = readLogEntries( path.join( outDir, 'debug.log' ) );
	const { fatals } = findLogProblems( entries );
	const dbErrors = entries.filter( ( entry ) => /WordPress database error/.test( entry ) ).map( ( entry ) => entry.slice( 0, 600 ) );
	return [
		'',
		lines.length ? `Install-time requests (probe.jsonl):\n  ${ lines.join( '\n  ' ) }` : 'probe.jsonl has no install-time requests, so the helper mu-plugin never ran during the install.',
		fatals.length ? `Fatal errors in debug.log:\n  ${ fatals.join( '\n  ' ) }` : 'No fatal errors in debug.log.',
		dbErrors.length ? `Database errors in debug.log:\n  ${ dbErrors.join( '\n  ' ) }` : 'No database errors in debug.log.',
	].join( '\n' );
}

// WordPress installs before any PHP snippet can run, so these mu-plugins are staged on the host.
function stageMuPlugins( outDir ) {
	const dir = path.join( outDir, 'mu-plugins' );
	mkdirSync( dir, { recursive: true } );
	cpSync( path.join( FIXTURES_DIR, 'mu-plugins' ), dir, { recursive: true } );
	const skip = new Set( [ 'node_modules', 'tests', 'package.json', 'package-lock.json' ] );
	for ( const entry of readdirSync( config.pluginDir ) ) {
		if ( ! entry.startsWith( '.' ) && ! skip.has( entry ) ) {
			cpSync( path.join( config.pluginDir, entry ), path.join( dir, 'safety-net', entry ), { recursive: true } );
		}
	}
	writeFileSync( path.join( dir, 'safety-net-loader.php' ), "<?php\nrequire_once WPMU_PLUGIN_DIR . '/safety-net/safety-net.php';\n" );
	return dir;
}

export function phpAtLeast( site, version ) {
	const [ major, minor ] = site.versions.php.split( '.' ).map( Number );
	const [ wantMajor, wantMinor ] = version.split( '.' ).map( Number );
	return major > wantMajor || ( major === wantMajor && minor >= wantMinor );
}

export function scrapeNonces( html ) {
	const nonces = {};
	for ( const match of html.matchAll( /<button[^>]*id="(safety-net-[a-z-]+)"[^>]*data-nonce="([a-f0-9]+)"/g ) ) {
		nonces[ match[ 1 ] ] = match[ 2 ];
	}
	return nonces;
}
