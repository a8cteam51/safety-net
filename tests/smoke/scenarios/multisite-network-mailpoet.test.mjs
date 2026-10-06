import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { firstLoad } from '../lib/checks.mjs';
import { bootSite } from '../lib/site.mjs';

const NETWORK_PLUGINS = "return array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );";

// Network-activated plugins load in alphabetical order before muplugins_loaded: MailPoet, then Safety Net, then WooCommerce.
describe( 'multisite-network-mailpoet: Safety Net deactivating network-activated MailPoet, with WooCommerce network-activated after it', () => {
	let site;

	before( async () => {
		site = await bootSite( { name: 'multisite-network-mailpoet', env: 'staging', mode: 'network', multisite: true, woocommerce: 'network', mailpoet: 'network' } );
		await site.php( 'return sn_test_seed_network();', { label: 'seeding the network' } );
		const network = await site.php( NETWORK_PLUGINS );
		assert.deepEqual( [ 'mailpoet/mailpoet.php', 'woocommerce/woocommerce.php' ].filter( ( plugin ) => ! network.includes( plugin ) ), [], `MailPoet and WooCommerce are not both network-active: ${ JSON.stringify( network ) }` );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'MW4: the first page load deactivates MailPoet network-wide without a fatal error in MailPoet, which is still running in that request', async () => {
		await firstLoad( site, '/' );
		const network = await site.php( NETWORK_PLUGINS );
		assert.ok( ! network.includes( 'mailpoet/mailpoet.php' ), 'MailPoet is still network-active' );
		assert.deepEqual( [ 'safety-net/safety-net.php', 'woocommerce/woocommerce.php' ].filter( ( plugin ) => ! network.includes( plugin ) ), [], 'Safety Net or WooCommerce was deactivated' );
	} );

	test( 'MW5: the next requests load WooCommerce without MailPoet', async () => {
		await site.get( '/' );
		await site.get( '/shop/' );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
