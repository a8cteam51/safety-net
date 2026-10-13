import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { STEP_FLAGS } from '../lib/checks.mjs';
import { bootSite } from '../lib/site.mjs';

describe( 'woocommerce-preprocessed: a site Safety Net processed before the gateway pass existed', () => {
	let site;
	let seed;

	before( async () => {
		site = await bootSite( { name: 'woocommerce-preprocessed', env: 'staging', mode: 'plugin', woocommerce: 'active' } );
		seed = await site.php( 'return sn_test_seed_base();', { label: 'seeding the site' } );
		await site.php( `foreach ( array( '${ STEP_FLAGS.join( "', '" ) }' ) as $step ) { update_option( 'safety_net_' . $step, true ); } return true;`, { label: 'marking every step as done' } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'W6: gateway plugins are not deactivated retroactively', async () => {
		await site.get( '/' );
		await site.get( '/' );
		const s = await site.php( "return array( 'flags' => sn_test_flags(), 'active' => get_option( 'active_plugins' ), 'users' => sn_test_users() );" );
		assert.ok( s.active.includes( 'zz-checkout/zz-checkout.php' ) );
		assert.equal( s.flags.safety_net_gateway_plugins_deactivated, undefined );
		assert.equal( s.flags.safety_net_gateway_plugins_pending, undefined );
		assert.equal( s.users.customer1, seed.users.customer1, 'The data step ran again although its flag was set' );
	} );

	test( 'ST10: the status panel says no automatic run will deactivate the gateway plugins, and does not count them as plugins Safety Net deactivated', async () => {
		const rows = await site.php( "return array( 'active' => in_array( 'zz-checkout/zz-checkout.php', (array) get_option( 'active_plugins' ), true ), 'rows' => SafetyNet\\Status\\get_status_rows() );", { label: 'building the status rows' } );
		assert.equal( rows.active, true, 'ZZ Checkout is not active, so this proves nothing' );
		const byId = Object.fromEntries( rows.rows.map( ( row ) => [ row.id, row ] ) );
		assert.deepEqual( [ byId.gateways.state, byId.gateways.status, byId.gateways.link?.text ], [ 'warning', 'Not deactivated yet', 'Deactivate Plugins' ] );
		assert.ok( byId.gateways.details.startsWith( 'Safety Net ran before it handled payment gateway plugins, so plugins that register a WooCommerce payment gateway stay active until Deactivate Plugins runs below.' ), `The gateway row promises an automatic run: ${ byId.gateways.details }` );
		assert.ok( byId.gateways.details.includes( 'ZZ Checkout (fixture)' ), `The gateway row does not name ZZ Checkout: ${ byId.gateways.details }` );
		assert.ok( ! byId.plugins.details.includes( 'ZZ Checkout' ), `The plugins row says Safety Net deactivated ZZ Checkout, which it never did: ${ byId.plugins.details }` );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
