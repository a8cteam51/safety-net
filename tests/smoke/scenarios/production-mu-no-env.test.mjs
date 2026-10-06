import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertNoFlags, assertStepFlags, firstLoad, GITHUB_RELEASE_URL, githubRelease } from '../lib/checks.mjs';
import { bootSite } from '../lib/site.mjs';

describe( 'production-mu-no-env: mu-plugin with WP_ENVIRONMENT_TYPE undefined stays dormant', () => {
	let site;
	let baseline;

	before( async () => {
		site = await bootSite( { name: 'production-mu-no-env', env: null, mode: 'mu' } );
		await site.php( 'return sn_test_seed_base();', { label: 'seeding the site' } );
		baseline = await site.php( 'return sn_test_snapshot();', { label: 'baseline snapshot' } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'P3: the environment type is really undefined and falls back to production', async () => {
		const s = await site.php( "return array( 'defined' => defined( 'WP_ENVIRONMENT_TYPE' ), 'getenv' => getenv( 'WP_ENVIRONMENT_TYPE' ), 'core' => wp_get_environment_type(), 'safety_net' => SafetyNet\\Utilities\\get_environment_type() );" );
		assert.deepEqual( s, { defined: false, getenv: false, core: 'production', safety_net: 'production' } );
	} );

	test( 'P3/P4: pages load and nothing is touched', async () => {
		await site.get( '/' );
		await site.get( '/' );
		await site.login();
		await site.get( '/wp-admin/', { jar: site.adminJar } );
		const now = await site.php( 'return sn_test_snapshot();' );
		assertNoFlags( now.flags );
		assert.deepEqual( now.backups, [] );
		assert.deepEqual( now.options, baseline.options );
		assert.deepEqual( now.users, baseline.users );
	} );

	test( 'P2: the notice asks to remove the mu-plugin', async () => {
		const res = await site.get( '/wp-admin/', { jar: site.adminJar } );
		assert.match( res.text, /Safety Net is active on a production site[^<]*remove the mu-plugin or switch the site/ );
	} );

	test( 'P4: the REST route and the Tools page do not exist', async () => {
		await site.get( '/wp-json/safety-net/v1/status', { expect: 404 } );
		await site.get( '/wp-admin/tools.php?page=safety_net_options', { jar: site.adminJar, expect: 403 } );
	} );

	test( 'U8: no update is offered for an mu-plugin', async () => {
		site.setHttpMocks( { [ GITHUB_RELEASE_URL ]: githubRelease( 'v99.0.0' ) } );
		const page = await site.get( '/wp-admin/plugins.php?plugin_status=mustuse', { jar: site.adminJar } );
		assert.doesNotMatch( page.text, /There is a new version of Safety Net available/ );
		const offered = await site.php( "delete_site_transient( 'update_plugins' ); wp_update_plugins(); $t = get_site_transient( 'update_plugins' ); return array_keys( (array) $t->response );" );
		assert.deepEqual( offered.filter( ( plugin ) => plugin.includes( 'safety-net' ) ), [] );
	} );

	// Playground cannot set environment variables for HTTP requests, so the helper mu-plugin putenv()s it before Safety Net loads.
	test( 'A27: "sandbox" set as the WP_ENVIRONMENT_TYPE environment variable later runs everything on the next load', async () => {
		await site.php( "update_option( 'sn_test_env', 'sandbox' ); return true;" );
		const { probe } = await firstLoad( site );
		assert.equal( probe.env, 'production', 'Core should still report production for "sandbox"' );
		const s = await site.php( "return array( 'defined' => defined( 'WP_ENVIRONMENT_TYPE' ), 'getenv' => getenv( 'WP_ENVIRONMENT_TYPE' ), 'core' => wp_get_environment_type(), 'safety_net' => SafetyNet\\Utilities\\get_environment_type(), 'snapshot' => sn_test_snapshot() );" );
		assert.deepEqual( { defined: s.defined, getenv: s.getenv, core: s.core, safety_net: s.safety_net }, { defined: false, getenv: 'sandbox', core: 'production', safety_net: 'sandbox' } );
		assertStepFlags( s.snapshot.flags, { woocommerce: false } );
		assert.equal( s.snapshot.options.admin_email, 'safetynet@scrubbedthis.option' );
		assert.deepEqual( Object.keys( s.snapshot.users ).sort(), [ 'admin', 'admin2' ] );
		assert.equal( JSON.parse( ( await site.get( '/wp-json/safety-net/v1/status' ) ).text ).environment, 'sandbox' );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
