import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertAiKeysScrubbed, assertMailBlocked, assertNoIndex, assertStepFlags, assertToolsAssets, captureMail, FILTER_PROBE, firstLoad, getToolsPage, runAjaxTools } from '../lib/checks.mjs';
import { bootSite } from '../lib/site.mjs';

describe( 'staging-mu: mu-plugin on a development site without WooCommerce, keeping GiveWP data', () => {
	let site;
	let seed;
	let thirdParty;

	before( async () => {
		site = await bootSite( { name: 'staging-mu', env: 'development', mode: 'mu', constants: { SAFETY_NET_SKIP_GIVEWP: true } } );
		seed = await site.php( 'return sn_test_seed_base();', { label: 'seeding the site' } );
		thirdParty = await site.php( 'return sn_test_seed_third_party();', { label: 'seeding third-party plugin tables' } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'S1: the first page load runs the automatic pass, returns 200 and is already noindex', async () => {
		const { res } = await firstLoad( site );
		assertNoIndex( res.text );
	} );

	test( 'A1: every step flag is set', async () => {
		assertStepFlags( await site.php( 'return sn_test_flags();' ), { woocommerce: false } );
	} );

	test( 'A14/A17: plugins, users and options are handled as in plugin mode', async () => {
		const s = await site.php( "return array( 'snapshot' => sn_test_snapshot(), 'ai' => sn_test_ai_state(), 'active' => get_option( 'active_plugins' ), 'path' => SAFETY_NET_PATH );" );
		assert.equal( s.path, '/wordpress/wp-content/mu-plugins/safety-net/' );
		assert.ok( ! s.active.includes( 'mailchimp-for-wp/mailchimp-for-wp.php' ) );
		assert.ok( ! s.active.includes( 'safety-net/safety-net.php' ) );
		assert.ok( s.active.includes( 'barcode-label-printer/barcode-label-printer.php' ) );
		assert.deepEqual( s.snapshot.users, { admin: 1, admin2: seed.users.admin2 } );
		assert.equal( s.snapshot.options.admin_email, 'safetynet@scrubbedthis.option' );
		assert.equal( s.snapshot.options.klaviyo_api_key, '' );
		assertAiKeysScrubbed( s.ai, seed.ai, 'The first load in mu-plugin mode' );
		for ( const plugin of [ 'ai/ai.php', 'ai-provider-for-anthropic/plugin.php', 'aslams-ai-provider-for-grok/ai-provider-for-grok.php' ] ) {
			assert.ok( ! s.active.includes( plugin ), `${ plugin } is still active` );
		}
		assert.ok( s.active.includes( 'ai-services/ai-services.php' ), 'ai-services/ai-services.php was deactivated' );
	} );

	test( 'T2: SAFETY_NET_SKIP_GIVEWP keeps GiveWP data and still clears the rest', async () => {
		const counts = await site.php( `return sn_test_third_party_counts( json_decode( '${ JSON.stringify( thirdParty.tables ) }', true ) );` );
		for ( const [ table, count ] of Object.entries( counts ) ) {
			if ( table.startsWith( 'give_' ) ) {
				assert.equal( count, 1, `${ table } was cleared although SAFETY_NET_SKIP_GIVEWP is true` );
			}
		}
		assert.equal( counts.pmpro_membership_orders, 0 );
		assert.equal( counts.wpforms_entries, 0 );
	} );

	test( 'A25: the Jetpack and PMPro filters are active from mu-plugins too', async () => {
		const s = await site.php( FILTER_PROBE );
		assert.deepEqual( s.jetpack, [ 'non-existing' ] );
		assert.deepEqual( s.pmpro, [] );
	} );

	test( 'A19: emails are blocked', async () => {
		assertMailBlocked( await captureMail( site ) );
	} );

	test( 'S3/S9: robots.txt and the REST status route', async () => {
		const [ robots, rest ] = await site.getAll( [ '/robots.txt', '/wp-json/safety-net/v1/status' ] );
		assert.equal( robots.text, 'User-agent: *\nDisallow: /\n' );
		const status = JSON.parse( rest.text );
		assert.equal( status.environment, 'development' );
		assert.equal( status.data_deleted, true );
	} );

	test( 'S5/S6: the dashboard notice and the must-use plugin list', async () => {
		const jar = await site.login();
		const [ dashboard, mustUse ] = await site.getAll( [ '/wp-admin/', '/wp-admin/plugins.php?plugin_status=mustuse' ].map( ( path ) => ( { path, jar } ) ) );
		assert.match( dashboard.text, /Safety Net Activated/ );
		assert.match( dashboard.text, /environment type is set to "development"/ );
		assert.match( mustUse.text, /safety-net-loader\.php/ );
	} );

	test( 'S7: the Tools page loads its assets from mu-plugins', async () => {
		const { res } = await getToolsPage( site );
		await assertToolsAssets( site, res.text, '/wp-content/mu-plugins/safety-net/' );
	} );

	test( 'S10: every AJAX tool succeeds and removes what was added after the automatic pass', async () => {
		const { nonces } = await getToolsPage( site );
		await runAjaxTools( site, nonces );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
