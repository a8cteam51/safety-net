import assert from 'node:assert/strict';
import vm from 'node:vm';
import { after, before, describe, test } from 'node:test';
import { assertMailBlocked, assertToolsAssets, captureMail, firstLoad, getToolsPage, saveToolsForm, TOOL_BUTTONS, toolsPagePath } from '../lib/checks.mjs';
import { CookieJar } from '../lib/http.mjs';
import { bootSite } from '../lib/site.mjs';
import { decode, emailsNotices, emailsNoticesHtml, ICONS, SEND_EMAILS, SEND_EMAILS_SITE, sendEmailsForm, setStoredHome, siteKey, statusRows, statusSummary, withoutIcons } from '../lib/status.mjs';

const SETTINGS = '/wp-admin/admin.php?page=safety_net_email_log_settings';

const PAGE = '/wp-admin/admin.php?page=safety_net_email_log';

const LOG_SWITCHES = [ 'safety_net_email_log_toggle', 'safety_net_email_log_cleanup_toggle' ];

const COMMAND = `wp option update ${ SEND_EMAILS } off`;

const WARNING = 'Lets this site send every email WordPress sends, to real addresses: test customers, the site\'s staff, anyone a form or order emails. Leave it off unless you are testing email delivery.';

const CONFIRM = 'Turn on Send emails? This site will then send every email WordPress sends to real addresses: test customers, the site\'s staff, anyone a form or order emails. Save the settings to apply it.';

const COPY_NOTE = 'Switched on for another address (this site is a copy); switch it on again to send emails here.';

const NO_SITE_NOTE = 'Switched on without this site\'s address (written straight to the database); switch it on again to send emails here.';

const BLOCKED = 'Emails sent through wp_mail() are blocked. Plugins that send email another way are neither blocked nor logged.';

const COPY_HOME = 'https://Copy.Example.TEST/Shop/';

const NOTICE = { title: 'Safety Net: emails are switched on.', text: 'This site sends every email WordPress sends to real addresses.' };

const REST = { active: true, environment: 'staging', options_scrubbed: true, plugins_deactivated: true, gateway_plugins_deactivated: false, data_deleted: true, data_kept: false, data_deletion_disabled: false, keep_until: null, transients_deleted: true, webhooks_disabled: true };

const STEP_FLAGS = [ 'safety_net_options_scrubbed', 'safety_net_plugins_deactivated', 'safety_net_gateway_plugins_pending', 'safety_net_gateway_plugins_deactivated', 'safety_net_data_deleted', 'safety_net_data_kept', 'safety_net_transients_deleted', 'safety_net_webhooks_disabled', 'safety_net_email_log_db_version' ];

const MARKUP_PLUGIN = { file: 'zz-extra-denied-markup/zz-extra-denied-markup.php', name: 'ZZ <em>Denied</em> & "Quoted" <script>alert(1)</script>' };

// Runs the admin script against a document that only records its listeners, so its change handler can be driven like a browser would.
function runAdminScript( source ) {
	const listeners = {};
	const asked = [];
	let answer = true;
	class Element {}
	class HTMLFormElement extends Element {}
	class HTMLInputElement extends Element {
		constructor( props ) {
			super();
			Object.assign( this, props );
		}
	}
	const context = {
		document: {
			getElementById: () => null,
			querySelectorAll: () => [],
			addEventListener: ( type, listener ) => ( listeners[ type ] ??= [] ).push( listener ),
		},
		Element,
		HTMLFormElement,
		HTMLInputElement,
		jQuery: () => ( {} ),
		confirm: ( message ) => {
			asked.push( message );
			return answer;
		},
	};
	context.window = context;
	vm.runInNewContext( source, context );
	return {
		listeners,
		change( props, reply ) {
			const input = new HTMLInputElement( { type: 'checkbox', dataset: {}, ...props } );
			answer = reply;
			asked.length = 0;
			for ( const listener of listeners.change ?? [] ) {
				listener( { target: input } );
			}
			return { asked: [ ...asked ], checked: input.checked };
		},
	};
}

describe( 'status-and-switch: the Send emails switch and the status panel on a regular plugin on a staging site', () => {
	let site;
	let notice;

	const rest = async () => JSON.parse( ( await site.get( '/wp-json/safety-net/v1/status' ) ).text );
	const setSwitch = ( value ) => site.php( value === null ? `delete_option( '${ SEND_EMAILS }' ); return true;` : `update_option( '${ SEND_EMAILS }', '${ value }' ); return true;`, { label: `setting Send emails to ${ value }` } );
	const raw = ( name ) => site.php( `return sn_test_raw_option( '${ name }' );`, { label: `reading ${ name }` } );
	const phpRows = ( body = '' ) => site.php( `${ body } return SafetyNet\\Status\\get_status_rows();`, { label: 'building the status rows' } );
	const url = ( target ) => new URL( target, site.siteUrl ).href;
	// The key for this site: its stored home option, and WP_HOME, which Playground sets to the site's URL.
	const homeKey = async () => siteKey( await raw( 'home' ), site.siteUrl );
	const switchState = () => site.php( 'return SafetyNet\\Utilities\\get_send_emails_switch_state();', { label: 'reading the Send emails state' } );

	// Core's password and email change notifications for a user, with Safety Net's verdict on each; none is sent.
	const changeNotifications = () => site.php( `
$id       = sn_test_create_user( 'sn_change_' . strtolower( wp_generate_password( 8, false ) ), 'subscriber' );
$captured = array();
$capture  = static function ( $verdict, $atts ) use ( &$captured ) {
	$captured[] = array( 'subject' => preg_replace( '/^\\[[^\\]]*\\] /', '', $atts['subject'] ), 'verdict' => $verdict );
	return false;
};
add_filter( 'pre_wp_mail', $capture, 11, 2 );
wp_update_user( array( 'ID' => $id, 'user_pass' => 'changed-password' ) );
wp_update_user( array( 'ID' => $id, 'user_email' => 'changed-' . $id . '@example.com' ) );
remove_filter( 'pre_wp_mail', $capture, 11 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $id );
return $captured;`, { label: 'changing a user\'s password and email address' } );

	// Posts the Email Log Settings form as the browser does, with the log switches on and Send emails as given.
	async function saveSettings( send ) {
		const form = sendEmailsForm( ( await site.get( SETTINGS, { jar: site.adminJar } ) ).text );
		const res = await site.post( '/wp-admin/options.php', { ...form.fields, ...Object.fromEntries( LOG_SWITCHES.map( ( name ) => [ name, 'on' ] ) ), ...( send ? { [ SEND_EMAILS ]: 'on' } : {} ) }, { jar: site.adminJar, follow: false, expect: 302 } );
		assert.match( res.location ?? '', /page=safety_net_email_log_settings&settings-updated=true/, `Saving the Settings form redirected to ${ res.location }` );
		return sendEmailsForm( ( await site.get( SETTINGS, { jar: site.adminJar } ) ).text );
	}

	before( async () => {
		site = await bootSite( { name: 'status-and-switch', env: 'staging', mode: 'plugin', wpCli: true } );
		await site.php( 'return sn_test_seed_base();', { label: 'seeding the site' } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'SW1: with Send emails never saved, emails are blocked, core\'s change notifications are not sent, the REST route says emails_enabled false and no page warns', async () => {
		await firstLoad( site );
		await site.login();
		assert.equal( await raw( SEND_EMAILS ), null, 'Send emails is stored, so this does not test the default' );
		assertMailBlocked( await captureMail( site ) );
		assert.deepEqual( await changeNotifications(), [], 'Core sent a password or email change notification while emails are off' );
		assert.deepEqual( await rest(), { ...REST, emails_enabled: false } );
		const [ dashboard, tools ] = await site.getAll( [ '/wp-admin/', toolsPagePath() ].map( ( target ) => ( { path: target, jar: site.adminJar } ) ) );
		assert.match( dashboard.text, /Safety Net Activated/, 'The dashboard has no Safety Net notice, so this proves nothing' );
		for ( const res of [ dashboard, tools ] ) {
			assert.doesNotMatch( res.text, /safety-net-emails-enabled/, 'A page warns that emails are on while they are off' );
		}
	} );

	test( 'ST1: the Tools page shows the status panel above the tools form: one row per protection, each with an icon, a state screen readers tell apart without colour, an explanation and where it is changed, and no WooCommerce rows without WooCommerce', async () => {
		const pre = await site.php( "global $wpdb; return array( 'webhooks' => sn_test_table_exists( $wpdb->prefix . 'wc_webhooks' ), 'keys' => sn_test_table_exists( $wpdb->prefix . 'woocommerce_api_keys' ), 'scheduler' => class_exists( 'ActionScheduler' ), 'entries' => count( (array) sn_test_email_log() ) );", { label: 'checking that WooCommerce is absent' } );
		assert.deepEqual( [ pre.webhooks, pre.keys, pre.scheduler ], [ false, false, false ], 'WooCommerce\'s tables or Action Scheduler exist, so this does not test a site without them' );
		assert.ok( pre.entries > 1, 'SW1 logged no emails, so the entry count is not tested' );
		const { res } = await getToolsPage( site );
		const html = res.text;
		const panel = html.indexOf( '<div class="safety-net-status">' );
		assert.ok( panel !== -1 && panel < html.indexOf( '<h3>Tools</h3>' ) && html.indexOf( '<hr/>' ) < panel, 'The status panel is not between the page header and the tools form' );
		assert.match( html, /<div class="safety-net-status">\s*<h3>Status<\/h3>\s*<table class="widefat striped safety-net-status-table">\s*<thead>\s*<tr>\s*<th scope="col">Protection<\/th>\s*<th scope="col">State<\/th>\s*<th scope="col">Details<\/th>/, 'The status panel is not a striped table with its headings' );
		const rows = statusRows( html );
		assert.deepEqual( statusSummary( rows ), [
			[ 'environment', 'ok', 'staging' ],
			[ 'emails', 'ok', 'Blocked, except password resets' ],
			[ 'email_log', 'ok', 'On' ],
			[ 'data', 'ok', 'Deleted' ],
			[ 'options', 'ok', 'Scrubbed' ],
			[ 'plugins', 'ok', 'Deactivated' ],
			[ 'gateways', 'ok', 'Waiting for WooCommerce' ],
			[ 'search_engines', 'ok', 'Discouraged' ],
			[ 'transients', 'ok', 'Deleted' ],
		], 'The panel does not show every protection, in order, as done on a site Safety Net ran on' );
		for ( const row of rows ) {
			assert.deepEqual( [ row.icon, row.severity ], ICONS[ row.state ], `The ${ row.id } row's icon or screen reader text does not match its state` );
			assert.ok( row.label && row.details, `The ${ row.id } row has no label or explanation` );
		}
		const byId = Object.fromEntries( rows.map( ( row ) => [ row.id, row ] ) );
		assert.deepEqual( byId.emails.link, { url: url( SETTINGS ), text: 'Email Log Settings' }, 'The emails row does not link to where Send emails is changed' );
		assert.deepEqual( byId.email_log.link, { url: url( PAGE ), text: 'Email Log' } );
		assert.equal( byId.email_log.details, `The log holds ${ pre.entries } entries. Entries older than 6 months are deleted once a day.` );
		assert.deepEqual( withoutIcons( rows ), await phpRows(), 'The panel does not show what get_status_rows() returns' );
	} );

	test( 'ST2: before Safety Net\'s steps have run, the panel says what is not done yet and links to the tool that does it, without a warning or database error', async () => {
		const mark = site.logMark();
		const rows = await phpRows( `foreach ( json_decode( '${ JSON.stringify( STEP_FLAGS ) }', true ) as $flag ) { add_filter( "pre_option_{$flag}", '__return_zero' ); }` );
		assert.deepEqual( statusSummary( rows ), [
			[ 'environment', 'ok', 'staging' ],
			[ 'emails', 'ok', 'Blocked, except password resets' ],
			[ 'email_log', 'ok', 'On' ],
			[ 'data', 'warning', 'Not deleted yet' ],
			[ 'options', 'warning', 'Not scrubbed yet' ],
			[ 'plugins', 'warning', 'Not deactivated yet' ],
			[ 'gateways', 'ok', 'No WooCommerce' ],
			[ 'search_engines', 'ok', 'Discouraged' ],
			[ 'transients', 'warning', 'Not deleted yet' ],
		] );
		const links = Object.fromEntries( rows.filter( ( row ) => row.state === 'warning' ).map( ( row ) => [ row.id, row.link ] ) );
		const tools = url( toolsPagePath() );
		assert.deepEqual( links, {
			data: { url: `${ tools }#safety-net-delete-users`, text: 'Delete' },
			options: { url: `${ tools }#safety-net-scrub-options`, text: 'Scrub Options' },
			plugins: { url: `${ tools }#safety-net-deactivate-plugins`, text: 'Deactivate Plugins' },
			transients: { url: `${ tools }#safety-net-delete-transients`, text: 'Delete Transients' },
		} );
		const { res } = await getToolsPage( site );
		assert.deepEqual( Object.values( links ).map( ( link ) => link.url.split( '#' )[ 1 ] ).filter( ( id ) => ! TOOL_BUTTONS.includes( id ) || ! res.text.includes( `id="${ id }"` ) ), [], 'A row links to a tool the Tools page does not have' );
		assert.equal( rows.find( ( row ) => row.id === 'email_log' ).details, 'The log holds 0 entries. Entries older than 6 months are deleted once a day.' );
		assert.deepEqual( site.logEntriesSince( mark ).filter( ( line ) => /WordPress database error|PHP (?:Warning|Notice)/.test( line ) ), [], 'Building the rows before the steps ran logged a warning or database error' );
	} );

	test( 'ST6: with the email log table gone but its option left, opening the Tools page counts no entries, forgets the table without a database error, and the next email creates it again', async () => {
		const mark = site.logMark();
		const dropped = await site.php( "global $wpdb; $wpdb->query( \"DROP TABLE {$wpdb->prefix}safety_net_email_log\" ); return sn_test_email_log_state();", { label: 'dropping the email log table and keeping its option' } );
		assert.deepEqual( [ dropped.table, dropped.options.safety_net_email_log_db_version ], [ false, '1' ], 'Dropping the table failed, or its option is gone, so this proves nothing' );
		const { res } = await getToolsPage( site );
		const row = statusRows( res.text ).find( ( candidate ) => candidate.id === 'email_log' );
		assert.ok( row?.details.startsWith( 'The log holds 0 entries.' ), `The email log row does not count 0 entries without the table: ${ row?.details }` );
		assert.equal( await raw( 'safety_net_email_log_db_version' ), null, 'Opening the Tools page did not forget the missing table' );
		assert.deepEqual( site.logEntriesSince( mark ).filter( ( line ) => /WordPress database error/.test( line ) ), [], 'A query reached the missing table and wpdb reported it' );
		const sent = await site.php( "return sn_test_send_emails( array( array( 'to' => 'someone@example.com', 'subject' => 'Sent after the Tools page forgot the table', 'message' => 'x' ) ) );", { label: 'sending an email after the table was forgotten' } );
		assert.deepEqual( sent.entries?.map( ( entry ) => [ entry.subject, entry.status ] ), [ [ 'Sent after the Tools page forgot the table', 'blocked' ] ], 'The next email did not create the table again and log itself' );
	} );

	test( 'SW2: Email Log > Settings shows Send emails off in a section of its own below the log switches, with its warning and the confirmation the script asks before switching it on', async () => {
		const settings = ( await site.get( SETTINGS, { jar: site.adminJar } ) ).text;
		const form = sendEmailsForm( settings );
		assert.deepEqual( form.checkboxes, [ ...LOG_SWITCHES, SEND_EMAILS ], 'The Settings form does not hold the two log switches followed by Send emails' );
		assert.deepEqual( form.input, { classes: [ 'safety-net-toggle', 'safety-net-send-emails-toggle' ], checked: false, disabled: false, confirm: form.input.confirm }, 'Send emails is not an enabled toggle that is off' );
		assert.equal( decode( form.input.confirm ?? '' ), CONFIRM, 'Send emails does not carry the confirmation the script asks' );
		assert.deepEqual( form.description, { classes: [ 'description', 'safety-net-warning' ], text: WARNING }, 'Send emails has no warning' );
		assert.equal( form.hidden, null, 'An enabled Send emails has a hidden field, which would post its stored value too' );
		// Recent WordPress versions give section headings an ID; older ones do not.
		const heading = form.html.search( /<h2(?: id="[^"]*")?>Sending emails<\/h2>/ );
		assert.ok( heading !== -1, 'Send emails has no section heading of its own' );
		assert.ok( LOG_SWITCHES.every( ( name ) => form.html.indexOf( `<input id="${ name }"` ) < heading ) && heading < form.html.indexOf( `<input id="${ SEND_EMAILS }"` ), 'Send emails is not in its own section below the log switches' );
		assert.match( form.html.slice( heading ), /^<h2(?: id="[^"]*")?>Sending emails<\/h2>\s*<table class="form-table" role="presentation">/, 'The Sending emails section has no table of its own' );

		await assertToolsAssets( site, settings, '/wp-content/plugins/safety-net/', 'the Email Log Settings page' );
		const script = settings.match( /<script[^>]+src=['"]([^'"]*assets\/js\/safety-net-admin\.js[^'"]*)['"]/ )[ 1 ];
		const style = settings.match( /<link[^>]+href=['"]([^'"]*assets\/css\/admin\.css[^'"]*)['"]/ )[ 1 ];
		const [ js, css ] = ( await site.getAll( [ script, style ].map( ( asset ) => asset.replace( /&#038;/g, '&' ) ) ) ).map( ( asset ) => asset.text );
		assert.match( css, /input\[type="checkbox"\]\.safety-net-send-emails-toggle:checked:after\s*\{[^}]*background-color: #d63638/, 'admin.css does not draw Send emails red when it is on' );
		assert.match( css, /p\.description\.safety-net-warning\s*\{[^}]*color: #b32d2e/, 'admin.css does not draw the warning red' );

		const page = runAdminScript( js );
		assert.ok( page.listeners.change?.length, 'The script listens to no change event, so switching Send emails on asks nothing' );
		const confirm = decode( form.input.confirm );
		assert.deepEqual( page.change( { checked: true, dataset: { safetyNetConfirm: confirm } }, false ), { asked: [ CONFIRM ], checked: false }, 'Cancelling the confirmation does not leave Send emails off' );
		assert.deepEqual( page.change( { checked: true, dataset: { safetyNetConfirm: confirm } }, true ), { asked: [ CONFIRM ], checked: true }, 'Confirming does not switch Send emails on' );
		assert.deepEqual( page.change( { checked: false, dataset: { safetyNetConfirm: confirm } }, false ), { asked: [], checked: false }, 'Switching Send emails off asks for a confirmation' );
		assert.deepEqual( page.change( { checked: true }, false ), { asked: [], checked: true }, 'A switch without a confirmation text asks one' );
	} );

	test( 'SW3: saving Send emails on through options.php lets every email through, at Safety Net\'s own priority, logs each as allowed_switch with the real result and in debug.log, sends core\'s change notifications and reports emails_enabled true', async () => {
		const saved = await saveSettings( true );
		assert.equal( saved.input.checked, true, 'Send emails does not show as on after saving it on' );
		assert.equal( await raw( SEND_EMAILS ), 'on' );
		assert.equal( await raw( SEND_EMAILS_SITE ), await homeKey(), 'Saving Send emails on did not bind it to this site\'s address' );
		assert.deepEqual( await site.php( "return array_map( 'sn_test_raw_option', array( 'safety_net_email_log_toggle', 'safety_net_email_log_cleanup_toggle' ) );" ), [ 'on', 'on' ], 'Logging is off, so the log checks below prove nothing' );

		const mark = site.logMark();
		const sent = await site.php( "return sn_test_send_emails( array( array( 'to' => 'customer1@example.com', 'subject' => 'Order receipt while emails are on', 'message' => 'x' ), array( 'to' => 'customer1@example.com', 'subject' => '[Site] Password Reset while emails are on', 'message' => 'x' ) ) );", { label: 'sending a regular email and a password reset to a customer' } );
		assert.deepEqual( sent.verdicts, [ null, null ], 'Safety Net did not let a regular email or a password reset to a customer through' );
		assert.deepEqual( sent.entries?.map( ( entry ) => [ entry.subject, entry.status, entry.outcome ] ), [ [ 'Order receipt while emails are on', 'allowed_switch', '' ], [ '[Site] Password Reset while emails are on', 'allowed_switch', '' ] ], 'The emails are not logged as let through because emails are switched on' );
		const lines = site.logEntriesSince( mark );
		assert.ok( lines.some( ( line ) => line.includes( 'Email sent: Order receipt while emails are on (emails are switched on)' ) ), 'debug.log does not say the email was sent because emails are switched on' );
		assert.deepEqual( lines.filter( ( line ) => /Email blocked: /.test( line ) ), [], 'debug.log says an email was blocked' );

		const results = await site.php( "return sn_test_send_for_results( array( array( 'to' => 'customer1@example.com', 'subject' => 'Order delivered while emails are on' ), array( 'to' => 'customer1@example.com', 'subject' => 'Order refused while emails are on', 'refuse' => 'sn-test: the mail server refused the email' ) ) );", { label: 'sending emails through to wp_mail()\'s result' } );
		assert.deepEqual( [ results.results.map( ( result ) => result.sent ), results.delivered ], [ [ true, false ], [ 'Order delivered while emails are on' ] ], 'wp_mail() did not deliver one email and fail the other, so the results prove nothing' );
		assert.deepEqual( results.entries?.map( ( entry ) => [ entry.subject, entry.status, entry.outcome, entry.error ] ), [
			[ 'Order delivered while emails are on', 'allowed_switch', 'sent', '' ],
			[ 'Order refused while emails are on', 'allowed_switch', 'failed', 'sn-test: the mail server refused the email' ],
		], 'An email let through because emails are switched on does not hold wp_mail()\'s real result' );

		const mail = await captureMail( site );
		assert.equal( mail.stop_emails_hook, 10, 'stop_emails is not hooked to pre_wp_mail at its own priority' );
		assert.deepEqual( mail.captured, { 'Order receipt': null, [ mail.reset_subject ]: null }, 'pre_wp_mail does not return null at Safety Net\'s priority while emails are switched on' );
		assert.deepEqual( await changeNotifications(), [ { subject: 'Password Changed', verdict: null }, { subject: 'Email Changed', verdict: null } ], 'Core did not send its password and email change notifications, or Safety Net blocked them' );
		assert.deepEqual( await rest(), { ...REST, emails_enabled: true } );

		const list = ( await site.get( PAGE, { jar: site.adminJar } ) ).text;
		const rows = [ ...list.matchAll( /<tr class="safety-net-email-log-row status-allowed_switch outcome-([a-z_]+)">([\s\S]*?)<\/tr>/g ) ].map( ( [ , outcome, row ] ) => [ outcome, row.match( /<td class='status column-status'[^>]*>([^<]*)<\/td>/ )?.[ 1 ] ] );
		assert.ok( rows.length >= 4, 'The Email Log does not list the emails let through because emails are switched on' );
		assert.deepEqual( [ ...new Set( rows.map( ( [ , label ] ) => label ) ) ], [ 'Let through: emails switched on' ], 'The Email Log does not label them' );
		assert.ok( rows.some( ( [ outcome ] ) => outcome === 'sent' ) && rows.some( ( [ outcome ] ) => outcome === 'failed' ), 'The Email Log does not show their results' );
	} );

	test( 'SW4: while emails are switched on, every wp-admin page warns users who can manage options, with where and how to turn them off, and users who cannot are not warned', async () => {
		await setSwitch( 'on' );
		const pages = [ '/wp-admin/', '/wp-admin/plugins.php', toolsPagePath(), PAGE, SETTINGS, '/wp-admin/profile.php', '/wp-admin/options-general.php' ];
		const loaded = await site.getAll( pages.map( ( target ) => ( { path: target, jar: site.adminJar } ) ) );
		for ( const [ index, res ] of loaded.entries() ) {
			const notices = emailsNotices( res.text );
			assert.equal( notices.length, 1, `${ pages[ index ] } does not warn exactly once that emails are switched on` );
			assert.deepEqual( notices[ 0 ], { ...NOTICE, link: { url: url( SETTINGS ), text: 'Email Log > Settings' }, command: COMMAND, how: `To stop it, turn off Send emails in Email Log > Settings, or run: ${ COMMAND }`, markup: [] }, `${ pages[ index ] } has the wrong warning` );
		}
		notice = emailsNotices( loaded[ 0 ].text )[ 0 ];
		assert.match( loaded[ 0 ].text, /<div class="notice notice-error safety-net-emails-enabled">/, 'The warning is not an error notice' );
		assert.doesNotMatch( loaded[ 0 ].text, /safety-net-emails-enabled[^"]*(?:is-dismissible|inline)/, 'The warning can be dismissed or is inline' );
		assert.ok( emailsNoticesHtml( loaded[ 0 ].text ).includes( 'Email Log &gt; Settings</a>' ), 'The warning\'s link text is not escaped' );

		await site.php( "update_option( 'sn_test_inject_urls', 1 ); return true;", { label: 'putting markup in the Settings page URL' } );
		try {
			const injected = ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text;
			const escaped = emailsNotices( injected );
			assert.equal( escaped.length, 1, 'Markup in the Settings page URL broke the warning apart' );
			assert.deepEqual( { ...escaped[ 0 ], link: null }, { ...NOTICE, link: null, command: COMMAND, how: `To stop it, turn off Send emails in Email Log > Settings, or run: ${ COMMAND }`, markup: [] }, 'Markup in the Settings page URL changed the warning' );
			assert.ok( escaped[ 0 ].link?.url.startsWith( `${ url( SETTINGS ) }&sn=` ) && ! /["<>]/.test( escaped[ 0 ].link.url ), `The Settings link is not the escaped URL: ${ escaped[ 0 ].link?.url }` );
			assert.doesNotMatch( emailsNoticesHtml( injected ), /<b>x<\/b>/, 'The warning prints the markup in the Settings page URL' );
		} finally {
			await site.php( "delete_option( 'sn_test_inject_urls' ); return true;", { label: 'removing the markup from the Settings page URL' } );
		}

		const editor = await site.php( "$id = sn_test_create_user( 'sn_switch_editor', 'editor' ); wp_set_password( 'password', $id ); return array( 'id' => $id, 'can' => user_can( $id, 'manage_options' ) );", { label: 'creating an editor' } );
		try {
			assert.equal( editor.can, false, 'The editor can manage options, so this proves nothing' );
			const jar = await site.login( { user: 'sn_switch_editor', jar: new CookieJar() } );
			for ( const res of await site.getAll( [ '/wp-admin/', '/wp-admin/profile.php' ].map( ( target ) => ( { path: target, jar } ) ) ) ) {
				assert.match( res.text, /id="adminmenu"/, 'The editor got no admin page, so this proves nothing' );
				assert.doesNotMatch( res.text, /safety-net-emails-enabled/, 'A user who cannot manage options is warned that emails are on' );
			}
		} finally {
			await site.php( `require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( ${ editor.id } ); return true;`, { label: 'deleting the editor' } );
		}

		await site.php( "update_option( 'sn_test_hide_admin', 1 ); return true;", { label: 'hiding Safety Net\'s admin pages' } );
		try {
			const hidden = await site.get( '/wp-admin/', { jar: site.adminJar } );
			assert.match( hidden.text, /Safety Net Activated/ );
			assert.deepEqual( emailsNotices( hidden.text ), [ { ...NOTICE, link: null, command: COMMAND, how: `To stop it, run: ${ COMMAND }`, markup: [] } ], 'With the admin pages hidden, the warning does not give only the WP-CLI command' );
		} finally {
			await site.php( "delete_option( 'sn_test_hide_admin' ); return true;", { label: 'showing Safety Net\'s admin pages again' } );
		}
	} );

	test( 'ST3: the panel follows changes, escaped: emails switched on, a reactivated denylisted plugin whose name holds markup, and logging and cleanup off', async () => {
		const seeded = await site.php( `
$file = WP_PLUGIN_DIR . '/${ MARKUP_PLUGIN.file }';
wp_mkdir_p( dirname( $file ) );
file_put_contents( $file, "<?php\\n/*\\n * Plugin Name: " . ${ JSON.stringify( MARKUP_PLUGIN.name ).replace( /\$/g, '\\$' ) } . "\\n */\\n" );
sn_test_activate_plugins( array( '${ MARKUP_PLUGIN.file }' ) );
update_option( '${ SEND_EMAILS }', 'on' );
update_option( 'safety_net_email_log_toggle', 'off' );
update_option( 'safety_net_email_log_cleanup_toggle', 'off' );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
return array( 'name' => get_plugin_data( $file, false, false )['Name'], 'entries' => count( (array) sn_test_email_log() ) );`, { label: 'reactivating a denylisted plugin named with markup, switching emails on and logging off' } );
		try {
			assert.equal( seeded.name, MARKUP_PLUGIN.name, 'The plugin\'s name is not read with its markup, so escaping it is not tested' );
			const { res } = await getToolsPage( site );
			assert.doesNotMatch( res.text, /<script>alert\(1\)<\/script>|<em>Denied<\/em>/, 'The plugin\'s name is printed unescaped' );
			const rows = statusRows( res.text );
			const byId = Object.fromEntries( rows.map( ( row ) => [ row.id, row ] ) );
			assert.deepEqual( statusSummary( [ byId.emails, byId.email_log, byId.plugins, byId.gateways ] ), [
				[ 'emails', 'danger', 'Switched on: sent to real addresses' ],
				[ 'email_log', 'warning', 'Off' ],
				[ 'plugins', 'danger', 'Active again' ],
				[ 'gateways', 'ok', 'Waiting for WooCommerce' ],
			] );
			assert.deepEqual( [ byId.emails.icon, byId.emails.severity ], ICONS.danger );
			assert.ok( byId.plugins.details.endsWith( `outside wp_mail(): ${ MARKUP_PLUGIN.name }.` ), `The plugins row does not name the reactivated plugin: ${ byId.plugins.details }` );
			assert.deepEqual( byId.plugins.link, { url: url( '/wp-admin/plugins.php' ), text: 'Plugins' } );
			assert.equal( byId.email_log.details, `New emails are not logged. The log holds ${ seeded.entries } entries. Old entries are not deleted.` );
			assert.deepEqual( byId.email_log.link, { url: url( SETTINGS ), text: 'Email Log Settings' }, 'The email log row does not link to where logging is turned back on' );
			assert.deepEqual( withoutIcons( rows ), await phpRows(), 'The panel does not show what get_status_rows() returns' );
		} finally {
			await site.php( `update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins' ), array( '${ MARKUP_PLUGIN.file }' ) ) ) ); wp_delete_file( WP_PLUGIN_DIR . '/${ MARKUP_PLUGIN.file }' ); delete_option( 'safety_net_email_log_toggle' ); delete_option( 'safety_net_email_log_cleanup_toggle' ); return true;`, { label: 'removing the plugin and switching logging back on' } );
		}
	} );

	test( 'SW5: Send to me still sends a copy to administrators only while emails are switched on, though other emails with the same extra recipient go through', async () => {
		await setSwitch( 'on' );
		const s = await site.php( `
global $wpdb;
$entry    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}safety_net_email_log WHERE subject = %s", 'Order receipt while emails are on' ) );
if ( ! $entry ) {
	return array( 'entry' => 0 );
}
$admin    = get_userdata( 1 )->user_email;
$verdicts = array();
$capture  = static function ( $verdict, $atts ) use ( &$verdicts ) {
	$verdicts[] = array( $atts['subject'], $verdict );
	return false;
};
$bcc      = static function ( $atts ) {
	$atts['headers'] = array_merge( array_filter( (array) $atts['headers'] ), array( 'Bcc: outsider@example.com' ) );
	return $atts;
};
$after = sn_test_email_log_last_id();
add_filter( 'pre_wp_mail', $capture, 11, 2 );
add_filter( 'wp_mail', $bcc );
wp_mail( 'customer1@example.com', 'Sent with an extra Bcc while emails are on', 'x' );
SafetyNet\\EmailLog\\send_copy( SafetyNet\\EmailLog\\get_entry( $entry ), $admin );
remove_filter( 'wp_mail', $bcc );
SafetyNet\\EmailLog\\send_copy( SafetyNet\\EmailLog\\get_entry( $entry ), $admin );
remove_filter( 'pre_wp_mail', $capture, 11 );
return array( 'on' => SafetyNet\\Utilities\\are_emails_enabled(), 'entry' => $entry, 'verdicts' => $verdicts, 'entries' => array_map( static fn( $row ) => array( $row['subject'], $row['status'] ), (array) sn_test_email_log( $after ) ) );`, { label: 'sending a copy with and without an extra Bcc while emails are switched on' } );
		assert.ok( s.entry > 0, 'SW3 logged no entry to copy' );
		assert.equal( s.on, true, 'Emails are not switched on in that request, so this proves nothing' );
		const copy = '[Safety Net copy] Order receipt while emails are on';
		assert.deepEqual( s.verdicts, [ [ 'Sent with an extra Bcc while emails are on', null ], [ copy, false ], [ copy, null ] ], 'A copy that also goes to someone else was let through, or the copy to the administrator alone was not' );
		assert.deepEqual( s.entries, [ [ 'Sent with an extra Bcc while emails are on', 'allowed_switch' ], [ copy, 'blocked' ] ], 'The blocked copy was not logged like an email with emails off, or the administrator\'s copy was logged' );
	} );

	test( 'SW6: saving Send emails off restores blocking, and so does the WP-CLI command the warning gives', async () => {
		const saved = await saveSettings( false );
		assert.equal( saved.input.checked, false, 'Send emails does not show as off after saving it off' );
		assert.equal( await raw( SEND_EMAILS ), 'off' );
		assert.equal( await raw( SEND_EMAILS_SITE ), null, 'Saving Send emails off kept the address it was bound to' );
		assertMailBlocked( await captureMail( site ) );
		assert.deepEqual( await changeNotifications(), [], 'Core sent a change notification after emails were switched off' );
		assert.deepEqual( await rest(), { ...REST, emails_enabled: false } );
		assert.doesNotMatch( ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text, /safety-net-emails-enabled/, 'The dashboard still warns that emails are on' );

		assert.ok( notice?.command, 'SW4 did not read the WP-CLI command from the warning' );
		const [ wp, ...args ] = notice.command.split( ' ' );
		assert.equal( wp, 'wp' );
		assert.equal( args.at( -1 ), 'off' );
		const on = await site.wp( [ ...args.slice( 0, -1 ), 'on' ] );
		assert.equal( on.exitCode, 0, `Switching emails on with WP-CLI failed: ${ on.stderr }` );
		assert.match( on.stdout, /Updated 'safety_net_send_emails_toggle' option/ );
		assert.equal( await raw( SEND_EMAILS_SITE ), await homeKey(), 'Switching emails on with WP-CLI did not bind them to this site\'s address' );
		assert.equal( ( await rest() ).emails_enabled, true, 'Emails switched on with WP-CLI are not on in a web request, so the command proves nothing' );
		const cli = await site.wp( args );
		assert.equal( cli.exitCode, 0, `${ notice.command } failed: ${ cli.stderr }` );
		assert.equal( await raw( SEND_EMAILS ), 'off' );
		assert.equal( await raw( SEND_EMAILS_SITE ), null, 'Switching emails off with WP-CLI kept the address they were bound to' );
		assert.equal( ( await rest() ).emails_enabled, false );
		assertMailBlocked( await captureMail( site ) );
	} );

	test( 'SW7: saving the Tools form leaves Send emails alone', async () => {
		await setSwitch( 'on' );
		try {
			await saveToolsForm( site );
			assert.notEqual( await raw( 'safety_net_pause_renewal_actions_toggle' ), 'on', 'Saving the Tools form did not turn its unticked renewal pause off, so the form may not have been saved' );
			assert.equal( await raw( SEND_EMAILS ), 'on', 'Saving the Tools form, which holds no Send emails switch, changed it' );
		} finally {
			await site.php( `update_option( 'safety_net_pause_renewal_actions_toggle', 'on' ); delete_option( '${ SEND_EMAILS }' ); return true;`, { label: 'turning the renewal pause back on and Send emails back to its default' } );
		}
	} );

	test( 'SW8: while emails are switched on, an earlier pre_wp_mail callback\'s decision stands: another plugin\'s block is not lifted, and nothing is logged as let through', async () => {
		await setSwitch( 'on' );
		try {
			const mark = site.logMark();
			const s = await site.php( `
add_filter( 'pre_wp_mail', '__return_false', 5 );
try {
	return array( 'on' => SafetyNet\\Utilities\\are_emails_enabled(), 'sent' => sn_test_send_emails( array( array( 'to' => 'customer1@example.com', 'subject' => 'Blocked by another plugin while emails are on', 'message' => 'x' ) ) ) );
} finally {
	remove_filter( 'pre_wp_mail', '__return_false', 5 );
}`, { label: 'sending an email that an earlier pre_wp_mail callback blocks while emails are switched on' } );
			assert.equal( s.on, true, 'Emails are not switched on in that request, so this proves nothing' );
			assert.deepEqual( s.sent.verdicts, [ false ], 'Safety Net lifted another plugin\'s block because emails are switched on' );
			assert.deepEqual( ( s.sent.entries ?? [] ).filter( ( entry ) => entry.status === 'allowed_switch' ), [], 'An email another plugin blocked is logged as let through' );
			assert.deepEqual( site.logEntriesSince( mark ).filter( ( line ) => line.includes( 'Email sent: Blocked by another plugin' ) ), [], 'debug.log says an email another plugin blocked was sent' );
		} finally {
			await setSwitch( null );
		}
	} );

	test( 'SW9: with Send emails on and no constant keeping data, emails stay blocked while data the keep step kept is still waiting to be deleted', async () => {
		await setSwitch( 'on' );
		try {
			const s = await site.php( `
add_filter( 'pre_option_safety_net_data_kept', static fn() => '1' );
add_filter( 'pre_option_safety_net_data_deleted', '__return_zero' );
return array(
	'kept'    => SafetyNet\\Utilities\\is_data_deletion_disabled(),
	'switch'  => SafetyNet\\Utilities\\is_send_emails_switch_on(),
	'enabled' => SafetyNet\\Utilities\\are_emails_enabled(),
	'sent'    => sn_test_send_emails( array( array( 'to' => 'customer1@example.com', 'subject' => 'Sent while kept data waits to be deleted', 'message' => 'x' ) ) ),
);`, { label: 'sending an email while kept data waits to be deleted' } );
			assert.deepEqual( [ s.kept, s.switch ], [ false, true ], 'The constants keep data or Send emails is off, so this proves nothing' );
			assert.equal( s.enabled, false, 'Emails are switched on while kept data is still there' );
			assert.deepEqual( s.sent.verdicts, [ false ], 'An email went out while kept data is still there' );
			assert.deepEqual( s.sent.entries?.map( ( entry ) => [ entry.subject, entry.status ] ), [ [ 'Sent while kept data waits to be deleted', 'blocked' ] ] );
		} finally {
			await setSwitch( null );
		}
	} );

	test( 'SW10: on a copy, simulated by storing another home option, a stored on is off everywhere and the Settings page and panel say why; switching it on there works, and back at the first address it is off again', async () => {
		await setSwitch( 'on' );
		const home = await raw( 'home' );
		try {
			assert.equal( await raw( SEND_EMAILS_SITE ), await homeKey(), 'update_option() did not bind Send emails to this site\'s address' );
			assert.equal( ( await rest() ).emails_enabled, true, 'Emails are not on before the copy, so this proves nothing' );
			try {
				assert.equal( await setStoredHome( site, COPY_HOME ), true, 'WP_HOME is not defined, so the site would not load at the copy\'s address' );
				assert.deepEqual( [ await raw( 'home' ), await raw( SEND_EMAILS ), await switchState() ], [ COPY_HOME, 'on', 'other_site' ], 'The copy does not keep the stored on with another address, so this proves nothing' );
				assertMailBlocked( await captureMail( site ) );
				assert.deepEqual( await changeNotifications(), [], 'Core sent a change notification on the copy' );
				assert.deepEqual( await rest(), { ...REST, emails_enabled: false } );
				const [ dashboard, settings, tools ] = await site.getAll( [ '/wp-admin/', SETTINGS, toolsPagePath() ].map( ( target ) => ( { path: target, jar: site.adminJar } ) ) );
				assert.match( dashboard.text, /Safety Net Activated/, 'The dashboard has no Safety Net notice, so this proves nothing' );
				for ( const res of [ dashboard, settings, tools ] ) {
					assert.doesNotMatch( res.text, /safety-net-emails-enabled/, 'A page on the copy warns that emails are on' );
				}
				const form = sendEmailsForm( settings.text );
				assert.deepEqual( [ form.input.checked, form.input.disabled, form.hidden, decode( form.input.confirm ?? '' ) ], [ false, false, null, CONFIRM ], 'Send emails does not show as an enabled switch that is off on the copy' );
				assert.deepEqual( form.description, { classes: [ 'description', 'safety-net-warning' ], text: `${ COPY_NOTE } ${ WARNING }` }, 'Send emails does not say it was switched on for another address' );
				const emails = statusRows( tools.text ).find( ( row ) => row.id === 'emails' );
				assert.deepEqual( [ emails.state, emails.status, emails.details ], [ 'ok', 'Blocked, except password resets', `${ BLOCKED } ${ COPY_NOTE }` ], 'The emails row does not say Send emails was switched on for another address' );

				// WP-CLI skips a value it reads as unchanged, which the README tells people to work around with off first.
				const unchanged = await site.wp( [ 'option', 'update', SEND_EMAILS, 'on' ] );
				assert.deepEqual( [ unchanged.exitCode, /unchanged/.test( unchanged.stdout ) ], [ 0, true ], `WP-CLI did not skip on over a stored on: ${ unchanged.stdout } ${ unchanged.stderr }` );
				assert.equal( await switchState(), 'other_site', 'A skipped WP-CLI update bound the switch to the copy' );

				const saved = await saveSettings( true );
				assert.deepEqual( [ saved.input.checked, saved.description.text ], [ true, WARNING ], 'Switching Send emails on again on the copy does not show it on' );
				assert.equal( await raw( SEND_EMAILS_SITE ), siteKey( COPY_HOME, site.siteUrl ), 'Switching Send emails on again on the copy did not bind it to the copy\'s address' );
				assert.equal( ( await rest() ).emails_enabled, true );
				const mail = await captureMail( site );
				assert.deepEqual( mail.captured, { 'Order receipt': null, [ mail.reset_subject ]: null }, 'Emails are not let through on the copy after switching them on there' );
				assert.equal( emailsNotices( ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text ).length, 1, 'The copy does not warn that emails are on after switching them on there' );
			} finally {
				await setStoredHome( site, home );
			}
			assert.equal( await raw( 'home' ), home );
			assert.deepEqual( [ await switchState(), ( await rest() ).emails_enabled ], [ 'other_site', false ], 'Back at the first address, the switch bound to the copy still sends emails' );
		} finally {
			await setSwitch( null );
		}
		assert.deepEqual( [ await raw( SEND_EMAILS ), await raw( SEND_EMAILS_SITE ) ], [ null, null ], 'Deleting Send emails kept the address it was bound to' );
	} );

	test( 'SW11: a stored on without an address, written straight to the database, is off everywhere, and saving on again with update_option() binds it', async () => {
		await site.php( `global $wpdb; $wpdb->insert( $wpdb->options, array( 'option_name' => '${ SEND_EMAILS }', 'option_value' => 'on', 'autoload' => 'yes' ) ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); return true;`, { label: 'writing Send emails on straight to the database' } );
		try {
			assert.deepEqual( [ await raw( SEND_EMAILS ), await raw( SEND_EMAILS_SITE ), await switchState() ], [ 'on', null, 'no_site' ], 'Send emails is not stored on without an address, so this proves nothing' );
			assertMailBlocked( await captureMail( site ) );
			assert.deepEqual( await rest(), { ...REST, emails_enabled: false } );
			const [ dashboard, settings, tools ] = await site.getAll( [ '/wp-admin/', SETTINGS, toolsPagePath() ].map( ( target ) => ( { path: target, jar: site.adminJar } ) ) );
			assert.doesNotMatch( dashboard.text, /safety-net-emails-enabled/, 'The dashboard warns that emails are on' );
			const form = sendEmailsForm( settings.text );
			assert.deepEqual( [ form.input.checked, form.description.text ], [ false, `${ NO_SITE_NOTE } ${ WARNING }` ], 'Send emails does not show as off, saying why' );
			assert.equal( statusRows( tools.text ).find( ( row ) => row.id === 'emails' ).details, `${ BLOCKED } ${ NO_SITE_NOTE }` );

			await setSwitch( 'on' );
			assert.deepEqual( [ await raw( SEND_EMAILS_SITE ), ( await rest() ).emails_enabled ], [ await homeKey(), true ], 'update_option() with the stored value did not bind Send emails to this site' );
		} finally {
			await setSwitch( null );
		}
	} );

	test( 'ST8: the emails row says emails are not blocked when something removes Safety Net\'s mail filter, and warns when a plugin replaces wp_mail()', async () => {
		const removed = ( await phpRows( "remove_filter( 'pre_wp_mail', 'SafetyNet\\Admin\\stop_emails', 10 );" ) ).find( ( row ) => row.id === 'emails' );
		assert.deepEqual( removed, { id: 'emails', label: 'Emails', state: 'danger', status: 'Not blocked', details: 'Something removed Safety Net\'s mail filter, so emails sent through wp_mail() reach their recipients and are not logged.', link: null } );

		await site.php( "update_option( 'sn_test_replace_wp_mail', 1 ); return true;", { label: 'replacing wp_mail() from an mu-plugin' } );
		try {
			const s = await site.php( "return array( 'file' => ( new ReflectionFunction( 'wp_mail' ) )->getFileName(), 'rows' => SafetyNet\\Status\\get_status_rows() );", { label: 'building the status rows with wp_mail() replaced' } );
			assert.match( s.file, /\/mu-plugins\/00-sn-test-helper\.php$/, 'wp_mail() is not the helper\'s, so this proves nothing' );
			assert.deepEqual( s.rows.find( ( row ) => row.id === 'emails' ), { id: 'emails', label: 'Emails', state: 'warning', status: 'wp_mail() is replaced', details: 'wp_mail() is defined in wp-content/mu-plugins/00-sn-test-helper.php instead of WordPress. Safety Net blocks and logs its emails only if it applies the pre_wp_mail filter.', link: null } );
		} finally {
			await site.php( "delete_option( 'sn_test_replace_wp_mail' ); return true;", { label: 'putting WordPress\'s wp_mail() back' } );
		}
		assert.deepEqual( statusSummary( ( await phpRows() ).filter( ( row ) => row.id === 'emails' ) ), [ [ 'emails', 'ok', 'Blocked, except password resets' ] ] );
	} );

	test( 'ST11: where WordPress offers application passwords, the panel counts the administrators who have one, without WooCommerce, leaving out other users and odd values, and links to Users', async () => {
		const unavailable = await site.php( "return array( 'available' => wp_is_application_passwords_available(), 'rows' => SafetyNet\\Status\\get_status_rows() );", { label: 'building the status rows without application passwords' } );
		assert.equal( unavailable.available, false, 'WordPress offers application passwords over plain HTTP, so this does not test their absence' );
		assert.deepEqual( unavailable.rows.filter( ( row ) => [ 'app_passwords', 'api_keys' ].includes( row.id ) ), [], 'The panel has an application passwords row while WordPress does not offer them' );

		await site.php( "update_option( 'sn_test_application_passwords', 1 ); return true;", { label: 'offering application passwords over HTTP' } );
		const mark = site.logMark();
		const users = await site.php( `
$ids = array(
	'admin2'     => sn_test_create_user( 'sn_app_admin2', 'administrator' ),
	'admin3'     => sn_test_create_user( 'sn_app_admin3', 'administrator' ),
	'subscriber' => sn_test_create_user( 'sn_app_subscriber', 'subscriber' ),
);
return $ids;`, { label: 'creating two more administrators and a subscriber' } );
		const create = ( id ) => `$created = WP_Application_Passwords::create_new_application_password( ${ id }, array( 'name' => 'SN status ' . ${ id } ) ); if ( is_wp_error( $created ) ) { throw new RuntimeException( $created->get_error_message() ); }`;
		const rowsNow = async () => {
			const rows = await phpRows();
			return { rows, app: rows.find( ( row ) => row.id === 'app_passwords' ) };
		};
		try {
			const none = await rowsNow();
			assert.deepEqual( statusSummary( none.rows ).map( ( [ id ] ) => id ), [ 'environment', 'emails', 'email_log', 'data', 'options', 'plugins', 'gateways', 'app_passwords', 'search_engines', 'transients' ], 'The application passwords row is missing or out of place' );
			assert.deepEqual( none.app, { id: 'app_passwords', label: 'Application passwords', state: 'ok', status: 'None', details: 'No administrator has an application password.', link: null } );

			await site.php( `${ create( users.subscriber ) } ${ create( 1 ) } update_user_meta( ${ users.admin2 }, '_application_passwords', 'not an array' ); update_user_meta( ${ users.admin3 }, '_application_passwords', array( 'x', array( 'name' => 'no password' ), array( 'password' => '' ), array( 'password' => array( 'x' ) ) ) ); return true;`, { label: 'creating application passwords for a subscriber and the first administrator, and odd values for the other two' } );
			const one = await rowsNow();
			assert.deepEqual( one.app, { id: 'app_passwords', label: 'Application passwords', state: 'warning', status: '1 administrator', details: '1 administrator has application passwords that still let apps use the REST API.', link: { url: url( '/wp-admin/users.php' ), text: 'Users' } }, 'The row does not count the one administrator with a real application password' );

			await site.php( `delete_user_meta( ${ users.admin2 }, '_application_passwords' ); ${ create( users.admin2 ) } ${ create( users.admin2 ) } return true;`, { label: 'creating two application passwords for the second administrator' } );
			const { res } = await getToolsPage( site );
			const app = statusRows( res.text ).find( ( row ) => row.id === 'app_passwords' );
			assert.deepEqual( [ app.state, app.status, app.details, app.icon, app.link ], [ 'warning', '2 administrators', '2 administrators have application passwords that still let apps use the REST API.', ICONS.warning[ 0 ], { url: url( '/wp-admin/users.php' ), text: 'Users' } ], 'The panel does not count administrators, rather than passwords' );
			assert.deepEqual( site.logEntriesSince( mark ).filter( ( line ) => /WordPress database error|PHP (?:Warning|Notice|Deprecated)/.test( line ) ), [], 'Counting application passwords with odd values logged a warning or database error' );
		} finally {
			await site.php( `require_once ABSPATH . 'wp-admin/includes/user.php'; WP_Application_Passwords::delete_all_application_passwords( 1 ); foreach ( json_decode( '${ JSON.stringify( Object.values( users ) ) }', true ) as $id ) { wp_delete_user( $id ); } delete_option( 'sn_test_application_passwords' ); return true;`, { label: 'deleting the application passwords and users' } );
		}
		assert.equal( ( await rowsNow() ).app, undefined, 'The application passwords row stays after WordPress stopped offering them' );
	} );

	// Last, since another PHP worker may keep seeing the robots.txt file after it is deleted.
	test( 'ST7: the search engines row warns when the "Discourage search engines" setting is not in force, and when a static robots.txt is served instead of Safety Net\'s', async () => {
		const s = await site.php( "remove_filter( 'option_blog_public', '__return_zero' ); return array( 'blog_public' => get_option( 'blog_public' ), 'row' => SafetyNet\\Status\\search_engines_row() );", { label: 'building the search engines row without Safety Net\'s blog_public filter' } );
		assert.equal( String( s.blog_public ), '1', 'The site is stored as discouraging search engines, so this proves nothing' );
		assert.deepEqual( [ s.row.state, s.row.status ], [ 'warning', 'Not discouraged' ], 'The search engines row does not warn while the setting lets search engines index the site' );

		const robots = await site.php( "$file = ABSPATH . 'robots.txt'; file_put_contents( $file, \"User-agent: *\\nAllow: /\\n\" ); try { return SafetyNet\\Status\\search_engines_row(); } finally { unlink( $file ); }", { label: 'building the search engines row with a static robots.txt' } );
		assert.deepEqual( [ robots.state, robots.status, robots.details ], [ 'warning', 'Static robots.txt', 'The web server serves the robots.txt file in the site\'s folder instead of Safety Net\'s, though pages still ask search engines not to index them.' ] );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
