import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertMailBlocked, assertStepFlags, assertToolsPage, captureMail, firstLoad, getToolsPage, httpProbeSince, postAjax, robotsLines, runAjaxTools, saveToolsForm, toolsPagePath } from '../lib/checks.mjs';
import { HPOS_TABLES, seedWooCommerceSite } from '../lib/fixtures.mjs';
import { bootSite } from '../lib/site.mjs';
import { ICONS, statusRows, statusSummary, withoutIcons, withRenewal } from '../lib/status.mjs';

const CLEARED = [ ...HPOS_TABLES, 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'order_notes', 'wc_customer_lookup', 'wc_order_stats', 'wc_order_product_lookup', 'woocommerce_api_keys', 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta', 'wc_webhooks', 'subscription_posts', 'subscription_meta', 'membership_posts', 'membership_meta', 'woocommerce_log', 'renewal_actions', 'orphaned_action_logs' ];

const GATEWAY_PLUGIN = 'zz-checkout/zz-checkout.php';

const ADD_WEBHOOK = "$w = new WC_Webhook(); $w->set_name( 'SN late hook' ); $w->set_topic( 'order.created' ); $w->set_delivery_url( 'https://example.com/late' ); $w->set_status( 'active' ); $w->set_user_id( 1 ); return $w->save();";

describe( 'woocommerce-hpos: regular plugin on a WooCommerce store (HPOS)', () => {
	let site;
	let seed;
	let automateWoo;
	let xero;
	let firstProbe;

	before( async () => {
		site = await bootSite( { name: 'woocommerce-hpos', env: 'staging', mode: 'plugin', woocommerce: 'active' } );
		seed = await seedWooCommerceSite( site, { hpos: true } );
		automateWoo = await site.php( 'return sn_test_seed_automatewoo();', { label: 'seeding AutomateWoo data' } );
		assert.deepEqual( await site.php( `return sn_test_automatewoo_state( json_decode( '${ JSON.stringify( automateWoo ) }', true ) );` ), { workflow: 'publish', queue: 1, meta: 1, action: 'pending', other: 'pending' }, 'Seeding AutomateWoo data failed' );
		xero = await site.php( 'return sn_test_seed_xero_actions();', { label: 'seeding WooCommerce Xero actions' } );
		assert.deepEqual( await site.php( `return sn_test_action_statuses( json_decode( '${ JSON.stringify( xero ) }', true ) );` ), { invoice: 'pending', payment: 'pending', void: 'pending', control: 'pending' }, 'Seeding WooCommerce Xero actions failed' );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'S1: the first page load runs the automatic pass, including the gateway pass', async () => {
		const { probe } = await firstLoad( site );
		firstProbe = probe;
		assert.equal( probe.runs.safety_net_deactivate_gateway_plugins, 1, 'The wp_loaded gateway pass did not run on the first load' );
	} );

	test( 'A31: the first page load already uses the paused Action Scheduler store', () => {
		assert.ok( firstProbe, 'S1 never loaded the first page' );
		assert.equal( firstProbe.as_store, 'SafetyNet\\ActionScheduler_Custom_DBStore', 'Action Scheduler could claim renewal actions on the load that first ran Safety Net' );
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

	test( 'W1/W3: orders, customers, tokens, keys, webhooks, subscriptions, memberships and logs are deleted', async () => {
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

	test( 'A46: WooCommerce Xero\'s pending invoice, payment and void actions are canceled, and a hook its pattern only matches with "_" as a wildcard is not', async () => {
		const s = await site.php( `return sn_test_action_statuses( json_decode( '${ JSON.stringify( xero ) }', true ) );` );
		assert.deepEqual( s, { invoice: 'canceled', payment: 'canceled', void: 'canceled', control: 'pending' }, 'A WooCommerce Xero action can still send to Xero, or an unrelated pending action was canceled' );
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
		await site.getAll( [ ...pages, '/wp-json/wc/store/v1/cart' ] );
		await site.get( `/?add-to-cart=${ seed.woo.product }`, { follow: false, expect: [ 200, 302 ] } );
	} );

	test( 'S9: the REST status route reports every step as done', async () => {
		const status = JSON.parse( ( await site.get( '/wp-json/safety-net/v1/status' ) ).text );
		assert.deepEqual( status, { active: true, environment: 'staging', options_scrubbed: true, plugins_deactivated: true, gateway_plugins_deactivated: true, data_deleted: true, data_kept: false, data_deletion_disabled: false, keep_until: null, transients_deleted: true, webhooks_disabled: true, emails_enabled: false } );
	} );

	test( 'S12: wp-cron.php runs with the paused store', async () => {
		await site.get( '/wp-cron.php?doing_wp_cron' );
	} );

	test( 'S5/S7/S13: the dashboard, Tools page and WooCommerce screens load', async () => {
		const jar = await site.login();
		const [ dashboard, tools ] = await site.getAll( [ '/wp-admin/', toolsPagePath(), '/wp-admin/admin.php?page=wc-orders', '/wp-admin/admin.php?page=wc-settings&tab=checkout', '/wp-admin/admin.php?page=wc-admin', '/wp-admin/edit.php?post_type=product', '/wp-admin/admin.php?page=wc-status' ].map( ( path ) => ( { path, jar } ) ) );
		assert.match( dashboard.text, /WooCommerce Subscriptions scheduled actions are currently paused\./ );
		const { res } = assertToolsPage( tools );
		assert.match( res.text, /ZZ Checkout \(fixture\)/ );
	} );

	test( 'ST4: the status panel reports the gateway pass, the renewal pause once there is something to renew, webhooks and REST API keys, and warns about a webhook active again, a REST API key created after the run and a gateway plugin active again', async () => {
		await withRenewal( site, async () => {
			const { res } = await getToolsPage( site );
			const rows = statusRows( res.text );
			assert.deepEqual( statusSummary( rows ), [
				[ 'environment', 'ok', 'staging' ],
				[ 'emails', 'ok', 'Blocked, except password resets' ],
				[ 'email_log', 'ok', 'On' ],
				[ 'data', 'ok', 'Deleted' ],
				[ 'options', 'ok', 'Scrubbed' ],
				[ 'plugins', 'ok', 'Deactivated' ],
				[ 'gateways', 'ok', 'Deactivated' ],
				[ 'renewals', 'ok', 'Paused' ],
				[ 'webhooks', 'ok', 'Disabled' ],
				[ 'api_keys', 'ok', 'None' ],
				[ 'search_engines', 'ok', 'Discouraged' ],
				[ 'transients', 'ok', 'Deleted' ],
			], 'The panel does not show every protection, WooCommerce\'s included, as done on a store Safety Net ran on' );
			for ( const row of rows ) {
				assert.deepEqual( [ row.icon, row.severity ], ICONS[ row.state ], `The ${ row.id } row's icon or screen reader text does not match its state` );
			}
			assert.equal( rows.find( ( row ) => row.id === 'api_keys' ).details, 'There are no WooCommerce REST API keys.', 'The key row claims more than that the table is empty' );
			const renewals = rows.find( ( row ) => row.id === 'renewals' );
			assert.deepEqual( renewals.link, { url: new URL( `${ toolsPagePath() }#safety_net_pause_renewal_actions_toggle`, site.siteUrl ).href, text: 'Pause renewal actions' }, 'The renewal row does not link to the pause switch' );
			assert.match( res.text, /<input id="safety_net_pause_renewal_actions_toggle"/, 'The Tools page has no pause switch for the renewal row to link to' );
			assert.deepEqual( withoutIcons( rows ), await site.php( 'return SafetyNet\\Status\\get_status_rows();' ), 'The panel does not show what get_status_rows() returns' );

			const added = await site.php( `
global $wpdb;
$webhook = ( function () { ${ ADD_WEBHOOK } } )();
$wpdb->insert( $wpdb->prefix . 'woocommerce_api_keys', array( 'user_id' => 1, 'description' => 'SN 3PL', 'permissions' => 'read_write', 'consumer_key' => wc_api_hash( 'ck_sn_3pl' ), 'consumer_secret' => 'cs_sn_3pl', 'truncated_key' => 'sn3pl01' ) );
$key = $wpdb->insert_id;
sn_test_activate_plugins( array( '${ GATEWAY_PLUGIN }' ) );
return array( 'webhook' => $webhook, 'status' => wc_get_webhook( $webhook )->get_status(), 'key' => $key, 'flags' => array_intersect_key( sn_test_flags(), array_flip( array( 'safety_net_gateway_plugins_deactivated', 'safety_net_gateway_plugins_pending' ) ) ) );`, { label: 'adding an active webhook and a REST API key, and reactivating a gateway plugin' } );
			try {
				assert.deepEqual( [ added.status, added.key > 0, added.flags ], [ 'active', true, { safety_net_gateway_plugins_deactivated: '1' } ], 'The webhook is not active, the key was not added, or the gateway pass would run again, so this proves nothing' );
				const changed = statusRows( ( await getToolsPage( site ) ).res.text );
				const byId = Object.fromEntries( changed.map( ( row ) => [ row.id, row ] ) );
				assert.deepEqual( statusSummary( [ byId.plugins, byId.gateways, byId.webhooks, byId.api_keys ] ), [
					[ 'plugins', 'danger', 'Active again' ],
					[ 'gateways', 'danger', 'Active again' ],
					[ 'webhooks', 'warning', '1 active again' ],
					[ 'api_keys', 'warning', '1 key created after Safety Net ran' ],
				] );
				assert.ok( byId.gateways.details.endsWith( 'so the store can take real payments: ZZ Checkout (fixture).' ), `The gateway row does not name the plugin: ${ byId.gateways.details }` );
				assert.equal( byId.api_keys.details, 'Apps and 3PLs using them can read and change orders.' );
				assert.deepEqual( [ byId.webhooks.link, byId.api_keys.link ], [ 'webhooks', 'keys' ].map( ( section ) => ( { url: new URL( `/wp-admin/admin.php?page=wc-settings&tab=advanced&section=${ section }`, site.siteUrl ).href, text: section === 'keys' ? 'REST API keys' : 'Webhooks' } ) ), 'The webhook and key rows do not link to where WooCommerce changes them' );
				assert.deepEqual( withoutIcons( changed ), await site.php( 'return SafetyNet\\Status\\get_status_rows();' ), 'The panel does not show what get_status_rows() returns' );
			} finally {
				await site.php( `global $wpdb; wc_get_webhook( ${ added.webhook } )->delete( true ); $wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => ${ added.key } ) ); update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins' ), array( '${ GATEWAY_PLUGIN }' ) ) ) ); return true;`, { label: 'removing the webhook and the key, and deactivating the gateway plugin' } );
			}
		} );
	} );

	test( 'ST9: before the steps have run on a store, the panel says gateway plugins, webhooks and REST API keys copied from the live site are not done yet and links to their tools, and the renewal row says when the integration that pauses renewals is not loaded', async () => {
		const s = await site.php( `
global $wpdb;
foreach ( array( 'safety_net_plugins_deactivated', 'safety_net_gateway_plugins_pending', 'safety_net_gateway_plugins_deactivated', 'safety_net_webhooks_disabled', 'safety_net_data_deleted', 'safety_net_data_kept' ) as $flag ) {
	add_filter( "pre_option_{$flag}", '__return_zero' );
}
$wpdb->insert( $wpdb->prefix . 'woocommerce_api_keys', array( 'user_id' => 1, 'description' => 'SN live key', 'permissions' => 'read_write', 'consumer_key' => wc_api_hash( 'ck_sn_live' ), 'consumer_secret' => 'cs_sn_live', 'truncated_key' => 'snlive1' ) );
$key     = $wpdb->insert_id;
$renewal = sn_test_schedule_renewal();
remove_filter( 'action_scheduler_store_class', 'SafetyNet\\Integrations\\WooCommerceSubscriptions\\paused_store_class', 101 );
try {
	return array( 'key' => $key, 'renewal' => $renewal, 'toggle' => get_option( 'safety_net_pause_renewal_actions_toggle' ), 'rows' => SafetyNet\\Status\\get_status_rows() );
} finally {
	$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key ) );
	sn_test_delete_action( $renewal );
}`, { label: 'building the status rows as before the steps ran, with a REST API key and without the renewal pause' } );
		assert.deepEqual( [ s.key > 0, s.renewal > 0, s.toggle ], [ true, true, 'on' ], 'The key or the renewal action was not added, or the pause is off, so this proves nothing' );
		const byId = Object.fromEntries( s.rows.map( ( row ) => [ row.id, row ] ) );
		const tools = new URL( toolsPagePath(), site.siteUrl ).href;
		assert.deepEqual( statusSummary( [ byId.gateways, byId.renewals, byId.webhooks, byId.api_keys ] ), [
			[ 'gateways', 'warning', 'Not deactivated yet' ],
			[ 'renewals', 'warning', 'Not paused' ],
			[ 'webhooks', 'warning', 'Not disabled yet' ],
			[ 'api_keys', 'warning', '1 key, not deleted yet' ],
		] );
		assert.ok( byId.gateways.details.startsWith( 'Plugins that register a WooCommerce payment gateway stay active until Deactivate Plugins runs, automatically or below.' ), `The gateway row does not say the automatic run deactivates them: ${ byId.gateways.details }` );
		assert.deepEqual( [ byId.renewals.details, byId.renewals.link ], [ 'Pause renewal actions is on, but the WooCommerce Subscriptions integration that pauses them is not loaded.', null ] );
		assert.equal( byId.api_keys.details, 'Keys copied from the live site let apps and 3PLs read and change orders until they are deleted with the users and orders.' );
		assert.deepEqual( [ byId.gateways.link, byId.webhooks.link, byId.api_keys.link ], [
			{ url: `${ tools }#safety-net-deactivate-plugins`, text: 'Deactivate Plugins' },
			{ url: `${ tools }#safety-net-disable-webhooks`, text: 'Disable Webhooks' },
			{ url: new URL( '/wp-admin/admin.php?page=wc-settings&tab=advanced&section=keys', site.siteUrl ).href, text: 'REST API keys' },
		], 'The rows not done yet do not link to their tools' );
	} );

	test( 'ST12: where WordPress offers application passwords, the REST API keys row counts the administrators who have one, warning with a link to Users even with no keys', async () => {
		const keysRow = async () => {
			const rows = await site.php( 'return SafetyNet\\Status\\get_status_rows();', { label: 'building the status rows' } );
			assert.equal( rows.some( ( row ) => row.id === 'app_passwords' ), false, 'A store has an application passwords row of its own besides the REST API keys row' );
			return rows.find( ( row ) => row.id === 'api_keys' );
		};
		const users = new URL( '/wp-admin/users.php', site.siteUrl ).href;
		const note = '1 administrator has application passwords that still let apps use the REST API, including WooCommerce\'s.';
		await site.php( "update_option( 'sn_test_application_passwords', 1 ); return true;", { label: 'offering application passwords over HTTP' } );
		try {
			assert.deepEqual( await keysRow(), { id: 'api_keys', label: 'WooCommerce REST API keys', state: 'ok', status: 'None', details: 'There are no WooCommerce REST API keys. No administrator has an application password.', link: null } );
			const created = await site.php( "$created = WP_Application_Passwords::create_new_application_password( 1, array( 'name' => 'SN 3PL app' ) ); if ( is_wp_error( $created ) ) { throw new RuntimeException( $created->get_error_message() ); } return array( 'admin' => user_can( 1, 'manage_options' ), 'passwords' => count( WP_Application_Passwords::get_user_application_passwords( 1 ) ) );", { label: 'creating an application password for the administrator' } );
			assert.deepEqual( created, { admin: true, passwords: 1 }, 'User 1 is not an administrator with one application password, so this proves nothing' );
			const rows = statusRows( ( await getToolsPage( site ) ).res.text );
			const row = rows.find( ( candidate ) => candidate.id === 'api_keys' );
			assert.deepEqual( [ row.state, row.status, row.details, row.link, row.icon ], [ 'warning', 'No keys; 1 administrator has application passwords', `There are no WooCommerce REST API keys. ${ note }`, { url: users, text: 'Users' }, ICONS.warning[ 0 ] ], 'The REST API keys row does not warn about the administrator\'s application password' );
			assert.deepEqual( withoutIcons( rows ), await site.php( 'return SafetyNet\\Status\\get_status_rows();' ), 'The panel does not show what get_status_rows() returns' );

			const key = await site.php( "global $wpdb; $wpdb->insert( $wpdb->prefix . 'woocommerce_api_keys', array( 'user_id' => 1, 'description' => 'SN 3PL', 'permissions' => 'read_write', 'consumer_key' => wc_api_hash( 'ck_sn_app' ), 'consumer_secret' => 'cs_sn_app', 'truncated_key' => 'snapp01' ) ); return $wpdb->insert_id;", { label: 'adding a REST API key' } );
			try {
				assert.ok( key > 0, 'The key was not added' );
				const both = await keysRow();
				assert.deepEqual( [ both.state, both.status, both.details, both.link?.text ], [ 'warning', '1 key created after Safety Net ran', `Apps and 3PLs using them can read and change orders. ${ note }`, 'REST API keys' ] );
			} finally {
				await site.php( `global $wpdb; $wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => ${ key } ) ); return true;`, { label: 'deleting the REST API key' } );
			}
		} finally {
			await site.php( "WP_Application_Passwords::delete_all_application_passwords( 1 ); delete_option( 'sn_test_application_passwords' ); return true;", { label: 'deleting the application password' } );
		}
		assert.deepEqual( statusSummary( [ await keysRow() ] ), [ [ 'api_keys', 'ok', 'None' ] ] );
	} );

	test( 'W4: webhooks added later are disabled by the Disable Webhooks and Scrub Options tools', async () => {
		const { nonces } = await getToolsPage( site );
		const status = ( id ) => site.php( `return wc_get_webhook( ${ id } )->get_status();` );
		const first = await site.php( ADD_WEBHOOK );
		assert.equal( await status( first ), 'active' );
		assert.deepEqual( await postAjax( site, 'safety_net_disable_webhooks', nonces[ 'safety-net-disable-webhooks' ] ), { success: true, message: 'Webhooks have been disabled.' } );
		// Checked before the Scrub Options request, which disables webhooks too.
		assert.equal( await status( first ), 'disabled', 'The Disable Webhooks tool left the webhook active' );
		// One more than WooCommerce's default webhook search limit of 10.
		const later = await site.php( `$ids = array(); for ( $i = 0; $i < 11; $i++ ) { $ids[] = ( function () { ${ ADD_WEBHOOK } } )(); } return $ids;` );
		assert.deepEqual( await postAjax( site, 'safety_net_scrub_options', nonces[ 'safety-net-scrub-options' ] ), { success: true, message: 'Options have been scrubbed.' } );
		const statuses = await site.php( `return array_map( fn( $id ) => wc_get_webhook( $id )->get_status(), json_decode( '${ JSON.stringify( later ) }' ) );` );
		assert.deepEqual( statuses, later.map( () => 'disabled' ), 'The Scrub Options tool left some of 11 webhooks active' );
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

	test( 'A32: once the Tools form turns the pause off, the next load uses Action Scheduler\'s own store', async () => {
		await saveToolsForm( site );
		const before = site.probe().length;
		await site.get( '/' );
		const [ load ] = httpProbeSince( site, before );
		assert.ok( load?.as_store, 'The load built no Action Scheduler store' );
		assert.notEqual( load.as_store, 'SafetyNet\\ActionScheduler_Custom_DBStore', 'Renewal actions are still paused after the Tools form turned the pause off' );
	} );

	test( 'ST5: on a store without WooCommerce Subscriptions, the status panel has no renewal row until a renewal action is pending; once the Tools form turns the pause off, it says renewal actions are not paused and links to the switch', async () => {
		assert.notEqual( await site.php( "return sn_test_raw_option( 'safety_net_pause_renewal_actions_toggle' );" ), 'on', 'A32 did not turn the pause off, so this proves nothing' );
		// S10's Delete removed the renewal action A21 left pending.
		const before = await site.php( "global $wpdb; return array( 'subscriptions' => function_exists( 'wcs_get_subscriptions' ), 'pending' => sn_test_count( $wpdb->prefix . 'actionscheduler_actions', \"status = 'pending' AND hook IN ( 'woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry', 'woocommerce_scheduled_subscription_end_of_prepaid_term' )\" ), 'rows' => array_column( SafetyNet\\Status\\get_status_rows(), 'id' ) );", { label: 'building the status rows without anything to renew' } );
		assert.deepEqual( [ before.subscriptions, before.pending ], [ false, 0 ], 'WooCommerce Subscriptions is loaded or a renewal action is pending, so this does not test a store with nothing to renew' );
		assert.ok( before.rows.includes( 'webhooks' ) && ! before.rows.includes( 'renewals' ), 'The panel shows the renewal row on a store with no subscriptions and no pending renewal actions' );
		await withRenewal( site, async () => {
			const renewals = statusRows( ( await getToolsPage( site ) ).res.text ).find( ( row ) => row.id === 'renewals' );
			assert.deepEqual( [ renewals?.state, renewals?.status, renewals?.link?.text ], [ 'warning', 'Not paused', 'Pause renewal actions' ], 'The panel does not warn that renewal actions are not paused' );
			assert.match( renewals.details, /^Pause renewal actions is off, so renewal payments/ );
		} );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
