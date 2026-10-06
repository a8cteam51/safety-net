import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { firstLoad, httpProbeSince } from '../lib/checks.mjs';
import { bootSite } from '../lib/site.mjs';

const STATE = "return array( 'flags' => sn_test_flags(), 'active' => get_option( 'active_plugins' ) );";

describe( 'woocommerce-activated-later: gateway plugins wait until WooCommerce is activated', () => {
	let site;

	before( async () => {
		site = await bootSite( { name: 'woocommerce-activated-later', env: 'staging', mode: 'plugin', woocommerce: 'inactive' } );
		await site.php( 'return sn_test_seed_base();', { label: 'seeding the site' } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'W5: without WooCommerce the gateway pass stays pending across loads', async () => {
		await firstLoad( site );
		await site.get( '/' );
		const s = await site.php( STATE );
		assert.equal( s.flags.safety_net_gateway_plugins_pending, '1' );
		assert.equal( s.flags.safety_net_gateway_plugins_deactivated, undefined );
		assert.ok( s.active.includes( 'zz-checkout/zz-checkout.php' ) );
	} );

	test( 'W5: the first load with WooCommerce active deactivates the gateway plugin', async () => {
		const beforeActivation = site.probe().length;
		await site.php( "require_once ABSPATH . 'wp-admin/includes/plugin.php'; $r = activate_plugin( 'woocommerce/woocommerce.php' ); if ( is_wp_error( $r ) ) { throw new RuntimeException( $r->get_error_message() ); } return true;", { label: 'activating WooCommerce' } );
		assert.deepEqual( site.probe().slice( beforeActivation ).map( ( line ) => line.runs.safety_net_deactivate_gateway_plugins ), [ 0 ], 'The gateway pass already ran while WooCommerce was being activated' );
		const before = site.probe().length;
		await site.get( '/' );
		// The STATE read below loads WordPress too and would run the pass itself, so the GET's own probe line must show it.
		const runs = httpProbeSince( site, before ).map( ( line ) => line.runs.safety_net_deactivate_gateway_plugins );
		assert.ok( runs.includes( 1 ), `GET / did not run the gateway pass (runs per request: ${ JSON.stringify( runs ) })` );
		const s = await site.php( STATE );
		assert.ok( ! s.active.includes( 'zz-checkout/zz-checkout.php' ), 'The gateway plugin is still active' );
		assert.ok( s.active.includes( 'zz-offline-cod/zz-offline-cod.php' ) );
		assert.ok( s.active.includes( 'woocommerce/woocommerce.php' ) );
		assert.equal( s.flags.safety_net_gateway_plugins_deactivated, '1' );
		assert.equal( s.flags.safety_net_gateway_plugins_pending, undefined );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
