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

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
