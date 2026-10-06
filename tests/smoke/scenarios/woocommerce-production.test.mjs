import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertNoFlags, robotsLines } from '../lib/checks.mjs';
import { seedWooCommerceSite } from '../lib/fixtures.mjs';
import { bootSite } from '../lib/site.mjs';

const STATE = "return array( 'snapshot' => sn_test_snapshot(), 'counts' => sn_test_wc_counts(), 'gateways' => array_keys( WC()->payment_gateways->payment_gateways() ), 'store' => get_class( ActionScheduler::store() ) );";

describe( 'woocommerce-production: a production WooCommerce store is left alone', () => {
	let site;
	let seed;
	let baseline;
	let automateWoo;

	before( async () => {
		site = await bootSite( { name: 'woocommerce-production', env: 'production', mode: 'plugin', woocommerce: 'active' } );
		seed = await seedWooCommerceSite( site, { hpos: true } );
		automateWoo = await site.php( 'return sn_test_seed_automatewoo();', { label: 'seeding AutomateWoo data' } );
		baseline = await site.php( STATE, { label: 'baseline' } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'P1: the store and its admin screens load', async () => {
		await site.get( '/' );
		await site.getAll( await site.php( `return array( wc_get_page_permalink( 'shop' ), wc_get_page_permalink( 'checkout' ), get_permalink( ${ seed.woo.product } ) );` ) );
		const jar = await site.login();
		await site.getAll( [ '/wp-admin/', '/wp-admin/plugins.php', '/wp-admin/admin.php?page=wc-orders', '/wp-admin/admin.php?page=wc-settings&tab=checkout' ].map( ( path ) => ( { path, jar } ) ) );
	} );

	test( 'P1: orders, customers, tokens, webhooks, gateways and plugins are untouched', async () => {
		const now = await site.php( STATE );
		assertNoFlags( now.snapshot.flags );
		assert.deepEqual( now.snapshot.backups, [] );
		assert.deepEqual( now.snapshot.users, baseline.snapshot.users );
		assert.deepEqual( now.snapshot.options.active_plugins, [ ...baseline.snapshot.options.active_plugins, 'safety-net/safety-net.php' ].sort() );
		for ( const key of [ 'wc_orders', 'wc_order_addresses', 'woocommerce_order_items', 'order_notes', 'woocommerce_payment_tokens', 'woocommerce_api_keys', 'wc_customer_lookup', 'subscription_posts', 'renewal_actions', 'webhooks_active' ] ) {
			assert.equal( now.counts[ key ], baseline.counts[ key ], `${ key } changed on production` );
		}
		assert.ok( now.gateways.includes( 'acme_pay' ) );
		assert.notEqual( now.store, 'SafetyNet\\ActionScheduler_Custom_DBStore', 'Renewal actions were paused on production' );
		const automate = await site.php( `return sn_test_automatewoo_state( json_decode( '${ JSON.stringify( automateWoo ) }', true ) );` );
		assert.deepEqual( automate, { workflow: 'publish', queue: 1, meta: 1, action: 'pending', other: 'pending' }, 'AutomateWoo data changed on production' );
	} );

	test( 'P1: robots.txt and the front page keep the defaults', async () => {
		const lines = robotsLines( ( await site.get( '/robots.txt' ) ).text );
		assert.ok( lines.includes( 'Disallow: /wp-admin/' ), lines.join( '\n' ) );
		assert.ok( ! lines.includes( 'Disallow: /' ), lines.join( '\n' ) );
		assert.doesNotMatch( ( await site.get( '/' ) ).text, /<meta name=['"]robots['"][^>]*noindex/ );
	} );

	test( 'debug.log has no fatal errors and no Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
