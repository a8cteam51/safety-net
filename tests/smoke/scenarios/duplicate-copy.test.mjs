import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertStepFlags, firstLoad } from '../lib/checks.mjs';
import { bootSite } from '../lib/site.mjs';

describe( 'duplicate-copy: mu-plugin and regular plugin both active on a "sandbox" site', () => {
	let site;

	before( async () => {
		site = await bootSite( { name: 'duplicate-copy', env: 'sandbox', mode: 'duplicate' } );
		await site.php( 'return sn_test_seed_base();', { label: 'seeding the site' } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'D1: the first load runs once, from the mu-plugin copy', async () => {
		await firstLoad( site );
		const s = await site.php( "return array( 'path' => SAFETY_NET_PATH, 'flags' => sn_test_flags() );" );
		assert.equal( s.path, '/wordpress/wp-content/mu-plugins/safety-net/' );
		assertStepFlags( s.flags, { woocommerce: false } );
	} );

	test( 'D1: the second copy never redeclares anything and Safety Net loads once per request', async () => {
		await site.get( '/' );
		await site.login();
		await site.get( '/wp-admin/plugins.php', { jar: site.adminJar } );
		await site.get( '/wp-admin/tools.php?page=safety_net_options', { jar: site.adminJar } );
		for ( const line of site.probe() ) {
			if ( line.runs?.safety_net_loaded !== null ) {
				assert.ok( line.runs.safety_net_loaded <= 1, `safety_net_loaded fired ${ line.runs.safety_net_loaded } times on ${ line.uri }` );
			}
		}
		assert.deepEqual( site.logEntriesMatching( /Cannot redeclare/ ), [] );
	} );

	test( 'D1: re-activating the regular copy does not error', async () => {
		const result = await site.php( "require_once ABSPATH . 'wp-admin/includes/plugin.php'; $r = activate_plugin( 'safety-net/safety-net.php' ); return is_wp_error( $r ) ? $r->get_error_message() : $r;" );
		assert.equal( result, null );
	} );

	test( 'A26: "sandbox" is treated as non-production although core reports production', async () => {
		const s = await site.php( "return array( 'core' => wp_get_environment_type(), 'safety_net' => SafetyNet\\Utilities\\get_environment_type() );" );
		assert.deepEqual( s, { core: 'production', safety_net: 'sandbox' } );
		const status = JSON.parse( ( await site.get( '/wp-json/safety-net/v1/status' ) ).text );
		assert.equal( status.environment, 'sandbox' );
		assert.match( ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text, /environment type is set to "sandbox"/ );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
