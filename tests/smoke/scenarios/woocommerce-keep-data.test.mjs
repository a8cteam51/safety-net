import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertStepFlags, assertToolsPage, captureMail, firstLoad, getToolsPage, httpProbeSince, postAjax, saveToolsForm, toolsPagePath } from '../lib/checks.mjs';
import { HPOS_TABLES, seedWooCommerceSite } from '../lib/fixtures.mjs';
import { CookieJar } from '../lib/http.mjs';
import { bootSite } from '../lib/site.mjs';

const KEPT = [ ...HPOS_TABLES, 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'order_notes', 'wc_customer_lookup', 'wc_order_stats', 'wc_order_product_lookup', 'subscription_posts', 'subscription_meta', 'membership_posts', 'membership_meta', 'renewal_actions' ];

const CLEARED = [ ...HPOS_TABLES, 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'order_notes', 'wc_customer_lookup', 'wc_order_stats', 'wc_order_product_lookup', 'woocommerce_api_keys', 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta', 'wc_webhooks', 'subscription_posts', 'subscription_meta', 'membership_posts', 'membership_meta', 'woocommerce_log', 'renewal_actions', 'orphaned_action_logs' ];

const CREDENTIALS = [ 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta', 'woocommerce_api_keys' ];

const MAILPOET_KEPT = [ 'subscribers', 'automation_runs' ];

const MAILPOET_SENDING = [ 'scheduled_tasks', 'scheduled_task_subscribers', 'sending_queues' ];

const NEWSLETTERS = [ 'scheduled', 'sending', 'history' ];

// The keep step cancels the automation steps instead of deleting them, so they are counted by status.
const MAILPOET_STATE = "global $wpdb; $steps = static fn( $status ) => sn_test_count( $wpdb->prefix . 'actionscheduler_actions', $wpdb->prepare( 'hook = %s AND status = %s', 'mailpoet/automation/step', $status ) ); return array( 'state' => sn_test_mailpoet_state(), 'steps' => array( 'pending' => $steps( 'pending' ), 'canceled' => $steps( 'canceled' ) ) );";

const STATUS = { active: true, environment: 'staging', options_scrubbed: true, plugins_deactivated: true, gateway_plugins_deactivated: true, transients_deleted: true, webhooks_disabled: true };

const pick = ( counts, tables ) => Object.fromEntries( tables.map( ( table ) => [ table, counts[ table ] ] ) );

describe( 'woocommerce-keep-data: SAFETY_NET_DELETE_DATA false on a WooCommerce store (HPOS) with MailPoet', () => {
	let site;
	let seed;
	let baseline;
	let mailpoetIds;
	let mailpoetSeed;
	let xero;

	// Each request defines the constants anew from this option, so a change applies from the next request on.
	const setConstants = ( constants ) => site.php( `update_option( 'sn_test_constants', json_decode( '${ JSON.stringify( constants ) }', true ) ); return true;`, { label: `setting the constants ${ JSON.stringify( constants ) }` } );

	const newsletterStatuses = ( mailpoet ) => NEWSLETTERS.map( ( key ) => mailpoet.state.newsletters[ mailpoetIds.newsletters[ key ] ]?.status );

	before( async () => {
		site = await bootSite( { name: 'woocommerce-keep-data', env: 'staging', mode: 'plugin', woocommerce: 'active', mailpoet: true } );
		seed = await seedWooCommerceSite( site, { hpos: true } );
		mailpoetIds = await site.php( 'return sn_test_seed_mailpoet();', { label: 'seeding MailPoet' } );
		baseline = await site.php( `update_option( 'sn_test_old_secret_sn_backup', 'live' ); update_option( 'sn_test_keep_integration', 1 ); wp_set_password( 'password', ${ seed.base.users.customer1 } ); return array( 'counts' => sn_test_wc_counts(), 'users' => sn_test_users(), 'cache' => get_user_meta( 1, '_wcs_subscription_ids_cache', true ), 'backup' => sn_test_raw_option( 'sn_test_old_secret_sn_backup' ) );`, { label: 'seeding an option backup, giving customer1 a known password and reading the baseline' } );
		assert.equal( baseline.backup, 'live', 'Seeding an option backup failed, so its removal could not be tested' );
		assert.deepEqual( baseline.cache, [ 101 ], 'Seeding the subscription cache failed, so keeping it could not be tested' );
		xero = await site.php( 'return sn_test_seed_xero_actions();', { label: 'seeding WooCommerce Xero actions' } );
		assert.deepEqual( await site.php( `return sn_test_action_statuses( json_decode( '${ JSON.stringify( xero ) }', true ) );` ), { invoice: 'pending', payment: 'pending', void: 'pending', control: 'pending' }, 'Seeding WooCommerce Xero actions failed' );
		await setConstants( { SAFETY_NET_DELETE_DATA: false } );
		// MailPoet schedules more tasks at the end of the seeding request, so the seed is read in a request of its own.
		mailpoetSeed = await site.php( MAILPOET_STATE, { label: 'reading the seeded MailPoet data' } );
		const unseeded = [ ...CREDENTIALS.filter( ( table ) => ! ( baseline.counts[ table ] > 0 ) ), ...[ ...MAILPOET_KEPT, ...MAILPOET_SENDING ].filter( ( table ) => ! ( mailpoetSeed.state.counts[ table ] > 0 ) ).map( ( table ) => `mailpoet_${ table }` ) ];
		assert.deepEqual( unseeded, [], `Seeding left these tables empty, so keeping or emptying them could not be tested: ${ unseeded.join( ', ' ) }` );
		assert.deepEqual( mailpoetSeed.steps, { pending: 1, canceled: 0 }, 'The MailPoet automation step was not seeded as one pending action, so canceling it could not be tested' );
		assert.deepEqual( newsletterStatuses( mailpoetSeed ), [ 'scheduled', 'sending', 'sending' ], 'The MailPoet emails were not seeded with the statuses the keep step changes' );
		assert.deepEqual( mailpoetSeed.state.active, { mailpoet: true, premium: true } );
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

	test( 'KD7: wp-admin says data is kept and what keeps the copy from acting on it, the Tools page says Delete deletes anyway, and later loads do not run the keep step again', async () => {
		const jar = await site.login();
		const [ dashboard, tools ] = await site.getAll( [ '/wp-admin/', toolsPagePath() ].map( ( path ) => ( { path, jar } ) ) );
		assert.match( dashboard.text, /safety-net-keep-data/, 'The dashboard has no notice about kept data' );
		// esc_html() turns the apostrophe into &#039;.
		assert.match( dashboard.text, /<p>This site keeps a copy of the live site(?:'|&#0?39;)s users, orders and subscriptions, because SAFETY_NET_DELETE_DATA is false \(with no expiry date\)\. Removing the constant deletes them on the next page load\.<\/p>/ );
		assert.match( dashboard.text, /<p>Safety Net keeps this copy from acting on them: emails are blocked and MailPoet(?:'|&#0?39;)s sending is paused \(password resets only reach administrators\), subscription renewals are paused, payment gateways are deactivated and their keys and saved payment methods removed, webhooks are disabled, and accounts copied from the live site cannot log in\.<\/p>/, 'The notice does not say what keeps the copy from acting on the kept data' );
		const { res } = assertToolsPage( tools );
		assert.match( res.text, /this button deletes everything anyway/ );
		const before = site.probe().length;
		const mark = site.logMark();
		await site.get( '/' );
		assert.deepEqual( httpProbeSince( site, before ).map( ( line ) => [ line.runs.safety_net_keep_data, line.runs.safety_net_delete_data ] ), [ [ 0, 0 ] ], 'A later page load ran the keep or delete step again' );
		assert.ok( ! site.logEntriesSince( mark ).some( ( entry ) => entry.includes( 'SN_TEST integration phase keep' ) ), 'A later page load ran the integrations\' keep phase again' );
	} );

	test( 'KD14: saved payment methods and REST API keys are deleted while orders are kept, and webhooks stay disabled', async () => {
		const counts = await site.php( 'return sn_test_wc_counts();' );
		assert.deepEqual( pick( counts, CREDENTIALS ), Object.fromEntries( CREDENTIALS.map( ( table ) => [ table, 0 ] ) ), `Saved payment methods or API keys are kept next to the kept orders (seeded ${ JSON.stringify( pick( baseline.counts, CREDENTIALS ) ) })` );
		assert.deepEqual( pick( counts, KEPT ), pick( baseline.counts, KEPT ), 'Deleting saved payment methods and API keys also deleted orders, subscriptions or memberships' );
		assert.equal( counts.webhooks_active, 0, 'A WooCommerce webhook is active again' );
	} );

	test( 'KD15: MailPoet keeps its subscribers and automation runs, but its sending tasks and queues are emptied, scheduled emails become drafts, automation steps are canceled and MailPoet is deactivated', async () => {
		const s = await site.php( MAILPOET_STATE );
		assert.deepEqual( pick( s.state.counts, MAILPOET_KEPT ), pick( mailpoetSeed.state.counts, MAILPOET_KEPT ), 'The keep step deleted MailPoet subscribers or automation runs' );
		assert.deepEqual( pick( s.state.counts, MAILPOET_SENDING ), Object.fromEntries( MAILPOET_SENDING.map( ( table ) => [ table, 0 ] ) ), `MailPoet sending tasks or queues are kept next to the kept subscribers (seeded ${ JSON.stringify( pick( mailpoetSeed.state.counts, MAILPOET_SENDING ) ) })` );
		assert.deepEqual( newsletterStatuses( s ), [ 'draft', 'draft', 'sent' ], 'The scheduled and sending emails did not become drafts, or the sending post notification did not become sent' );
		assert.deepEqual( s.steps, { pending: 0, canceled: 1 }, 'The pending MailPoet automation step was not canceled, or was deleted' );
		assert.deepEqual( s.state.active, { mailpoet: false, premium: false }, 'MailPoet or MailPoet Premium is still active' );
	} );

	test( 'KD21: WooCommerce Xero\'s pending actions are canceled, its tokens deleted and its client secret blanked while data is kept', async () => {
		const s = await site.php( `return array( 'actions' => sn_test_action_statuses( json_decode( '${ JSON.stringify( xero ) }', true ) ), 'tokens' => sn_test_raw_option( 'xero_oauth_options' ), 'secret' => sn_test_raw_option( 'wc_xero_client_secret' ) );` );
		assert.deepEqual( s.actions, { invoice: 'canceled', payment: 'canceled', void: 'canceled', control: 'pending' }, 'A WooCommerce Xero action can still send the kept orders to Xero, or an unrelated pending action was canceled' );
		assert.equal( s.tokens, null, 'The Xero tokens are kept next to the kept orders' );
		assert.equal( s.secret, '', 'The Xero client secret was not blanked' );
	} );

	test( 'KD16: WooCommerce Subscriptions stays in its staging mode, which takes no automatic renewal payments', async () => {
		assert.equal( await site.php( "return apply_filters( 'woocommerce_subscriptions_is_duplicate_site', false );" ), true, 'WooCommerce Subscriptions could leave its staging mode and charge kept subscriptions automatically' );
	} );

	test( 'KD17: renewal actions stay paused while data is kept, even with the pause turned off, and the locked toggle still submits the stored setting', async () => {
		try {
			// A disabled checkbox is not submitted, so the form carries the stored setting in a hidden field instead.
			assert.match( ( await getToolsPage( site ) ).res.text, /<input type="hidden" name="safety_net_pause_renewal_actions_toggle" value="on" \/>/, 'Saving the Tools form while the pause is locked would turn the stored setting off' );
			await site.php( "update_option( 'safety_net_pause_renewal_actions_toggle', 'off' ); return true;", { label: 'turning the renewal pause off' } );
			await saveToolsForm( site );
			const before = site.probe().length;
			await site.get( '/' );
			const [ load ] = httpProbeSince( site, before );
			assert.equal( load?.as_store, 'SafetyNet\\ActionScheduler_Custom_DBStore', 'Renewal actions are no longer paused with the pause turned off while data is kept' );
			const [ dashboard, tools ] = await site.getAll( [ '/wp-admin/', toolsPagePath() ].map( ( path ) => ( { path, jar: site.adminJar } ) ) );
			assert.match( tools.text, /<input id="safety_net_pause_renewal_actions_toggle"(?=[^>]*\schecked\b)(?=[^>]*\sdisabled\b)[^>]*>/, 'The Tools page does not show the pause as on and locked while data is kept' );
			assert.match( tools.text, /<p class="description"[^>]*>Always on while SAFETY_NET_DELETE_DATA keeps customer data\.<\/p>/ );
			assert.match( dashboard.text, /WooCommerce Subscriptions scheduled actions are currently paused\./, 'The dashboard does not say renewal actions are paused' );
		} finally {
			await site.php( "update_option( 'safety_net_pause_renewal_actions_toggle', 'on' ); return true;", { label: 'turning the renewal pause back on' } );
		}
	} );

	test( 'KD18: accounts copied from the live site cannot log in by password, cookie or application password, while administrators and accounts created afterwards can', async () => {
		const kept = Math.max( ...Object.values( baseline.users ) );
		assert.equal( Number( await site.php( "return get_option( 'safety_net_kept_users_max_id' );" ) ), kept, 'The keep step did not record the highest user ID it kept' );
		const newtester = await site.php( "$id = sn_test_create_user( 'newtester', 'customer' ); wp_set_password( 'password', $id ); return $id;", { label: 'creating a customer after the keep step' } );
		assert.ok( newtester > kept, `The customer created after the keep step got ID ${ newtester }, not above the kept ${ kept }, so this proves nothing` );

		const refused = new CookieJar();
		refused.cookies.set( 'wordpress_test_cookie', 'WP%20Cookie%20check' );
		const page = await site.post( '/wp-login.php', { log: 'customer1', pwd: 'password', 'wp-submit': 'Log In', redirect_to: '/wp-admin/', testcookie: '1' }, { jar: refused } );
		assert.ok( ! [ ...refused.cookies.keys() ].some( ( name ) => name.startsWith( 'wordpress_logged_in_' ) ), 'customer1, copied from the live site, logged in through wp-login.php' );
		assert.match( page.text, /<strong>Error:<\/strong> This is a copy of the live site that keeps its customer data, so accounts copied from the live site cannot log in here\. Administrators can\./, 'wp-login.php does not say why customer1 cannot log in' );
		await site.get( '/wp-admin/', { jar: await site.login( { jar: new CookieJar() } ) } );
		await site.login( { user: 'newtester', jar: new CookieJar(), redirectTo: '/' } );

		const me = async ( options, expect ) => JSON.parse( ( await site.get( '/wp-json/wp/v2/users/me', { ...options, expect } ) ).text );
		// The REST API trusts a cookie only with a nonce, which belongs to the user and the session in that cookie.
		const cookieFor = async ( login ) => {
			const made = await site.php( `$user = get_user_by( 'login', '${ login }' ); $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user->ID, time() + DAY_IN_SECONDS, 'logged_in' ); wp_set_current_user( $user->ID ); return array( 'name' => LOGGED_IN_COOKIE, 'value' => $_COOKIE[ LOGGED_IN_COOKIE ], 'nonce' => wp_create_nonce( 'wp_rest' ) );`, { label: `making a logged-in cookie for ${ login }` } );
			const jar = new CookieJar();
			jar.cookies.set( made.name, encodeURIComponent( made.value ) );
			return { jar, headers: { 'X-WP-Nonce': made.nonce } };
		};
		assert.equal( ( await me( await cookieFor( 'newtester' ), 200 ) ).id, newtester, 'A logged-in cookie made this way does not log in a customer created after the keep step, so this proves nothing' );
		assert.equal( ( await me( await cookieFor( 'customer1' ), [ 401, 403 ] ) ).id, undefined, 'A valid logged-in cookie still logs in customer1, copied from the live site' );

		await site.php( "update_option( 'sn_test_application_passwords', 1 ); return true;", { label: 'offering application passwords over HTTP' } );
		try {
			const passwordFor = async ( login ) => {
				const password = await site.php( `$created = WP_Application_Passwords::create_new_application_password( get_user_by( 'login', '${ login }' )->ID, array( 'name' => 'SN test' ) ); if ( is_wp_error( $created ) ) { throw new RuntimeException( $created->get_error_message() ); } return $created[0];`, { label: `creating an application password for ${ login }` } );
				return { headers: { authorization: `Basic ${ Buffer.from( `${ login }:${ password }` ).toString( 'base64' ) }` } };
			};
			assert.equal( ( await me( await passwordFor( 'newtester' ), 200 ) ).id, newtester, 'An application password does not log in a customer created after the keep step, so this proves nothing' );
			assert.equal( ( await me( await passwordFor( 'customer1' ), [ 401, 403 ] ) ).id, undefined, 'An application password still logs in customer1, copied from the live site' );
		} finally {
			await site.php( "delete_option( 'sn_test_application_passwords' ); return true;", { label: 'withdrawing application passwords' } );
		}
	} );

	test( 'KD19: a reactivated MailPoet keeps its sending paused while data is kept, also after Resume', async () => {
		const paused = "return array( 'paused' => \\MailPoet\\Mailer\\MailerLog::isSendingPaused(), 'error' => \\MailPoet\\Mailer\\MailerLog::getError() );";
		const expected = { paused: true, error: { operation: 'migration', error_message: 'Safety Net paused sending: this site is a copy of the live site and keeps its real subscribers, because SAFETY_NET_DELETE_DATA is false. Sending stays paused until that data is deleted.' } };
		try {
			// Activating MailPoet resets its sending log, which would clear any pause stored before.
			await site.php( "require_once ABSPATH . 'wp-admin/includes/plugin.php'; $result = activate_plugin( 'mailpoet/mailpoet.php' ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); } return true;", { label: 'reactivating MailPoet' } );
			assert.deepEqual( await site.php( paused, { label: 'reading MailPoet\'s sending state' } ), expected, 'MailPoet can send to the kept subscribers after it was reactivated' );
			assert.equal( await site.php( 'return \\MailPoet\\Mailer\\MailerLog::resumeSending()["status"] ?? null;', { label: 'resuming MailPoet\'s sending' } ), null, 'Resuming MailPoet\'s sending failed, so this proves nothing' );
			assert.deepEqual( await site.php( paused, { label: 'reading MailPoet\'s sending state again' } ), expected, 'MailPoet\'s Resume lets it send to the kept subscribers' );
		} finally {
			await site.php( "require_once ABSPATH . 'wp-admin/includes/plugin.php'; deactivate_plugins( 'mailpoet/mailpoet.php', true ); return true;", { label: 'deactivating MailPoet again' } );
		}
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
		const s = await site.php( "return array( 'counts' => sn_test_wc_counts(), 'users' => sn_test_users(), 'flags' => sn_test_flags(), 'duplicate_site' => apply_filters( 'woocommerce_subscriptions_is_duplicate_site', false ) );" );
		for ( const table of CLEARED ) {
			assert.equal( s.counts[ table ], 0, `${ table } still has ${ s.counts[ table ] } rows (seeded ${ seed.woo.counts[ table ] })` );
		}
		assert.deepEqual( s.users, { admin: 1, admin2: seed.base.users.admin2 } );
		assert.equal( s.flags.safety_net_data_deleted, '1' );
		assert.equal( s.flags.safety_net_data_kept, '1', 'Deleting the kept data cleared the flag that says it was kept' );
		assert.equal( s.duplicate_site, false, 'WooCommerce Subscriptions is still held in its staging mode, which only applies while data is kept' );
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
		assert.match( ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text, /This site keeps a copy of the live site/, 'The dashboard does not say data is kept, so this would not test the button on a site that keeps data' );
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
		// customer1's ID is at or below the recorded highest ID, so only the deleted-data check can let it through.
		assert.equal( await site.php( `return \\SafetyNet\\Admin\\is_blocked_kept_user( ${ seed.base.users.customer1 } );` ), false, 'Accounts are still refused after the data was deleted, so test accounts made on the copy cannot log in' );
	} );

	test( 'KD20: once the kept data is deleted, Safety Net lifts its own MailPoet pause but leaves MailPoet\'s own ones', async () => {
		const state = "return array( 'paused' => \\MailPoet\\Mailer\\MailerLog::isSendingPaused(), 'operation' => \\MailPoet\\Mailer\\MailerLog::getError()['operation'] ?? null );";
		try {
			await site.php( "require_once ABSPATH . 'wp-admin/includes/plugin.php'; $result = activate_plugin( 'mailpoet/mailpoet.php' ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); } return true;", { label: 'reactivating MailPoet' } );
			assert.equal( await site.php( "return get_option( 'safety_net_data_deleted' ) && get_option( 'safety_net_data_kept' );" ), true, 'The data is not deleted after being kept, so this proves nothing' );
			assert.deepEqual( await site.php( `\\SafetyNet\\Integrations\\MailPoet\\pause_sending(); ${ state }`, { label: 'storing Safety Net\'s pause' } ), { paused: true, operation: 'migration' }, 'Storing Safety Net\'s pause failed, so this proves nothing' );
			assert.deepEqual( await site.php( state, { label: 'reading MailPoet\'s sending state' } ), { paused: false, operation: null }, 'MailPoet stays paused by Safety Net after the kept data was deleted' );
			await site.php( "\\MailPoet\\Mailer\\MailerLog::pauseSending( \\MailPoet\\Mailer\\MailerLog::setError( \\MailPoet\\Mailer\\MailerLog::getMailerLog(), 'send', 'SMTP refused the connection' ) ); return true;", { label: 'pausing MailPoet the way a sending error does' } );
			assert.deepEqual( await site.php( state, { label: 'reading MailPoet\'s sending state again' } ), { paused: true, operation: 'send' }, 'Safety Net resumed a pause that MailPoet set for its own sending error' );
		} finally {
			await site.php( "\\MailPoet\\Mailer\\MailerLog::resumeSending(); require_once ABSPATH . 'wp-admin/includes/plugin.php'; deactivate_plugins( 'mailpoet/mailpoet.php', true ); return true;", { label: 'deactivating MailPoet again' } );
		}
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
