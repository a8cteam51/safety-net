import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertStepFlags, assertToolsAssets, firstLoad, getToolsPage, runAjaxTools } from '../lib/checks.mjs';
import { seedWooCommerceSite } from '../lib/fixtures.mjs';
import { bootSite } from '../lib/site.mjs';

describe( 'woocommerce-mu-legacy: mu-plugin on a WooCommerce store with posts-based order storage', () => {
	let site;
	let seed;

	before( async () => {
		site = await bootSite( { name: 'woocommerce-mu-legacy', env: 'staging', mode: 'mu', woocommerce: 'active' } );
		seed = await seedWooCommerceSite( site, { hpos: false } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'S1: the first page load runs the automatic pass, including the gateway pass', async () => {
		const { probe } = await firstLoad( site );
		assert.equal( probe.runs.safety_net_deactivate_gateway_plugins, 1 );
	} );

	test( 'A1/A15: flags are set and the gateway plugin is deactivated', async () => {
		const s = await site.php( "return array( 'flags' => sn_test_flags(), 'active' => get_option( 'active_plugins' ) );" );
		assertStepFlags( s.flags, { woocommerce: true } );
		assert.ok( ! s.active.includes( 'zz-checkout/zz-checkout.php' ) );
		assert.ok( s.active.includes( 'zz-offline-cod/zz-offline-cod.php' ) );
	} );

	test( 'W2: legacy order posts, their meta, items and notes are deleted', async () => {
		const counts = await site.php( 'return sn_test_wc_counts();' );
		for ( const table of [ 'shop_order_posts', 'shop_order_postmeta', 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'order_notes', 'woocommerce_payment_tokens', 'wc_webhooks', 'subscription_posts', 'renewal_actions' ] ) {
			assert.equal( counts[ table ], 0, `${ table } still has ${ counts[ table ] } rows (seeded ${ seed.woo.counts[ table ] })` );
		}
		assert.deepEqual( await site.php( 'return sn_test_users();' ), { admin: 1, admin2: seed.base.users.admin2 } );
	} );

	test( 'K7: legacy refunds, sessions, download permissions and download logs from before the run are cleared', async () => {
		const counts = await site.php( 'return sn_test_wc_counts();' );
		assert.deepEqual(
			{ refunds: counts.refund_posts, refund_meta: counts.refund_postmeta, sessions: counts.woocommerce_sessions, downloads: counts.woocommerce_downloadable_product_permissions, download_log: counts.wc_download_log },
			{ refunds: 0, refund_meta: 0, sessions: 0, downloads: 0, download_log: 0 }
		);
	} );

	test( 'S8: the storefront loads', async () => {
		for ( const page of await site.php( "return array( wc_get_page_permalink( 'shop' ), wc_get_page_permalink( 'checkout' ) );" ) ) {
			await site.get( page );
		}
	} );

	test( 'S7/S10: the Tools page and its AJAX tools work from mu-plugins, and Delete removes a legacy order added later', async () => {
		await site.login();
		const { res, nonces } = await getToolsPage( site );
		await assertToolsAssets( site, res.text, '/wp-content/mu-plugins/safety-net/' );
		await runAjaxTools( site, nonces, { order: true } );
		await site.get( '/wp-admin/edit.php?post_type=shop_order', { jar: site.adminJar } );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
