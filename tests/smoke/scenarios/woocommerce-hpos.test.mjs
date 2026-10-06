import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertMailBlocked, assertStepFlags, captureMail, firstLoad, getToolsPage, httpProbeSince, postAjax, robotsLines, runAjaxTools } from '../lib/checks.mjs';
import { HPOS_TABLES, seedWooCommerceSite } from '../lib/fixtures.mjs';
import { bootSite } from '../lib/site.mjs';

const CLEARED = [ ...HPOS_TABLES, 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'order_notes', 'wc_customer_lookup', 'wc_order_stats', 'wc_order_product_lookup', 'woocommerce_api_keys', 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta', 'wc_webhooks', 'subscription_posts', 'subscription_meta', 'renewal_actions', 'orphaned_action_logs' ];

const ADD_WEBHOOK = "$w = new WC_Webhook(); $w->set_name( 'SN late hook' ); $w->set_topic( 'order.created' ); $w->set_delivery_url( 'https://example.com/late' ); $w->set_status( 'active' ); $w->set_user_id( 1 ); return $w->save();";

describe( 'woocommerce-hpos: regular plugin on a WooCommerce store (HPOS)', () => {
	let site;
	let seed;
	let automateWoo;

	before( async () => {
		site = await bootSite( { name: 'woocommerce-hpos', env: 'staging', mode: 'plugin', woocommerce: 'active' } );
		seed = await seedWooCommerceSite( site, { hpos: true } );
		automateWoo = await site.php( 'return sn_test_seed_automatewoo();', { label: 'seeding AutomateWoo data' } );
		assert.deepEqual( await site.php( `return sn_test_automatewoo_state( json_decode( '${ JSON.stringify( automateWoo ) }', true ) );` ), { workflow: 'publish', queue: 1, meta: 1, action: 'pending', other: 'pending' }, 'Seeding AutomateWoo data failed' );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'S1: the first page load runs the automatic pass, including the gateway pass', async () => {
		const { probe } = await firstLoad( site );
		assert.equal( probe.runs.safety_net_deactivate_gateway_plugins, 1, 'The wp_loaded gateway pass did not run on the first load' );
	} );

	test( 'A1: every step flag is set and the gateway pass is done', async () => {
		assertStepFlags( await site.php( 'return sn_test_flags();' ), { woocommerce: true } );
	} );

	test( 'A15/A16: gateway plugins are deactivated, offline and excluded ones are kept', async () => {
		const s = await site.php( "return array( 'active' => get_option( 'active_plugins' ), 'gateways' => array_keys( WC()->payment_gateways->payment_gateways() ) );" );
		assert.ok( ! s.active.includes( 'zz-checkout/zz-checkout.php' ), 'The plugin registering an online gateway is still active' );
		for ( const plugin of [ 'woocommerce/woocommerce.php', 'zz-offline-cod/zz-offline-cod.php', 'zz-keep-gateway/zz-keep-gateway.php', 'barcode-label-printer/barcode-label-printer.php' ] ) {
			assert.ok( s.active.includes( plugin ), `${ plugin } was deactivated` );
		}
		assert.ok( ! s.gateways.includes( 'acme_pay' ) );
		for ( const gateway of [ 'bacs', 'cheque', 'cod', 'zz_offline_cod', 'zz_keep' ] ) {
			assert.ok( s.gateways.includes( gateway ), `${ gateway } is gone` );
		}
	} );

	test( 'W1/W3: orders, customers, tokens, keys, webhooks and subscriptions are deleted', async () => {
		const counts = await site.php( 'return sn_test_wc_counts();' );
		for ( const table of CLEARED ) {
			assert.equal( counts[ table ], 0, `${ table } still has ${ counts[ table ] } rows (seeded ${ seed.woo.counts[ table ] })` );
		}
		assert.equal( counts.keep_actions, 1, 'An unrelated scheduled action was deleted' );
	} );

	test( 'A29: AutomateWoo workflows are disabled, its queue emptied and its pending actions canceled', async () => {
		const s = await site.php( `return sn_test_automatewoo_state( json_decode( '${ JSON.stringify( automateWoo ) }', true ) );` );
		assert.equal( s.workflow, 'aw-disabled' );
		assert.equal( s.queue, 0, 'automatewoo_queue still has rows' );
		assert.equal( s.meta, 0, 'automatewoo_queue_meta still has rows' );
		assert.equal( s.action, 'canceled', 'The AutomateWoo action does not have Action Scheduler\'s canceled status' );
		assert.equal( s.other, 'pending', 'An unrelated pending action was taken out of the queue' );
	} );

	test( 'K7: sessions, download permissions and logs, and order placeholders from before the run are cleared', async () => {
		const counts = await site.php( 'return sn_test_wc_counts();' );
		assert.deepEqual( { sessions: counts.woocommerce_sessions, downloads: counts.woocommerce_downloadable_product_permissions, download_log: counts.wc_download_log, placeholders: counts.placeholder_posts }, { sessions: 0, downloads: 0, download_log: 0, placeholders: 0 } );
	} );

	test( 'A17/W3: only administrators remain and the subscription cache is cleared', async () => {
		const s = await site.php( "return array( 'users' => sn_test_users(), 'cache' => get_user_meta( 1, '_wcs_subscription_ids_cache', true ), 'control' => get_user_meta( 1, 'awcs_subscription_ids_cache', true ) );" );
		assert.deepEqual( s.users, { admin: 1, admin2: seed.base.users.admin2 } );
		assert.equal( s.cache, '' );
		assert.equal( s.control, 'control' );
	} );

	test( 'A21: renewal actions are paused while other actions still run', async () => {
		const s = await site.php( `
add_action( 'sn_keep_hook', '__return_null' );
add_action( 'sn_other_pending_hook', '__return_null' );
$renewal = as_schedule_single_action( time() - 60, 'woocommerce_scheduled_subscription_payment', array( 'subscription_id' => 7 ) );
$keep    = as_schedule_single_action( time() - 60, 'sn_keep_hook', array( 'late' => 1 ) );
$store   = ActionScheduler::store();
$claim   = $store->stake_claim( 50 );
$claimed = $claim->get_actions();
$store->release_claim( $claim );
ActionScheduler_QueueRunner::instance()->run( 'Safety Net smoke test' );
return array(
	'store'          => get_class( $store ),
	'claimed'        => array_map( 'intval', $claimed ),
	'renewal'        => (int) $renewal,
	'keep'           => (int) $keep,
	'renewal_status' => $store->get_status( $renewal ),
	'keep_status'    => $store->get_status( $keep ),
);` );
		assert.equal( s.store, 'SafetyNet\\ActionScheduler_Custom_DBStore' );
		assert.ok( s.claimed.includes( s.keep ), 'The unrelated action was not claimed' );
		assert.ok( ! s.claimed.includes( s.renewal ), 'A renewal action was claimed' );
		assert.equal( s.renewal_status, 'pending' );
		assert.equal( s.keep_status, 'complete' );
	} );

	test( 'A19/A20: emails are blocked and robots.txt disallows everything', async () => {
		assertMailBlocked( await captureMail( site ) );
		const lines = robotsLines( ( await site.get( '/robots.txt' ) ).text );
		assert.ok( lines.includes( 'User-agent: *' ), lines.join( '\n' ) );
		assert.ok( lines.includes( 'Disallow: /' ), lines.join( '\n' ) );
		assert.deepEqual( lines.filter( ( line ) => /^(Allow|Sitemap):/i.test( line ) ), [] );
	} );

	test( 'S8/S16: the storefront, cart, checkout, account and Store API work', async () => {
		const pages = await site.php( `return array( wc_get_page_permalink( 'shop' ), wc_get_page_permalink( 'cart' ), wc_get_page_permalink( 'checkout' ), wc_get_page_permalink( 'myaccount' ), get_permalink( ${ seed.woo.product } ) );` );
		for ( const page of pages ) {
			await site.get( page );
		}
		await site.get( '/wp-json/wc/store/v1/cart' );
		await site.get( `/?add-to-cart=${ seed.woo.product }`, { follow: false, expect: [ 200, 302 ] } );
	} );

	test( 'S9: the REST status route reports every step as done', async () => {
		const status = JSON.parse( ( await site.get( '/wp-json/safety-net/v1/status' ) ).text );
		assert.deepEqual( status, { active: true, environment: 'staging', options_scrubbed: true, plugins_deactivated: true, gateway_plugins_deactivated: true, data_deleted: true, transients_deleted: true, webhooks_disabled: true } );
	} );

	test( 'S12: wp-cron.php runs with the paused store', async () => {
		await site.get( '/wp-cron.php?doing_wp_cron' );
	} );

	test( 'S5/S7/S13: the dashboard, Tools page and WooCommerce screens load', async () => {
		await site.login();
		assert.match( ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text, /WooCommerce Subscriptions scheduled actions are currently paused\./ );
		const { res } = await getToolsPage( site );
		assert.match( res.text, /ZZ Checkout \(fixture\)/ );
		for ( const page of [ '/wp-admin/admin.php?page=wc-orders', '/wp-admin/admin.php?page=wc-settings&tab=checkout', '/wp-admin/admin.php?page=wc-admin', '/wp-admin/edit.php?post_type=product', '/wp-admin/admin.php?page=wc-status' ] ) {
			await site.get( page, { jar: site.adminJar } );
		}
	} );

	test( 'W4: webhooks added later are disabled by the Disable Webhooks and Scrub Options tools', async () => {
		const { nonces } = await getToolsPage( site );
		const status = ( id ) => site.php( `return wc_get_webhook( ${ id } )->get_status();` );
		const first = await site.php( ADD_WEBHOOK );
		assert.equal( await status( first ), 'active' );
		assert.deepEqual( await postAjax( site, 'safety_net_disable_webhooks', nonces[ 'safety-net-disable-webhooks' ] ), { success: true, message: 'Webhooks have been disabled.' } );
		// Checked before the Scrub Options request, which disables webhooks too.
		assert.equal( await status( first ), 'disabled', 'The Disable Webhooks tool left the webhook active' );
		const second = await site.php( ADD_WEBHOOK );
		assert.deepEqual( await postAjax( site, 'safety_net_scrub_options', nonces[ 'safety-net-scrub-options' ] ), { success: true, message: 'Options have been scrubbed.' } );
		assert.equal( await status( second ), 'disabled', 'The Scrub Options tool left the webhook active' );
	} );

	test( 'W7: the Deactivate Plugins tool itself removes a gateway plugin', async () => {
		const { nonces } = await getToolsPage( site );
		// No pending flag, so the wp_loaded gateway pass cannot do the tool's job.
		await site.php( "sn_test_activate_plugins( array( 'zz-checkout/zz-checkout.php' ) ); delete_option( 'safety_net_gateway_plugins_deactivated' ); delete_option( 'safety_net_gateway_plugins_pending' ); return true;" );
		const before = site.probe().length;
		assert.deepEqual( await postAjax( site, 'safety_net_deactivate_plugins', nonces[ 'safety-net-deactivate-plugins' ] ), { success: true, message: 'Plugins have been deactivated.' } );
		assert.deepEqual( httpProbeSince( site, before ).map( ( line ) => line.runs.safety_net_deactivate_gateway_plugins ), [ 0 ], 'The gateway pass ran during the AJAX request, so it did not test the tool' );
		const s = await site.php( "return array( 'active' => get_option( 'active_plugins' ), 'flags' => sn_test_flags() );" );
		assert.ok( ! s.active.includes( 'zz-checkout/zz-checkout.php' ) );
		assert.equal( s.flags.safety_net_gateway_plugins_deactivated, '1' );
		assert.equal( s.flags.safety_net_gateway_plugins_pending, undefined );
	} );

	test( 'S10: every AJAX tool succeeds with WooCommerce loaded and removes a customer and order added after the automatic pass', async () => {
		const { nonces } = await getToolsPage( site );
		await runAjaxTools( site, nonces, { order: true } );
		assert.deepEqual( await site.php( 'return sn_test_users();' ), { admin: 1, admin2: seed.base.users.admin2 } );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
