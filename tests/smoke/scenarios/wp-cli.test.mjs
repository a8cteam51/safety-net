import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertAutomaticPassSince, assertStepFlags } from '../lib/checks.mjs';
import { seedWooCommerceSite } from '../lib/fixtures.mjs';
import { bootSite } from '../lib/site.mjs';

const plain = ( text ) => text.replace( /\x1B\[[0-9;]*m/g, '' );

describe( 'wp-cli: the wp safety-net commands on a WooCommerce store', () => {
	let site;
	let seed;

	async function wpOk( ...args ) {
		const res = await site.wp( args );
		assert.equal( res.exitCode, 0, `wp ${ args.join( ' ' ) } exited with ${ res.exitCode }: ${ plain( res.stderr || res.stdout ).slice( 0, 500 ) }` );
		return { ...res, stdout: plain( res.stdout ), stderr: plain( res.stderr ) };
	}

	before( async () => {
		site = await bootSite( { name: 'wp-cli', env: 'staging', mode: 'plugin', woocommerce: 'active', wpCli: true } );
		seed = await seedWooCommerceSite( site, { hpos: true } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'C2: the first WP-CLI call runs the automatic pass', async () => {
		const before = site.probe().length;
		const res = await wpOk( 'option', 'get', 'blogname' );
		// WP-CLI's bundled libraries print their own PHP deprecations first on new PHP versions.
		assert.equal( res.stdout.trim().split( '\n' ).pop(), 'My WordPress Website' );
		assertAutomaticPassSince( site, before, { label: 'wp option get blogname', match: ( line ) => line.wp_cli && line.uri === '' && ! line.php_run, code: null } );
		const s = await site.php( "return array( 'flags' => sn_test_flags(), 'users' => sn_test_users() );" );
		assertStepFlags( s.flags, { woocommerce: true } );
		assert.deepEqual( s.users, { admin: 1, admin2: seed.base.users.admin2 } );
	} );

	test( 'C5: wp help safety-net lists the commands', async () => {
		const help = ( await wpOk( 'help', 'safety-net' ) ).stdout;
		for ( const command of [ 'scrub-options', 'deactivate-plugins', 'delete', 'delete-transients', 'disable-webhooks' ] ) {
			assert.match( help, new RegExp( `^\\s+${ command }\\s`, 'm' ), `wp help safety-net does not list ${ command }` );
		}
	} );

	test( 'C1/S11: each command succeeds and does its job', async () => {
		await site.php( "sn_test_create_user( 'cli_customer', 'customer' ); set_transient( 'sn_cli', 'x', DAY_IN_SECONDS ); update_option( 'klaviyo_api_key', 'again' ); update_option( 'connectors_ai_openai_api_key', 'sk-test-cli' ); update_option( '_secret_ai/openai_api_key', base64_encode( 'sn-test-cli' ), false ); sn_test_activate_plugins( array( 'mailchimp-for-wp/mailchimp-for-wp.php', 'zz-checkout/zz-checkout.php' ) ); return true;", { label: 're-seeding after the automatic pass' } );

		assert.match( ( await wpOk( 'safety-net', 'scrub-options' ) ).stdout, /Success: All options have been scrubbed\./ );
		assert.equal( await site.php( "return sn_test_raw_option( 'klaviyo_api_key' );" ), '' );
		const ai = await site.php( 'return sn_test_ai_state();' );
		assert.deepEqual( { openai: ai.keys.connectors_ai_openai_api_key, secret: ai.keys[ '_secret_ai/openai_api_key' ], backups: ai.backups }, { openai: null, secret: null, backups: [] }, 'wp safety-net scrub-options left an AI provider key or a backup of it' );

		assert.match( ( await wpOk( 'safety-net', 'deactivate-plugins' ) ).stdout, /Success: Problematic plugins have been deactivated\./ );
		const active = await site.php( "return get_option( 'active_plugins' );" );
		assert.ok( ! active.includes( 'mailchimp-for-wp/mailchimp-for-wp.php' ) );
		assert.ok( ! active.includes( 'zz-checkout/zz-checkout.php' ), 'With WooCommerce loaded the gateway plugin goes in the same call' );

		assert.match( ( await wpOk( 'safety-net', 'delete' ) ).stdout, /Success: Users and their data have been deleted/ );
		assert.equal( ( await site.php( 'return sn_test_users();' ) ).cli_customer, undefined );

		assert.match( ( await wpOk( 'safety-net', 'delete-transients' ) ).stdout, /Success: Transients have been deleted/ );
		assert.equal( await site.php( "return sn_test_raw_option( '_transient_sn_cli' );" ), null );

		const webhook = await site.php( "$w = new WC_Webhook(); $w->set_name( 'SN cli hook' ); $w->set_topic( 'order.created' ); $w->set_delivery_url( 'https://example.com/cli' ); $w->set_status( 'active' ); $w->set_user_id( 1 ); return $w->save();" );
		assert.match( ( await wpOk( 'safety-net', 'disable-webhooks' ) ).stdout, /Success: All WooCommerce webhooks have been disabled\./ );
		assert.equal( await site.php( `return wc_get_webhook( ${ webhook } )->get_status();` ), 'disabled' );
	} );

	test( 'K5/K11: on a single site neither the automatic run nor the commands record network-wide changes', async () => {
		const flags = await site.php( 'return sn_test_flags();' );
		assert.equal( flags.safety_net_plugins_deactivated, '1', 'Safety Net never deactivated plugins, so this check would pass for the wrong reason' );
		assert.deepEqual( Object.keys( flags ).filter( ( name ) => name.startsWith( 'safety_net_network_' ) ), [] );
	} );

	let refusedExitCode;

	test( 'C4: with the automatic run disabled, wp safety-net delete refuses to run before the plugins step', async () => {
		await site.php( "update_option( 'sn_test_manual_mode', 1 ); sn_test_create_user( 'cli_guarded', 'customer' ); delete_option( 'safety_net_plugins_deactivated' ); return true;" );
		try {
			const res = await site.wp( [ 'safety-net', 'delete' ] );
			refusedExitCode = res.exitCode;
			assert.match( plain( res.stderr ), /Error: Plugins need to be deactivated first\./ );
			assert.ok( ( await site.php( 'return sn_test_users();' ) ).cli_guarded, 'Users were deleted although the plugins step had not run' );
		} finally {
			await site.php( "delete_option( 'sn_test_manual_mode' ); update_option( 'safety_net_plugins_deactivated', true ); return true;" );
		}
	} );

	test( 'K9: a refused delete or deactivate-plugins exits with a non-zero status', async () => {
		assert.equal( typeof refusedExitCode, 'number', 'C4 never ran the refused command' );
		assert.notEqual( refusedExitCode, 0, 'wp safety-net delete exited with 0 although it refused to run' );

		await site.php( "update_option( 'sn_test_manual_mode', 1 ); sn_test_activate_plugins( array( 'mailchimp-for-wp/mailchimp-for-wp.php' ) ); delete_option( 'safety_net_options_scrubbed' ); return true;" );
		try {
			const res = await site.wp( [ 'safety-net', 'deactivate-plugins' ] );
			assert.notEqual( res.exitCode, 0, 'wp safety-net deactivate-plugins exited with 0 although it refused to run' );
			assert.match( plain( res.stderr ), /Error: Options need to be scrubbed first\./ );
			assert.ok( ( await site.php( "return get_option( 'active_plugins' );" ) ).includes( 'mailchimp-for-wp/mailchimp-for-wp.php' ), 'Plugins were deactivated although the scrub step had not run' );
		} finally {
			await site.php( "delete_option( 'sn_test_manual_mode' ); update_option( 'safety_net_options_scrubbed', true ); return true;" );
		}
	} );

	test( 'K10: the documented "wp safety-net delete-transients" works, and so does the old delete_transients name', async () => {
		for ( const command of [ 'delete-transients', 'delete_transients' ] ) {
			await site.php( "set_transient( 'sn_k10', 'x', DAY_IN_SECONDS ); return true;" );
			const res = await site.wp( [ 'safety-net', command ] );
			assert.equal( res.exitCode, 0, `wp safety-net ${ command }: ${ plain( res.stderr ) }` );
			assert.match( plain( res.stdout ), /Success: Transients have been deleted/ );
			assert.equal( await site.php( "return sn_test_raw_option( '_transient_sn_k10' );" ), null, `wp safety-net ${ command } left the transient` );
		}
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
