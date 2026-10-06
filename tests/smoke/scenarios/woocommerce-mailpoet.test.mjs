import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertStepFlags, firstLoad } from '../lib/checks.mjs';
import { bootSite } from '../lib/site.mjs';

// MailPoet loads before Safety Net and WooCommerce after it, and both use the Jetpack Autoloader.
describe( 'woocommerce-mailpoet: regular plugin deactivating MailPoet while WooCommerce is still to load', () => {
	let site;

	before( async () => {
		site = await bootSite( { name: 'woocommerce-mailpoet', env: 'staging', mode: 'plugin', woocommerce: 'active', mailpoet: true } );
		await site.php( 'return sn_test_seed_base();', { label: 'seeding the site' } );
		const active = await site.php( "return get_option( 'active_plugins' );" );
		assert.ok( active.indexOf( 'mailpoet/mailpoet.php' ) >= 0 && active.indexOf( 'woocommerce/woocommerce.php' ) >= 0, `MailPoet and WooCommerce are not both active: ${ JSON.stringify( active ) }` );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'MW1: the first page load deactivates MailPoet without a fatal error in MailPoet, which is still running in that request', async () => {
		await firstLoad( site );
		assertStepFlags( await site.php( 'return sn_test_flags();' ), { woocommerce: true } );
		const active = await site.php( "return get_option( 'active_plugins' );" );
		assert.ok( ! active.includes( 'mailpoet/mailpoet.php' ), 'MailPoet is still active' );
		assert.ok( active.includes( 'woocommerce/woocommerce.php' ), 'WooCommerce was deactivated' );
	} );

	test( 'MW2: the next requests load WooCommerce without MailPoet', async () => {
		await site.get( '/' );
		await site.get( '/?post_type=product' );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
