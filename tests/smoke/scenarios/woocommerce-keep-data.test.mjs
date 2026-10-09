import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertStepFlags, assertToolsPage, captureMail, firstLoad, getToolsPage, httpProbeSince, postAjax, toolsPagePath } from '../lib/checks.mjs';
import { HPOS_TABLES, seedWooCommerceSite } from '../lib/fixtures.mjs';
import { bootSite } from '../lib/site.mjs';

const KEPT = [ ...HPOS_TABLES, 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'order_notes', 'wc_customer_lookup', 'wc_order_stats', 'wc_order_product_lookup', 'subscription_posts', 'subscription_meta', 'membership_posts', 'membership_meta', 'renewal_actions' ];

const CLEARED = [ ...HPOS_TABLES, 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'order_notes', 'wc_customer_lookup', 'wc_order_stats', 'wc_order_product_lookup', 'woocommerce_api_keys', 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta', 'wc_webhooks', 'subscription_posts', 'subscription_meta', 'membership_posts', 'membership_meta', 'woocommerce_log', 'renewal_actions', 'orphaned_action_logs' ];

const STATUS = { active: true, environment: 'staging', options_scrubbed: true, plugins_deactivated: true, gateway_plugins_deactivated: true, transients_deleted: true, webhooks_disabled: true };

const pick = ( counts, tables ) => Object.fromEntries( tables.map( ( table ) => [ table, counts[ table ] ] ) );

describe( 'woocommerce-keep-data: SAFETY_NET_DELETE_DATA false on a WooCommerce store (HPOS)', () => {
	let site;
	let seed;
	let baseline;

	// Each request defines the constants anew from this option, so a change applies from the next request on.
	const setConstants = ( constants ) => site.php( `update_option( 'sn_test_constants', json_decode( '${ JSON.stringify( constants ) }', true ) ); return true;`, { label: `setting the constants ${ JSON.stringify( constants ) }` } );

	before( async () => {
		site = await bootSite( { name: 'woocommerce-keep-data', env: 'staging', mode: 'plugin', woocommerce: 'active' } );
		seed = await seedWooCommerceSite( site, { hpos: true } );
		baseline = await site.php( "update_option( 'sn_test_old_secret_sn_backup', 'live' ); update_option( 'sn_test_keep_integration', 1 ); return array( 'counts' => sn_test_wc_counts(), 'users' => sn_test_users(), 'cache' => get_user_meta( 1, '_wcs_subscription_ids_cache', true ), 'backup' => sn_test_raw_option( 'sn_test_old_secret_sn_backup' ) );", { label: 'seeding an option backup and reading the baseline' } );
		assert.equal( baseline.backup, 'live', 'Seeding an option backup failed, so its removal could not be tested' );
		assert.deepEqual( baseline.cache, [ 101 ], 'Seeding the subscription cache failed, so keeping it could not be tested' );
		await setConstants( { SAFETY_NET_DELETE_DATA: false } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'KD1: the first page load runs the automatic pass with the keep step instead of the delete step', async () => {
		const mark = site.logMark();
		const { probe } = await firstLoad( site, '/', { kept: true } );
		assert.equal( probe.runs.safety_net_deactivate_gateway_plugins, 1, 'The wp_loaded gateway pass did not run on the first load' );
		assert.equal( probe.as_store, 'SafetyNet\\ActionScheduler_Custom_DBStore', 'Action Scheduler could claim renewal actions on the load that first ran Safety Net' );
		const log = site.logEntriesSince( mark );
		assert.ok( log.some( ( entry ) => entry.includes( 'Safety Net: users, orders and subscriptions are kept on site 1 because SAFETY_NET_DELETE_DATA is false (no expiry date).' ) ), 'The keep step did not log that it kept the data' );
		assert.equal( log.filter( ( entry ) => entry.includes( 'SN_TEST integration phase keep' ) ).length, 1, 'The keep step did not run the integrations\' keep phase once' );
	} );

	test( 'KD2: every step flag but data_deleted is set, and data_kept is', async () => {
		assertStepFlags( await site.php( 'return sn_test_flags();' ), { woocommerce: true, kept: true } );
	} );

	test( 'KD3: users, orders, refunds, notes, customer lookup, subscriptions and memberships are kept', async () => {
		const s = await site.php( "return array( 'counts' => sn_test_wc_counts(), 'users' => sn_test_users(), 'cache' => get_user_meta( 1, '_wcs_subscription_ids_cache', true ) );" );
		assert.deepEqual( pick( s.counts, KEPT ), pick( baseline.counts, KEPT ), 'The automatic run deleted orders, subscriptions or memberships although SAFETY_NET_DELETE_DATA is false' );
		assert.deepEqual( s.users, baseline.users, 'The automatic run deleted users although SAFETY_NET_DELETE_DATA is false' );
		assert.deepEqual( s.cache, baseline.cache, 'The automatic run deleted the subscription cache although SAFETY_NET_DELETE_DATA is false' );
	} );

	test( 'KD4: options are scrubbed and no backup of them is kept', async () => {
		const s = await site.php( "return array( 'snapshot' => sn_test_snapshot(), 'old' => sn_test_raw_option( 'sn_test_old_secret_sn_backup' ) );" );
		assert.equal( s.snapshot.options.klaviyo_api_key, '', 'The scrub did not run' );
		assert.deepEqual( s.snapshot.backups, [], 'Option backups are kept next to the kept customer data' );
		assert.equal( s.old, null, 'A backup from before the automatic run is kept next to the kept customer data' );
	} );

	test( 'KD5: gateway plugins are deactivated, webhooks disabled and email blocked, and password resets reach only administrators', async () => {
		const s = await site.php( "return array( 'active' => get_option( 'active_plugins' ), 'webhooks_active' => sn_test_wc_counts()['webhooks_active'] );" );
		assert.ok( ! s.active.includes( 'zz-checkout/zz-checkout.php' ), 'The plugin registering an online gateway is still active' );
		assert.equal( s.webhooks_active, 0, 'A WooCommerce webhook is still active' );
		const mail = await captureMail( site );
		assert.equal( mail.stop_emails_hook, 10, 'stop_emails is not hooked to pre_wp_mail' );
		assert.equal( mail.blocked, false, 'wp_mail() reported success for a regular email' );
		assert.equal( mail.captured[ 'Order receipt' ], false, 'Safety Net did not short-circuit a regular email' );
		assert.equal( mail.captured[ mail.reset_subject ], false, 'A password reset reached someone who is not an administrator while customer data is kept' );
		assert.equal( mail.password_change, false );
		assert.equal( mail.email_change, false );
		const resets = await site.php( `
$admin = get_userdata( 1 )->user_email;
$reset = static fn( $to, $headers = '' ) => apply_filters( 'pre_wp_mail', null, array( 'to' => $to, 'subject' => '[Site] Password Reset', 'message' => 'x', 'headers' => $headers, 'attachments' => array() ) );
return array(
	'customer' => $reset( 'customer1@example.com' ),
	'admin'    => $reset( $admin ),
	'admins'   => $reset( 'Site Admin <' . strtoupper( $admin ) . '>, admin2@example.com' ),
	'mixed'    => $reset( array( $admin, 'customer1@example.com' ) ),
	'nobody'   => $reset( '' ),
	'cc'       => $reset( $admin, "Cc: Customer <customer1@example.com>\r\n" ),
	'bcc'      => $reset( $admin, array( 'Bcc: admin2@example.com' ) ),
	'cc_split' => $reset( $admin, array( "Cc:\ncustomer1@example.com" ) ),
	'bcc_nul'  => $reset( $admin, array( "Bcc\\0: customer1@example.com" ) ),
);` );
		assert.deepEqual( resets, { customer: false, admin: null, admins: null, mixed: false, nobody: false, cc: false, bcc: null, cc_split: false, bcc_nul: false }, 'A password reset must go through (null) only when every recipient is an administrator, and be blocked (false) otherwise' );
	} );

	test( 'KD6: the REST status route reports that data is kept', async () => {
		const status = JSON.parse( ( await site.get( '/wp-json/safety-net/v1/status' ) ).text );
		assert.deepEqual( status, { ...STATUS, data_deleted: false, data_kept: true, data_deletion_disabled: true, keep_until: null } );
	} );

	test( 'KD7: wp-admin says data is kept, the Tools page says Delete deletes anyway, and later loads do not run the keep step again', async () => {
		const jar = await site.login();
		const [ dashboard, tools ] = await site.getAll( [ '/wp-admin/', toolsPagePath() ].map( ( path ) => ( { path, jar } ) ) );
		assert.match( dashboard.text, /safety-net-keep-data/, 'The dashboard has no notice about kept data' );
		assert.match( dashboard.text, /NOT deleted on this site, because SAFETY_NET_DELETE_DATA is false \(with no expiry date\)/ );
		const { res } = assertToolsPage( tools );
		assert.match( res.text, /this button deletes everything anyway/ );
		const before = site.probe().length;
		const mark = site.logMark();
		await site.get( '/' );
		assert.deepEqual( httpProbeSince( site, before ).map( ( line ) => [ line.runs.safety_net_keep_data, line.runs.safety_net_delete_data ] ), [ [ 0, 0 ] ], 'A later page load ran the keep or delete step again' );
		assert.ok( ! site.logEntriesSince( mark ).some( ( entry ) => entry.includes( 'SN_TEST integration phase keep' ) ), 'A later page load ran the integrations\' keep phase again' );
	} );

	test( 'KD11: scrubbing from the Tools page while data is kept makes no backup of the live value it removes', async () => {
		assert.equal( await site.php( "update_option( 'klaviyo_api_key', 'pk_live_again' ); return sn_test_raw_option( 'klaviyo_api_key' );", { label: 'putting a live API key back' } ), 'pk_live_again', 'Putting a live API key back failed, so this proves nothing' );
		const { nonces } = await getToolsPage( site );
		assert.deepEqual( await postAjax( site, 'safety_net_scrub_options', nonces[ 'safety-net-scrub-options' ] ), { success: true, message: 'Options have been scrubbed.' } );
		const s = await site.php( "return array( 'key' => sn_test_raw_option( 'klaviyo_api_key' ), 'backup' => sn_test_raw_option( 'klaviyo_api_key_sn_backup' ), 'backups' => sn_test_snapshot()['backups'] );" );
		assert.deepEqual( s, { key: '', backup: null, backups: [] }, 'The Tools scrub did not blank the key, or kept a backup of it next to the kept customer data' );
	} );

	test( 'KD8: once SAFETY_NET_KEEP_UNTIL has passed, the next page load deletes the kept data', async () => {
		await setConstants( { SAFETY_NET_DELETE_DATA: false, SAFETY_NET_KEEP_UNTIL: '2000-01-01' } );
		const before = site.probe().length;
		await site.get( '/' );
		assert.deepEqual( httpProbeSince( site, before ).map( ( line ) => [ line.runs.safety_net_delete_data, line.runs.safety_net_keep_data ] ), [ [ 1, 0 ] ], 'The first page load after SAFETY_NET_KEEP_UNTIL passed did not run the delete step' );
		const s = await site.php( "return array( 'counts' => sn_test_wc_counts(), 'users' => sn_test_users(), 'flags' => sn_test_flags() );" );
		for ( const table of CLEARED ) {
			assert.equal( s.counts[ table ], 0, `${ table } still has ${ s.counts[ table ] } rows (seeded ${ seed.woo.counts[ table ] })` );
		}
		assert.deepEqual( s.users, { admin: 1, admin2: seed.base.users.admin2 } );
		assert.equal( s.flags.safety_net_data_deleted, '1' );
		assert.equal( s.flags.safety_net_data_kept, '1', 'Deleting the kept data cleared the flag that says it was kept' );
		const [ rest, dashboard ] = await site.getAll( [ '/wp-json/safety-net/v1/status', { path: '/wp-admin/', jar: site.adminJar } ] );
		assert.deepEqual( JSON.parse( rest.text ), { ...STATUS, data_deleted: true, data_kept: true, data_deletion_disabled: false, keep_until: '2000-01-01' } );
		assert.match( dashboard.text, /kept have been deleted\. SAFETY_NET_KEEP_UNTIL \(2000-01-01\) has passed\./ );
	} );

	test( 'KD9: invalid values are ignored and named in wp-admin, and valid ones keep data', async () => {
		const future = new Date( Date.now() + 30 * 24 * 60 * 60 * 1000 ).toISOString().slice( 0, 10 );
		const ignored = ( error ) => ( { disabled: false, until: null, expired: false, errors: [ error ] } );
		const cases = [
			// esc_html() turns the quotes var_export() adds into &#039;.
			[ { SAFETY_NET_DELETE_DATA: 'false' }, ignored( "SAFETY_NET_DELETE_DATA is 'false' instead of the boolean false, so it is ignored and data is deleted." ), /SAFETY_NET_DELETE_DATA is (?:'|&#0?39;)false(?:'|&#0?39;) instead of the boolean false/ ],
			[ { SAFETY_NET_DELETE_DATA: 0 }, ignored( 'SAFETY_NET_DELETE_DATA is 0 instead of the boolean false, so it is ignored and data is deleted.' ) ],
			[ { SAFETY_NET_DELETE_DATA: '' }, ignored( "SAFETY_NET_DELETE_DATA is '' instead of the boolean false, so it is ignored and data is deleted." ) ],
			[ { SAFETY_NET_DELETE_DATA: true }, ignored( 'SAFETY_NET_DELETE_DATA is true instead of the boolean false, so it is ignored and data is deleted.' ) ],
			[ { SAFETY_NET_DELETE_DATA: [ 1 ] }, ignored( 'SAFETY_NET_DELETE_DATA is an array instead of the boolean false, so it is ignored and data is deleted.' ) ],
			// A cut inside a UTF-8 character would make esc_html() print an empty line.
			[ { SAFETY_NET_DELETE_DATA: 'é'.repeat( 45 ) }, ignored( `SAFETY_NET_DELETE_DATA is '${ 'é'.repeat( 39 ) }… instead of the boolean false, so it is ignored and data is deleted.` ), new RegExp( `SAFETY_NET_DELETE_DATA is (?:'|&#0?39;)${ 'é'.repeat( 39 ) }… instead of the boolean false` ) ],
			[ { SAFETY_NET_DELETE_DATA: false, SAFETY_NET_KEEP_UNTIL: '2026-13-01' }, ignored( "SAFETY_NET_KEEP_UNTIL ('2026-13-01') is not a YYYY-MM-DD date, so SAFETY_NET_DELETE_DATA is ignored and data is deleted." ) ],
			[ { SAFETY_NET_DELETE_DATA: false, SAFETY_NET_KEEP_UNTIL: 20261101 }, ignored( 'SAFETY_NET_KEEP_UNTIL (20261101) is not a YYYY-MM-DD date, so SAFETY_NET_DELETE_DATA is ignored and data is deleted.' ) ],
			[ { SAFETY_NET_DELETE_DATA: false, SAFETY_NET_KEEP_UNTIL: 'tomorrow' }, ignored( "SAFETY_NET_KEEP_UNTIL ('tomorrow') is not a YYYY-MM-DD date, so SAFETY_NET_DELETE_DATA is ignored and data is deleted." ) ],
			[ { SAFETY_NET_KEEP_UNTIL: future }, ignored( 'SAFETY_NET_KEEP_UNTIL does nothing unless SAFETY_NET_DELETE_DATA is the boolean false.' ) ],
			[ { SAFETY_NET_DELETE_DATA: false, SAFETY_NET_KEEP_UNTIL: future }, { disabled: true, until: future, expired: false, errors: [] } ],
			[ { SAFETY_NET_DELETE_DATA: false }, { disabled: true, until: null, expired: false, errors: [] } ],
		];
		for ( const [ constants, expected, notice ] of cases ) {
			await setConstants( constants );
			assert.deepEqual( await site.php( 'return \\SafetyNet\\Utilities\\get_keep_config();', { label: 'reading the keep configuration' } ), expected, `get_keep_config() with ${ JSON.stringify( constants ) }` );
			if ( notice ) {
				assert.match( ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text, notice, `The dashboard does not name the invalid value in ${ JSON.stringify( constants ) }` );
			}
		}
	} );

	test( 'KD12: with an invalid value, the next automatic run deletes the data and logs why', async () => {
		await setConstants( { SAFETY_NET_DELETE_DATA: 'false' } );
		// One run, since any later request would already run the delete step.
		const added = await site.php( "$id = sn_test_create_user( 'invalidcustomer', 'customer' ); delete_option( 'safety_net_data_deleted' ); delete_option( 'safety_net_data_kept' ); return array( 'id' => $id, 'exists' => sn_test_user_exists( $id ) );", { label: 'adding a customer and clearing the delete and keep flags' } );
		assert.equal( added.exists, true, 'Adding a customer failed, so this proves nothing' );
		const mark = site.logMark();
		const before = site.probe().length;
		await site.get( '/' );
		assert.deepEqual( httpProbeSince( site, before ).map( ( line ) => [ line.runs.safety_net_delete_data, line.runs.safety_net_keep_data ] ), [ [ 1, 0 ] ], 'The page load kept data although SAFETY_NET_DELETE_DATA is the string \'false\'' );
		assert.equal( await site.php( `return sn_test_user_exists( ${ added.id } );` ), false, 'The automatic run kept a customer although SAFETY_NET_DELETE_DATA is the string \'false\'' );
		assert.ok( site.logEntriesSince( mark ).some( ( entry ) => entry.includes( "Safety Net: SAFETY_NET_DELETE_DATA is 'false' instead of the boolean false, so it is ignored and data is deleted." ) ), 'The automatic run did not log why it ignored SAFETY_NET_DELETE_DATA' );
	} );

	test( 'KD13: a SAFETY_NET_KEEP_UNTIL that has already passed keeps nothing, and the log and wp-admin say why', async () => {
		await setConstants( { SAFETY_NET_DELETE_DATA: false, SAFETY_NET_KEEP_UNTIL: '2000-01-01' } );
		// One run, since any later request would already run the delete step.
		const added = await site.php( "$id = sn_test_create_user( 'expiredcustomer', 'customer' ); delete_option( 'safety_net_data_deleted' ); delete_option( 'safety_net_data_kept' ); return array( 'id' => $id, 'exists' => sn_test_user_exists( $id ) );", { label: 'adding a customer and clearing the delete and keep flags' } );
		assert.equal( added.exists, true, 'Adding a customer failed, so this proves nothing' );
		const mark = site.logMark();
		const before = site.probe().length;
		await site.get( '/' );
		assert.deepEqual( httpProbeSince( site, before ).map( ( line ) => [ line.runs.safety_net_delete_data, line.runs.safety_net_keep_data ] ), [ [ 1, 0 ] ], 'The page load kept data although SAFETY_NET_KEEP_UNTIL had passed' );
		assert.equal( await site.php( `return sn_test_user_exists( ${ added.id } );` ), false, 'The automatic run kept a customer although SAFETY_NET_KEEP_UNTIL had passed' );
		assert.ok( site.logEntriesSince( mark ).some( ( entry ) => entry.includes( 'Safety Net: SAFETY_NET_KEEP_UNTIL (2000-01-01) has passed, so data is deleted.' ) ), 'The automatic run did not log that SAFETY_NET_KEEP_UNTIL had passed' );
		assert.match( ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text, /SAFETY_NET_KEEP_UNTIL \(2000-01-01\) has passed, so SAFETY_NET_DELETE_DATA no longer keeps users, orders and subscriptions\./ );
	} );

	test( 'KD10: the Tools Delete button still deletes everything while SAFETY_NET_DELETE_DATA is false', async () => {
		await setConstants( { SAFETY_NET_DELETE_DATA: false } );
		// Kept and not deleted, as after the first load, so the next requests neither keep nor delete.
		const late = await site.php( "$user = sn_test_create_user( 'latecustomer', 'customer' ); delete_option( 'safety_net_data_deleted' ); update_option( 'safety_net_data_kept', true ); return array( 'user' => $user, 'order' => sn_test_create_order( $user ) );", { label: 'adding a customer and an order to a site that keeps data' } );
		const exists = () => site.php( `return array( 'user' => sn_test_user_exists( ${ late.user } ), 'order' => sn_test_order_exists( ${ late.order } ) );` );
		assert.deepEqual( await exists(), { user: true, order: true }, 'Adding a customer and an order failed, so this proves nothing' );
		assert.match( ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text, /NOT deleted on this site/, 'The dashboard does not say data is kept, so this would not test the button on a site that keeps data' );
		const { res, nonces } = await getToolsPage( site );
		assert.match( res.text, /this button deletes everything anyway/, 'SAFETY_NET_DELETE_DATA is not false on the Tools page, so this would not test the button while data is kept' );
		assert.deepEqual( await postAjax( site, 'safety_net_delete_users', nonces[ 'safety-net-delete-users' ] ), { success: true, message: 'Users, orders, and subscriptions have been successfully deleted!' } );
		assert.deepEqual( await exists(), { user: false, order: false }, 'The Tools Delete button left a customer or an order while SAFETY_NET_DELETE_DATA is false' );
		const s = await site.php( "return array( 'users' => sn_test_users(), 'flags' => sn_test_flags() );" );
		assert.deepEqual( s.users, { admin: 1, admin2: seed.base.users.admin2 } );
		assert.equal( s.flags.safety_net_data_deleted, '1', 'The Tools Delete button did not record that the data is deleted' );
		const [ rest, dashboard ] = await site.getAll( [ '/wp-json/safety-net/v1/status', { path: '/wp-admin/', jar: site.adminJar } ] );
		assert.deepEqual( JSON.parse( rest.text ), { ...STATUS, data_deleted: true, data_kept: true, data_deletion_disabled: true, keep_until: null } );
		assert.match( dashboard.text, /already deleted, so nothing is kept/ );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
