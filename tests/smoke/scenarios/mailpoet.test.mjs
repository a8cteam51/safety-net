import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertStepFlags, firstLoad, getToolsPage, postAjax } from '../lib/checks.mjs';
import { bootSite } from '../lib/site.mjs';

const DELETED = [ 'subscribers', 'subscriber_segment', 'subscriber_custom_field', 'subscriber_tag', 'subscriber_ips', 'statistics_newsletters', 'statistics_opens', 'statistics_clicks', 'statistics_bounces', 'statistics_unsubscribes', 'statistics_forms', 'statistics_woocommerce_purchases', 'user_agents', 'scheduled_tasks', 'scheduled_task_subscribers', 'sending_queues', 'stats_notifications', 'newsletter_links', 'automation_runs', 'automation_run_subjects', 'automation_run_logs', 'log' ];

const KEPT = [ 'segments', 'dynamic_segment_filters', 'custom_fields', 'tags', 'forms', 'newsletter_templates', 'newsletter_option_fields', 'newsletter_option', 'newsletter_segment', 'newsletter_posts', 'automations', 'automation_versions', 'automation_triggers', 'feature_flags', 'user_flags', 'migrations' ];

// Null where a fresh MailPoet install has null, since MailPoet trusts a stored key state.
const SCRUBBED = {
	mta: { mailpoet_api_key: '', mailpoet_api_key_state: null, login: '', password: '', api_key: '', access_key: '', secret_key: '' },
	premium: { premium_key: '', premium_key_state: null },
	// Forms fall back to MailPoet's own captcha, since the live site's reCAPTCHA or Turnstile keys cannot verify anything here.
	captcha: { type: 'built-in', recaptcha_secret_token: '', recaptcha_invisible_secret_token: '', turnstile_secret_token: '' },
	re_captcha: { secret_token: '' },
	sender: { address: '' },
	reply_to: { address: '' },
	bounce: { address: '' },
	stats_notifications: { address: '' },
	subscriber_email_notification: { address: '' },
	mta_log: { error: null },
};

const EXPORTS = [ 'MailPoet_export_old.xlsx', 'MailPoet_stats_export_old.csv', 'exports/MailPoet_export_snseedtoken.csv', 'exports/MailPoet_stats_export_snseedtoken.csv' ];

const STATE = 'return sn_test_mailpoet_state();';

// Each page's own container, since an unfinished setup redirects every page to a landing page that also loads fine.
const MAILPOET_PAGES = {
	'mailpoet-newsletters': 'newsletters_container',
	'mailpoet-forms': 'forms_container',
	'mailpoet-lists': 'static_segments_container',
	'mailpoet-segments': 'dynamic_segments_container',
	'mailpoet-subscribers': 'subscribers_container',
	'mailpoet-automation': 'mailpoet_automation',
	'mailpoet-settings': 'settings_container',
};

const isBlank = ( value ) => value === undefined || value === null || value === '';

const withoutUpdatedAt = ( rows ) => Object.fromEntries( Object.entries( rows ).map( ( [ id, { updated_at: _, ...row } ] ) => [ id, row ] ) );

describe( 'mailpoet: regular plugin with MailPoet and Premium active, people and credentials go, configuration stays (#151)', () => {
	let site;
	let ids;
	let seeded;

	before( async () => {
		site = await bootSite( { name: 'mailpoet', env: 'staging', mode: 'plugin', mailpoet: true } );
		await site.php( 'return sn_test_seed_base();', { label: 'seeding the site' } );
		ids = await site.php( 'return sn_test_seed_mailpoet();', { label: 'seeding MailPoet' } );
		// MailPoet schedules more tasks at the end of the seeding request, so the seed is read in a request of its own.
		seeded = await site.php( STATE, { label: 'reading the seeded MailPoet data' } );

		const empty = [ ...DELETED, ...KEPT, 'newsletters', 'settings' ].filter( ( table ) => ! ( seeded.counts[ table ] > 0 ) );
		assert.deepEqual( empty, [], `Seeding left these MailPoet tables empty, so the test could not tell kept from deleted: ${ empty.join( ', ' ) }` );
		const unseeded = Object.entries( SCRUBBED ).flatMap( ( [ row, keys ] ) => Object.keys( keys ).filter( ( key ) => isBlank( seeded.settings[ row ]?.[ key ] ) ).map( ( key ) => `${ row }.${ key }` ) );
		assert.deepEqual( unseeded, [], `Seeding left these settings blank, so their scrub could not be tested: ${ unseeded.join( ', ' ) }` );
		assert.ok( seeded.settings.authorized_emails_addresses_check, 'The authorized email addresses check was not seeded' );
		assert.ok( seeded.actions[ 'mailpoet/automation/step' ] === 1 && seeded.actions.step_logs > 0, `The automation step action was not seeded: ${ JSON.stringify( seeded.actions ) }` );
		assert.ok( seeded.actions[ 'mailpoet/cron/daemon-trigger' ] > 0 && seeded.actions.sn_keep_hook === 1, `The scheduled actions that must survive were not seeded: ${ JSON.stringify( seeded.actions ) }` );
		assert.deepEqual( seeded.active, { mailpoet: true, premium: true } );
		const status = ( key ) => seeded.newsletters[ ids.newsletters[ key ] ]?.status;
		assert.deepEqual( [ 'sent', 'scheduled', 'sending', 'notification', 'history' ].map( status ), [ 'sent', 'scheduled', 'sending', 'active', 'sending' ], 'The newsletters were not seeded with the statuses under test' );
		const unsent = Object.values( seeded.newsletters ).filter( ( row ) => isBlank( row.sender_address ) || isBlank( row.reply_to_address ) ).map( ( row ) => row.subject );
		assert.deepEqual( unsent, [], 'Seeding left newsletters without a sender or reply-to address' );
		const unseededFiles = EXPORTS.filter( ( file ) => ! seeded.files.includes( file ) );
		assert.deepEqual( unseededFiles, [], 'The export files were not seeded' );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'M1: the first page load runs the automatic pass and deactivates MailPoet and MailPoet Premium', async () => {
		await firstLoad( site );
		assertStepFlags( await site.php( 'return sn_test_flags();' ), { woocommerce: false } );
		assert.deepEqual( ( await site.php( STATE ) ).active, { mailpoet: false, premium: false } );
	} );

	test( 'M2: subscribers, statistics, sending tasks and queues, automation runs, their scheduled steps, the MailPoet log and export files are deleted', async () => {
		const s = await site.php( STATE );
		assert.deepEqual( s.files, seeded.files.filter( ( file ) => ! EXPORTS.includes( file ) ), 'Export files were left, or other files in uploads/mailpoet were deleted' );
		const left = DELETED.filter( ( table ) => s.counts[ table ] !== 0 );
		assert.deepEqual( left, [], `These MailPoet tables still have rows: ${ left.map( ( table ) => `${ table }=${ s.counts[ table ] } (seeded ${ seeded.counts[ table ] })` ).join( ', ' ) }` );
		assert.equal( s.actions[ 'mailpoet/automation/step' ], 0, 'The automation step action is still scheduled' );
		assert.equal( s.actions.step_logs, 0, 'The automation step action still has logs' );
	} );

	test( 'M3: lists, segments, custom fields, tags, forms, emails, templates and automations are kept, emails without their sender and reply-to addresses', async () => {
		const s = await site.php( STATE );
		const changed = KEPT.filter( ( table ) => s.kept[ table ] !== seeded.kept[ table ] );
		assert.deepEqual( changed, [], `These MailPoet tables changed: ${ changed.map( ( table ) => `${ table } (${ seeded.counts[ table ] } -> ${ s.counts[ table ] } rows)` ).join( ', ' ) }` );
		assert.deepEqual( { mailpoet_cron: s.actions[ 'mailpoet/cron/daemon-trigger' ], other: s.actions.sn_keep_hook }, { mailpoet_cron: seeded.actions[ 'mailpoet/cron/daemon-trigger' ], other: 1 }, 'A scheduled action other than the automation steps was deleted' );

		const expected = withoutUpdatedAt( seeded.newsletters );
		for ( const row of Object.values( expected ) ) {
			Object.assign( row, { sender_address: '', reply_to_address: '' } );
		}
		expected[ ids.newsletters.scheduled ].status = 'draft';
		expected[ ids.newsletters.sending ].status = 'draft';
		expected[ ids.newsletters.history ].status = 'sent';
		assert.deepEqual( withoutUpdatedAt( s.newsletters ), expected, 'Newsletters changed beyond losing their addresses, the scheduled and sending ones becoming drafts and the sending post notification becoming sent' );
	} );

	test( 'M4: service keys, mail credentials, captcha secrets, email addresses and the last sending error are blanked, captcha falls back to built-in, and every other setting is unchanged', async () => {
		const { settings } = await site.php( STATE );
		const expected = structuredClone( seeded.settings );
		for ( const [ row, keys ] of Object.entries( SCRUBBED ) ) {
			Object.assign( expected[ row ], keys );
		}
		expected.authorized_emails_addresses_check = null;
		assert.deepEqual( settings, expected );
	} );

	test( 'M5: the Tools page scrub leaves a malformed MailPoet setting alone', async () => {
		const sql = "global $wpdb; $table = $wpdb->prefix . 'mailpoet_settings';";
		const original = await site.php( `${ sql } return $wpdb->get_var( "SELECT value FROM $table WHERE name = 'bounce'" );` );
		await site.php( `${ sql } return $wpdb->update( $table, array( 'value' => 'bounce@client.example' ), array( 'name' => 'bounce' ) );`, { label: 'storing a malformed bounce setting' } );
		await site.login();
		const { nonces } = await getToolsPage( site );
		assert.deepEqual( await postAjax( site, 'safety_net_scrub_options', nonces[ 'safety-net-scrub-options' ] ), { success: true, message: 'Options have been scrubbed.' } );
		const after = await site.php( `${ sql } $value = $wpdb->get_var( "SELECT value FROM $table WHERE name = 'bounce'" ); $wpdb->update( $table, array( 'value' => base64_decode( '${ Buffer.from( original ).toString( 'base64' ) }' ) ), array( 'name' => 'bounce' ) ); return $value;`, { label: 'restoring the bounce setting' } );
		assert.equal( after, 'bounce@client.example' );
	} );

	test( 'M6: later requests, including admin pages, bring nothing back', async () => {
		const settled = await site.php( STATE );
		await site.get( '/' );
		await site.login();
		await site.get( '/wp-admin/', { jar: site.adminJar } );
		await site.get( '/wp-admin/plugins.php', { jar: site.adminJar } );
		await getToolsPage( site );
		assert.deepEqual( await site.php( STATE ), settled );
	} );

	test( 'M7: MailPoet reactivated afterwards works with what was kept, and the Tools page scrubs and deletes again while it is active', async () => {
		await site.php( "require_once ABSPATH . 'wp-admin/includes/plugin.php'; $result = activate_plugin( 'mailpoet/mailpoet.php' ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); } return true;", { label: 'reactivating MailPoet' } );
		for ( const [ page, container ] of Object.entries( MAILPOET_PAGES ) ) {
			const res = await site.get( `/wp-admin/admin.php?page=${ page }`, { jar: site.adminJar, follow: false } );
			assert.ok( res.text.includes( `id="${ container }"` ), `${ page } did not render its own page` );
		}
		const form = await site.php( `return do_shortcode( '[mailpoet_form id="${ ids.form }"]' );`, { label: 'rendering the kept form' } );
		assert.match( form, /class="[^"]*mailpoet_form/, 'The kept MailPoet form no longer renders' );
		const reactivated = await site.php( STATE );
		assert.equal( reactivated.settings.sender.address, 'safetynet@scrubbedthis.option', 'Reactivating MailPoet did not fall back to the scrubbed admin email as its sender' );
		assert.equal( reactivated.settings.mta.mailpoet_api_key, '' );
		assert.equal( reactivated.settings.premium.premium_key, '' );

		await site.php(
			`$settings = \\MailPoet\\DI\\ContainerWrapper::getInstance()->get( \\MailPoet\\Settings\\SettingsController::class );
$settings->set( 'mta.mailpoet_api_key', 'sn-mss-key-again' );
$settings->set( 'premium.premium_key', 'sn-premium-key-again' );
\\MailPoet\\API\\API::MP( 'v1' )->addSubscriber( array( 'email' => 'late@example.com' ), array( ${ ids.list } ), array( 'send_confirmation_email' => false, 'schedule_welcome_email' => false, 'skip_subscriber_notification' => true ) );
return true;`,
			{ label: 'adding a key and a subscriber with MailPoet active' }
		);
		const added = await site.php( STATE );
		assert.ok( added.counts.subscribers > 0 && added.settings.mta.mailpoet_api_key === 'sn-mss-key-again', 'Adding a key and a subscriber with MailPoet active failed' );

		const { nonces } = await getToolsPage( site );
		assert.deepEqual( await postAjax( site, 'safety_net_scrub_options', nonces[ 'safety-net-scrub-options' ] ), { success: true, message: 'Options have been scrubbed.' } );
		assert.deepEqual( await postAjax( site, 'safety_net_delete_users', nonces[ 'safety-net-delete-users' ] ), { success: true, message: 'Users, orders, and subscriptions have been successfully deleted!' } );
		const s = await site.php( STATE );
		assert.equal( s.active.mailpoet, true, 'MailPoet was deactivated, so this did not test the tools with MailPoet active' );
		assert.deepEqual( { key: s.settings.mta.mailpoet_api_key, premium: s.settings.premium.premium_key, subscribers: s.counts.subscribers }, { key: '', premium: '', subscribers: 0 } );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
