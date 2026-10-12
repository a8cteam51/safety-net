import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { after, before, describe, test } from 'node:test';
import { firstLoad, getToolsPage, noncesForSession, postAjax, saveToolsForm, toolsPagePath } from '../lib/checks.mjs';
import { CookieJar } from '../lib/http.mjs';
import { bootSite } from '../lib/site.mjs';

const TAB = `${ toolsPagePath() }&tab=email-log`;

const ADMIN_POST = '/wp-admin/admin-post.php';

const TOGGLES = [ 'safety_net_email_log_toggle', 'safety_net_email_log_cleanup_toggle' ];

const NO_LOG = { table: false, options: {}, cleanup: null };

const PLAIN = 'text/plain; charset=UTF-8';

const LABELS = { blocked: 'Blocked', blocked_recipients: 'Blocked: not only administrators', allowed: 'Let through' };

const CSP = `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:">`;

const ORDER = {
	to: [ 'one@example.com', 'Two <two@example.com>' ],
	subject: 'Order receipt #1',
	message: 'Thanks for order <b>#1</b> & welcome &amp; more.\nSecond line',
	headers: 'Cc: cc@example.com\r\nBcc: bcc@example.com\r\nReply-To: Shop <reply@example.com>\r\n',
	// JSON puts the numeric key first, so PHP gets it first too.
	attachments: { 'invoice-1.pdf': '/tmp/sn-mail/a1b2c3.pdf', 1: '/var/www/private/report.csv' },
};

const RESET = {
	to: 'someone@example.com',
	subject: '[My WordPress Website] Password Reset',
	message: 'Reset your password: https://example.test/wp-login.php?action=rp&key=SECRETKEY1&login=someone',
	headers: [ 'X-SN-Test: reset' ],
	attachments: '/tmp/sn-mail/one.txt\n/tmp/sn-mail/two.txt',
};

const PAIR = { to: 'a@example.com, b@example.com', subject: 'Two recipients <script>alert(1)</script>' };

const WOO = {
	to: 'customer@example.com',
	subject: 'Your Example Shop account has been created!',
	message: '<p>Hi &lt;b&gt;customer&lt;/b&gt; &amp; welcome "friend"</p><script>document.title = "pwned";</script><img src="https://tracker.example/pixel.gif" alt=""><a href="https://shop.example/my-account/lost-password/?action=newaccount&#038;key=WOOKEY1&#038;login=customer">Set your new password</a>',
	headers: 'Reply-to: Example Shop <shop@example.com>\r\nCc: manager@example.com\r\nBcc: archive@example.com\r\n',
	attachments: [ '/wordpress/wp-content/uploads/terms.pdf' ],
	woocommerce: { from: 'shop@example.com', name: 'Example Shop', type: 'text/html' },
};

// WooCommerce's "Multipart" format: the message is HTML, and WooCommerce adds the plain-text part only inside PHPMailer.
const WOO_MULTIPART = {
	to: 'customer@example.com',
	subject: 'Your Example Shop order is complete',
	message: '<p>Your order &amp; its <b>items</b> are on the way.</p><script>document.title = "pwned";</script>',
	headers: 'Content-Type: multipart/alternative\r\nReply-to: Example Shop <shop@example.com>\r\n',
	woocommerce: { from: 'shop@example.com', name: 'Example Shop', type: 'multipart/alternative' },
};

const HTML_COPY = 'text/html; charset=UTF-8';

const REFUSED_CREATE = /for query CREATE TABLE sn_test_refused_create \(/;

const COULD_NOT_RECORD = 'Safety Net: the email log could not record an email: ';

// For json_decode() in a single-quoted PHP string.
const phpValue = ( value ) => `json_decode( '${ JSON.stringify( value ).replace( /[\\']/g, '\\$&' ) }', true )`;

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };

// Decodes once, as a browser does with text and attribute values.
const decode = ( html ) => html.replace( /&(?:#(\d+)|#x([\da-f]+)|([a-z]+));/gi, ( entity, dec, hex, name ) => {
	if ( dec || hex ) {
		return String.fromCodePoint( dec ? Number( dec ) : parseInt( hex, 16 ) );
	}
	return ENTITIES[ name.toLowerCase() ] ?? entity;
} );

const withoutIdAndDate = ( { id, logged_at: loggedAt, ...entry } ) => entry;

const entryPath = ( id ) => `${ TAB }&entry=${ id }`;

const utcTime = ( loggedAt ) => Date.parse( `${ loggedAt.replace( ' ', 'T' ) }Z` );

function assertLoggedNow( entries ) {
	for ( const { subject, logged_at: loggedAt } of entries ) {
		const age = Date.now() - utcTime( loggedAt );
		assert.ok( age > -60_000 && age < 600_000, `"${ subject }" was logged at ${ loggedAt }, which is not the current UTC time` );
	}
}

// The scenario's date and time formats (Y-m-d H:i) in Asia/Tokyo, which is UTC+9 all year.
const tokyoTime = ( loggedAt ) => new Date( utcTime( loggedAt ) + 9 * 3_600_000 ).toISOString().slice( 0, 16 ).replace( 'T', ' ' );

const forms = ( html ) => [ ...html.matchAll( /<form\b[\s\S]*?<\/form>/g ) ].map( ( [ form ] ) => form );

function hiddenFields( form ) {
	const fields = {};
	for ( const [ input ] of form.matchAll( /<input\b[^>]*>/g ) ) {
		const name = input.match( /\sname=["']([^"']*)["']/ )?.[ 1 ];
		if ( name && /\stype=["']hidden["']/.test( input ) ) {
			fields[ name ] = decode( input.match( /\svalue=["']([^"']*)["']/ )?.[ 1 ] ?? '' );
		}
	}
	return fields;
}

function actionForm( html, action ) {
	const form = forms( html ).find( ( candidate ) => hiddenFields( candidate ).action === action );
	assert.ok( form, `The page has no form for ${ action }` );
	assert.match( form.match( /^<form\b[^>]*>/ )[ 0 ], /\smethod="post"[^>]*\saction="[^"]*\/wp-admin\/admin-post\.php"/, `The ${ action } form does not post to admin-post.php` );
	return { html: form, fields: hiddenFields( form ) };
}

function settingsForm( html ) {
	const form = forms( html ).find( ( candidate ) => hiddenFields( candidate ).option_page === 'safety-net-email-log' );
	assert.ok( form, 'The Email log tab has no form for the safety-net-email-log settings group' );
	return {
		fields: hiddenFields( form ),
		action: form.match( /^<form\b[^>]*\saction="([^"]*)"/ )?.[ 1 ],
		checkboxes: [ ...form.matchAll( /<input\b[^>]*type="checkbox"[^>]*>/g ) ].map( ( [ input ] ) => input.match( /\sname="([^"]+)"/ )?.[ 1 ] ),
	};
}

function toggles( html ) {
	return Object.fromEntries( TOGGLES.map( ( name ) => {
		const input = html.match( new RegExp( `<input id="${ name }"[^>]*>` ) )?.[ 0 ];
		assert.ok( input, `The Email log tab has no ${ name } checkbox` );
		return [ name, /\schecked=/.test( input ) ];
	} ) );
}

function listedEntries( html ) {
	return [ ...html.matchAll( /<tr class="safety-net-email-log-row status-([a-z_]+)">([\s\S]*?)<\/tr>/g ) ].map( ( [ , status, row ] ) => {
		const cells = [ ...row.matchAll( /<td>([\s\S]*?)<\/td>/g ) ].map( ( [ , cell ] ) => cell.trim() );
		return { id: cells[ 4 ]?.match( /[?&;]entry=(\d+)/ )?.[ 1 ], status, cells: cells.slice( 0, 4 ) };
	} );
}

const detail = ( html, label ) => html.match( new RegExp( `<th scope="row">${ label }</th>\\s*<td>([\\s\\S]*?)</td>` ) )?.[ 1 ].trim();

const notice = ( html ) => {
	const match = html.match( /<div class="notice notice-(\w+) safety-net-email-log-result"><p>([\s\S]*?)<\/p><\/div>/ );
	return match ? `${ match[ 1 ] }: ${ decode( match[ 2 ] ) }` : null;
};

function redirectArgs( site, res ) {
	assert.ok( res.location, `The action did not redirect (HTTP ${ res.status })` );
	const url = new URL( res.location, site.url );
	assert.equal( url.pathname, '/wp-admin/tools.php', `The action redirected to ${ res.location }` );
	return Object.fromEntries( url.searchParams );
}

function capturedMail( site ) {
	const file = path.join( site.outDir, 'mail.jsonl' );
	return existsSync( file ) ? readFileSync( file, 'utf8' ).split( '\n' ).filter( Boolean ).map( ( line ) => JSON.parse( line ) ) : [];
}

describe( 'email-log: the email log of a regular plugin on a staging site', () => {
	let site;
	let admin;
	let defaultFrom;

	const log = ( after = 0 ) => site.php( `return sn_test_email_log( ${ after } );`, { label: 'reading the email log' } );
	const state = () => site.php( 'return sn_test_email_log_state();', { label: 'reading the email log\'s table, options and cleanup event' } );
	const send = ( emails, label = 'sending emails' ) => site.php( `return sn_test_send_emails( ${ phpValue( emails ) } );`, { label } );
	const capture = ( value ) => site.php( value ? `update_option( 'sn_test_capture_mail', ${ phpValue( value ) } ); return true;` : "delete_option( 'sn_test_capture_mail' ); return true;", { label: value ? 'capturing email in every request' : 'no longer capturing email' } );
	const seedAged = ( ages ) => site.php( `
global $wpdb;
$ids = array();
foreach ( ${ phpValue( ages ) } as $subject => $age ) {
	$wpdb->insert( $wpdb->prefix . 'safety_net_email_log', array( 'logged_at' => gmdate( 'Y-m-d H:i:s', strtotime( $age ) ), 'status' => 'blocked', 'recipients' => 'aged@example.com', 'subject' => $subject, 'message' => '', 'headers' => '', 'from_address' => '', 'content_type' => '', 'attachments' => '' ) );
	$ids[ $subject ] = $wpdb->insert_id;
}
return $ids;`, { label: 'adding entries of different ages' } );
	const aged = async () => ( await log() ).filter( ( entry ) => entry.recipients === 'aged@example.com' ).map( ( entry ) => entry.subject );
	const safetyNetDbErrors = ( mark ) => site.logEntriesSince( mark ).filter( ( line ) => /WordPress database error/.test( line ) && /SafetyNet\\/.test( line ) );

	async function entryBySubject( subject ) {
		const entry = ( await log() ).find( ( candidate ) => candidate.subject === subject );
		assert.ok( entry, `No log entry has the subject "${ subject }"` );
		return entry;
	}

	before( async () => {
		site = await bootSite( { name: 'email-log', env: 'staging', mode: 'plugin' } );
		await site.php( 'return sn_test_seed_base();', { label: 'seeding the site' } );
		admin = await site.php( 'return get_userdata( 1 )->user_email;', { label: 'reading the administrator\'s email address' } );
		defaultFrom = `WordPress <wordpress@${ new URL( site.siteUrl ).hostname }>`;
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'E1: the first page load runs the automatic pass and creates no email log, since no email was sent', async () => {
		await firstLoad( site );
		await site.login();
		const s = await site.php( "return array( 'state' => sn_test_email_log_state(), 'hooked' => has_filter( 'pre_wp_mail', 'SafetyNet\\\\Admin\\\\stop_emails' ), 'loaded' => function_exists( 'SafetyNet\\\\EmailLog\\\\log_email' ) );" );
		assert.equal( s.hooked, 10, 'stop_emails is not hooked to pre_wp_mail' );
		assert.equal( s.loaded, true, 'The email log is not loaded, so this proves nothing' );
		assert.deepEqual( s.state, NO_LOG, 'The email log table, one of its options or the cleanup event exists before any email was sent' );
	} );

	test( 'E2: with logging switched off, emails are still blocked or let through, and no table, option or cleanup event is created', async () => {
		await site.php( "update_option( 'safety_net_email_log_toggle', 'off' ); return true;", { label: 'switching logging off' } );
		try {
			const sent = await send( [ { to: 'someone@example.com', subject: 'Sent while logging is off', message: 'x' }, { ...RESET, message: 'x' } ] );
			assert.deepEqual( sent, { verdicts: [ false, null ], entries: null }, 'Safety Net did not block the regular email and let the password reset through (false, null), or the log table was created' );
			assert.deepEqual( await state(), { ...NO_LOG, options: { safety_net_email_log_toggle: 'off' } }, 'Logging switched off created the table, its version option or the cleanup event' );
			const tab = await site.get( TAB, { jar: site.adminJar } );
			assert.deepEqual( toggles( tab.text ), { safety_net_email_log_toggle: false, safety_net_email_log_cleanup_toggle: true } );
			assert.match( tab.text, /No emails have been logged\./, 'The Email log tab without a table does not say the log is empty' );
		} finally {
			await site.php( "delete_option( 'safety_net_email_log_toggle' ); return true;", { label: 'switching logging back to its default' } );
		}
	} );

	test( 'E2b: when the database refuses to create the table, emails are still blocked or let through, the table is tried once per request, and nothing is created', async () => {
		await site.php( "update_option( 'sn_test_refuse_email_log_table', 1 ); return true;", { label: 'refusing to create the email log table from the next load on' } );
		const mark = site.logMark();
		let sent;
		try {
			// Removed in the load that sends them, which keeps refusing for the rest of that load.
			sent = await site.php( `delete_option( 'sn_test_refuse_email_log_table' ); return sn_test_send_emails( ${ phpValue( [ { to: 'someone@example.com', subject: 'Sent while the table cannot be created', message: 'x' }, { to: 'someone@example.com', subject: RESET.subject, message: 'x' }, { to: 'someone@example.com', subject: 'Also sent while the table cannot be created', message: 'x' } ] ) } );`, { label: 'sending emails while the database refuses to create the table' } );
		} finally {
			if ( ! sent ) {
				await site.php( "delete_option( 'sn_test_refuse_email_log_table' ); return true;" );
			}
		}
		assert.deepEqual( sent, { verdicts: [ false, null, false ], entries: null }, 'A refused table changed Safety Net\'s decisions, or a table was created' );
		assert.equal( site.logEntriesSince( mark ).filter( ( line ) => REFUSED_CREATE.test( line ) ).length, 1, 'The table was not tried exactly once in the load that sent three emails' );
		assert.deepEqual( await state(), NO_LOG, 'A refused table left the table, its version option or the cleanup event' );
	} );

	test( 'E3: an email sent before init can be the first one, and creates the log with its entry', async () => {
		await site.php( "update_option( 'sn_test_early_mail', 1 ); return true;", { label: 'sending emails on plugins_loaded from the next load on' } );
		let s;
		try {
			// Removed in the load that sends them, so later loads do not send them again.
			s = await site.php( "delete_option( 'sn_test_early_mail' ); return array( 'early' => $GLOBALS['sn_test_early_mail'] ?? null, 'entries' => sn_test_email_log(), 'state' => sn_test_email_log_state() );", { label: 'sending emails on plugins_loaded' } );
		} finally {
			if ( ! s ) {
				await site.php( "delete_option( 'sn_test_early_mail' ); return true;" );
			}
		}
		assert.deepEqual( s.early, { 'Early order receipt': false, 'Early Password Reset': null }, 'The emails sent on plugins_loaded did not get Safety Net\'s verdicts, so this proves nothing' );
		assert.ok( Array.isArray( s.entries ), 'The first email, sent before init, did not create the log table' );
		const early = { recipients: 'someone@example.com', message: 'body', headers: '', from_address: defaultFrom, content_type: PLAIN, attachments: '' };
		assert.deepEqual( s.entries.map( withoutIdAndDate ), [ { status: 'blocked', ...early, subject: 'Early order receipt' }, { status: 'allowed', ...early, subject: 'Early Password Reset' } ] );
		assertLoggedNow( s.entries );
		assert.deepEqual( s.state, { table: true, options: { safety_net_email_log_db_version: '1' }, cleanup: { due: true, schedule: 'daily' } }, 'The first email did not record the table version, or init did not schedule the daily cleanup once the table existed' );
	} );

	test( 'E3b: a sender filter that throws while an email is logged changes no decision, logs no entry and is reported in debug.log', async () => {
		const mark = site.logMark();
		const sent = await site.php( `$throw = static function () { throw new RuntimeException( 'sn-test sender filter failed' ); }; add_filter( 'wp_mail_from', $throw ); try { return sn_test_send_emails( ${ phpValue( [ { to: 'someone@example.com', subject: 'Sent while a sender filter throws', message: 'x' }, { to: 'someone@example.com', subject: RESET.subject, message: 'x' } ] ) } ); } finally { remove_filter( 'wp_mail_from', $throw ); }`, { label: 'sending emails while a wp_mail_from filter throws' } );
		assert.deepEqual( sent, { verdicts: [ false, null ], entries: [] }, 'A throwing filter changed Safety Net\'s decisions, or an entry was logged' );
		assert.equal( site.logEntriesSince( mark ).filter( ( line ) => line.includes( `${ COULD_NOT_RECORD }sn-test sender filter failed` ) ).length, 2, 'debug.log does not report each email the log could not record' );
	} );

	test( 'E3c: with the table gone but its option left, the tab, the cleanup and Clear log forget the table without a database error, and the next emails create it again', async () => {
		const mark = site.logMark();
		const dropped = await site.php( "global $wpdb; $wpdb->query( \"DROP TABLE {$wpdb->prefix}safety_net_email_log\" ); return sn_test_email_log_state();", { label: 'dropping the email log table and keeping its option' } );
		assert.deepEqual( [ dropped.table, dropped.options ], [ false, { safety_net_email_log_db_version: '1' } ], 'Dropping the table failed, or its option is gone, so this proves nothing' );

		assert.match( ( await site.get( TAB, { jar: site.adminJar } ) ).text, /No emails have been logged\./, 'The Email log tab without its table does not show an empty log' );
		assert.deepEqual( ( await state() ).options, {}, 'Opening the Email log tab did not forget the missing table' );
		const forgotten = await site.php( "update_option( 'safety_net_email_log_db_version', '1' ); do_action( 'safety_net_email_log_cleanup' ); $cleanup = get_option( 'safety_net_email_log_db_version' ); update_option( 'safety_net_email_log_db_version', '1' ); SafetyNet\\EmailLog\\clear_log(); return array( 'cleanup' => $cleanup, 'clear' => get_option( 'safety_net_email_log_db_version' ) );", { label: 'running the cleanup and Clear log with the option but no table' } );
		assert.deepEqual( forgotten, { cleanup: false, clear: false }, 'The cleanup or Clear log did not forget the missing table' );

		await site.php( "update_option( 'safety_net_email_log_db_version', '1' ); return true;", { label: 'storing the option again without the table' } );
		const first = await send( [ { to: 'someone@example.com', subject: 'Sent while the table is gone', message: 'x' } ] );
		assert.deepEqual( first, { verdicts: [ false ], entries: null }, 'The missing table changed Safety Net\'s decision, or the email created the table in the same load' );
		const forgot = await state();
		assert.deepEqual( [ forgot.table, forgot.options ], [ false, {} ], 'The email that found no table did not forget it' );
		const second = await send( [ { to: 'someone@example.com', subject: 'Sent after the table was forgotten', message: 'x' } ] );
		assert.deepEqual( second.entries?.map( ( entry ) => [ entry.subject, entry.status ] ), [ [ 'Sent after the table was forgotten', 'blocked' ] ], 'The next email did not create the table again and log itself' );
		assert.deepEqual( ( await state() ).options, { safety_net_email_log_db_version: '1' } );

		assert.deepEqual( safetyNetDbErrors( mark ), [], 'A query reached the missing table and wpdb reported it' );
		assert.equal( site.logEntriesSince( mark ).filter( ( line ) => line.includes( COULD_NOT_RECORD ) ).length, 1, 'debug.log does not report the one email the log could not record' );
	} );

	test( 'E4: each email is recorded with Safety Net\'s decision, its recipients, subject, message, headers and attachment names only', async () => {
		const sent = await send( [ ORDER, RESET, PAIR ] );
		assert.deepEqual( sent.verdicts, [ false, null, false ], 'Safety Net did not block the regular emails and let the password reset through' );
		const defaults = { from_address: defaultFrom, content_type: PLAIN };
		assert.deepEqual( sent.entries?.map( withoutIdAndDate ), [
			{ status: 'blocked', recipients: 'one@example.com, Two <two@example.com>', subject: ORDER.subject, message: ORDER.message, headers: 'Cc: cc@example.com\nBcc: bcc@example.com\nReply-To: Shop <reply@example.com>', ...defaults, attachments: 'report.csv\ninvoice-1.pdf' },
			{ status: 'allowed', recipients: RESET.to, subject: RESET.subject, message: RESET.message.replace( 'SECRETKEY1', '[redacted]' ), headers: 'X-SN-Test: reset', ...defaults, attachments: 'one.txt\ntwo.txt' },
			{ status: 'blocked', recipients: 'a@example.com, b@example.com', subject: PAIR.subject, message: '', headers: '', ...defaults, attachments: '' },
		] );
		assertLoggedNow( sent.entries );
	} );

	test( 'E4b: invalid UTF-8 is replaced and text longer than its column is cut, so the database never refuses an entry', async () => {
		const s = await site.php( `
$long = str_repeat( 'é', 40000 );
$sent = sn_test_send_emails( array(
	array( 'to' => 'someone@example.com', 'subject' => "Invalid \\xC3\\x28 UTF-8", 'message' => "Body \\xFF end", 'headers' => "X-SN-Test: \\xFE" ),
	array( 'to' => 'someone@example.com', 'subject' => $long, 'message' => $long, 'headers' => 'X-SN-Long: ' . str_repeat( 'a', 70000 ) ),
) );
list( $invalid, $cut ) = array_pad( (array) $sent['entries'], 2, null );
return array(
	'verdicts' => $sent['verdicts'],
	'count'    => count( (array) $sent['entries'] ),
	'invalid'  => $invalid ? array( 'valid' => mb_check_encoding( $invalid['subject'] . $invalid['message'] . $invalid['headers'], 'UTF-8' ), 'subject' => $invalid['subject'], 'message' => $invalid['message'] ) : null,
	'cut'      => $cut ? array( 'valid' => mb_check_encoding( $cut['subject'] . $cut['headers'], 'UTF-8' ), 'subject' => strlen( $cut['subject'] ), 'prefix' => str_starts_with( $long, $cut['subject'] ), 'message' => $cut['message'] === $long, 'headers' => strlen( $cut['headers'] ) ) : null,
);`, { label: 'sending emails with invalid UTF-8 and over-long text' } );
		assert.deepEqual( [ s.verdicts, s.count ], [ [ false, false ], 2 ], 'An email with invalid UTF-8 or over-long text was not blocked and logged' );
		assert.equal( s.invalid.valid, true, 'Invalid UTF-8 was stored' );
		assert.ok( s.invalid.subject.startsWith( 'Invalid ' ) && s.invalid.subject.endsWith( '( UTF-8' ) && s.invalid.message.startsWith( 'Body ' ) && s.invalid.message.endsWith( ' end' ), `Replacing invalid UTF-8 lost the valid text: ${ JSON.stringify( s.invalid ) }` );
		assert.deepEqual( s.cut, { valid: true, subject: 65534, prefix: true, message: true, headers: 65535 }, 'Text over 64 KB was not cut on a character boundary to fit its column, or the message, which has no such limit, was cut' );
	} );

	test( 'E5: while SAFETY_NET_DELETE_DATA keeps data, a password reset to someone who is not an administrator is recorded as blocked_recipients', async () => {
		await site.php( "update_option( 'sn_test_constants', array( 'SAFETY_NET_DELETE_DATA' => false ) ); return true;", { label: 'defining SAFETY_NET_DELETE_DATA as false from the next load on' } );
		let kept;
		try {
			// The constant is defined while this load starts, so removing its option here only affects later loads.
			kept = await site.php( `delete_option( 'sn_test_constants' ); return array( 'kept' => SafetyNet\\Utilities\\is_data_deletion_disabled(), 'sent' => sn_test_send_emails( ${ phpValue( [ { to: 'customer@example.com', subject: RESET.subject, message: 'x' }, { to: admin, subject: RESET.subject, message: 'x' } ] ) } ) );`, { label: 'sending password resets while data is kept' } );
		} finally {
			if ( ! kept ) {
				await site.php( "delete_option( 'sn_test_constants' ); return true;" );
			}
		}
		assert.equal( kept.kept, true, 'SAFETY_NET_DELETE_DATA did not keep data in that load, so this proves nothing' );
		assert.deepEqual( kept.sent.verdicts, [ false, null ], 'While data is kept, a password reset must reach only administrators' );
		assert.deepEqual( kept.sent.entries?.map( ( entry ) => [ entry.recipients, entry.status ] ), [ [ 'customer@example.com', 'blocked_recipients' ], [ admin, 'allowed' ] ] );
	} );

	test( 'E6: the sender and content type are the ones wp_mail() would use at that moment, including filters that exist only around the call, as WooCommerce adds them', async () => {
		const sent = await send( [
			WOO,
			WOO_MULTIPART,
			{ to: 'customer@example.com', subject: 'Sent with a wp_mail_charset filter', message: 'x', charset: 'ISO-8859-1' },
			{ to: 'customer@example.com', subject: 'Sent after the WooCommerce filters were removed', message: 'Plain text' },
			{ to: 'customer@example.com', subject: 'Sender and type from the headers', message: '<p>Hello</p>', headers: [ 'From: "Named Sender" <named@example.com>', 'Content-Type: text/html; charset="ISO-8859-1"' ] },
			{ to: 'customer@example.com', subject: 'Bare From header', message: 'x', headers: 'From: bare@example.com' },
		] );
		assert.deepEqual( sent.verdicts, [ false, false, false, false, false, false ] );
		assert.deepEqual( sent.entries?.map( ( entry ) => [ entry.subject, entry.from_address, entry.content_type ] ), [
			[ WOO.subject, 'Example Shop <shop@example.com>', 'text/html; charset=UTF-8' ],
			[ WOO_MULTIPART.subject, 'Example Shop <shop@example.com>', 'multipart/alternative; charset=UTF-8' ],
			[ 'Sent with a wp_mail_charset filter', defaultFrom, 'text/plain; charset=ISO-8859-1' ],
			[ 'Sent after the WooCommerce filters were removed', defaultFrom, PLAIN ],
			[ 'Sender and type from the headers', 'Named Sender <named@example.com>', 'text/html; charset=ISO-8859-1' ],
			[ 'Bare From header', 'WordPress <bare@example.com>', PLAIN ],
		] );
		assert.deepEqual( withoutIdAndDate( sent.entries[ 0 ] ), {
			status: 'blocked',
			recipients: WOO.to,
			subject: WOO.subject,
			message: WOO.message.replace( 'WOOKEY1', '[redacted]' ),
			headers: 'Reply-to: Example Shop <shop@example.com>\nCc: manager@example.com\nBcc: archive@example.com',
			from_address: 'Example Shop <shop@example.com>',
			content_type: 'text/html; charset=UTF-8',
			attachments: 'terms.pdf',
		} );
	} );

	test( 'E7: the value of every key= and confirm_key= URL parameter is redacted in plain-text and HTML messages, and other parameters and the subject are kept', async () => {
		const plain = { to: 'someone@example.com', subject: 'Keys stay in the subject: ?key=SUBJECTKEY', message: 'Reset: https://example.test/wp-login.php?action=rp&key=PLAINKEY1&login=someone\nOr: https://example.test/?key=PLAINKEY2\nConfirm: https://example.test/wp-login.php?action=confirmaction&request_id=7&confirm_key=PLAINKEY3\nNot keys: monkey=banana&keyring=1&key_id=3&reconfirm_key=4' };
		const html = { to: 'someone@example.com', subject: 'Keys in HTML links', message: '<a href="https://shop.example/?action=newaccount&#038;key=HTMLKEY1&#038;login=c">a</a> <a href="https://example.test/wp-login.php?action=rp&amp;key=HTMLKEY2&amp;login=x">b</a> <a href="https://example.test/?KEY=HTMLKEY3">c</a> <a href="https://example.test/?a=1&#38;key=HTMLKEY4">d</a> <a href="https://example.test/wp-login.php?action=confirmaction&#038;request_id=7&#038;confirm_key=HTMLKEY5">e</a>', headers: [ 'Content-Type: text/html' ] };
		const sent = await send( [ plain, html ] );
		assert.deepEqual( sent.verdicts, [ false, false ] );
		const redacted = ( message ) => message.replace( /(?:PLAIN|HTML)KEY\d/g, '[redacted]' );
		assert.deepEqual( sent.entries?.map( ( entry ) => [ entry.subject, entry.message ] ), [ [ plain.subject, redacted( plain.message ) ], [ html.subject, redacted( html.message ) ] ] );
		assert.deepEqual( ( await log() ).filter( ( entry ) => /SECRETKEY|WOOKEY|PLAINKEY|HTMLKEY/.test( JSON.stringify( entry ) ) ).map( ( entry ) => entry.subject ), [], 'A logged entry still holds a key' );
	} );

	test( 'E8: the Email log tab shows both switches on by default, in a form of their own, and the Tools tab is unchanged', async () => {
		assert.deepEqual( ( await state() ).options, { safety_net_email_log_db_version: '1' }, 'A switch is stored, so this does not test the defaults' );
		const tab = await site.get( TAB, { jar: site.adminJar } );
		assert.deepEqual( toggles( tab.text ), { safety_net_email_log_toggle: true, safety_net_email_log_cleanup_toggle: true }, 'A switch without a stored option does not show as on' );
		const form = settingsForm( tab.text );
		assert.equal( form.action, 'options.php' );
		assert.deepEqual( form.checkboxes, TOGGLES, 'The Email log form must hold exactly the two switches' );
		assert.match( tab.text, /Keep a log of the emails Safety Net blocks or lets through\./ );
		assert.match( tab.text, /Delete log entries older than 6 months, once a day\./ );
		assert.match( tab.text, /<a href="[^"]*tab=email-log" class="nav-tab nav-tab-active"/, 'The Email log tab is not the active tab' );

		const { res: tools } = await getToolsPage( site );
		assert.match( tools.text, /<a href="[^"]*page=safety_net_options" class="nav-tab nav-tab-active"[^>]*>Tools<\/a>/, 'The Tools tab is not the active tab on the Tools page' );
		assert.match( tools.text, /<h3>Tools<\/h3>\s*<form action="options.php" method="post">/, 'The Tools tab lost its form' );
		assert.doesNotMatch( tools.text, /safety_net_email_log_(?:cleanup_)?toggle/, 'The Tools tab holds an email log switch, which saving its form would switch off' );
	} );

	test( 'E9: saving the Email log form unchecked switches logging or cleanup off, and checked back on; entries stay, and the cleanup event follows its own switch', async () => {
		const form = settingsForm( ( await site.get( TAB, { jar: site.adminJar } ) ).text );
		const pause = () => site.php( "return sn_test_raw_option( 'safety_net_pause_renewal_actions_toggle' );" );
		const pauseBefore = await pause();
		const save = async ( checked ) => {
			const res = await site.post( '/wp-admin/options.php', { ...form.fields, ...Object.fromEntries( checked.map( ( name ) => [ name, 'on' ] ) ) }, { jar: site.adminJar, follow: false, expect: 302 } );
			const back = new URL( res.location, site.url );
			assert.deepEqual( [ back.searchParams.get( 'tab' ), back.searchParams.get( 'settings-updated' ) ], [ 'email-log', 'true' ], `Saving the Email log form redirected to ${ res.location }` );
			return state();
		};
		const before = await log();

		try {
			let s = await save( [ 'safety_net_email_log_cleanup_toggle' ] );
			assert.deepEqual( s.options, { safety_net_email_log_toggle: 'off', safety_net_email_log_cleanup_toggle: 'on', safety_net_email_log_db_version: '1' } );
			assert.equal( s.cleanup?.schedule, 'daily', 'Switching logging off unscheduled the cleanup, which has its own switch' );
			assert.deepEqual( await send( [ { to: 'someone@example.com', subject: 'Sent while logging is switched off', message: 'x' } ] ), { verdicts: [ false ], entries: [] }, 'An email was logged with logging switched off, or not blocked' );
			assert.deepEqual( await log(), before, 'Switching logging off changed the entries already logged' );

			s = await save( [] );
			assert.deepEqual( s.options, { safety_net_email_log_toggle: 'off', safety_net_email_log_cleanup_toggle: 'off', safety_net_email_log_db_version: '1' } );
			assert.equal( s.cleanup, null, 'The cleanup event is still scheduled with its switch off' );
			assert.deepEqual( toggles( ( await site.get( TAB, { jar: site.adminJar } ) ).text ), { safety_net_email_log_toggle: false, safety_net_email_log_cleanup_toggle: false } );

			s = await save( TOGGLES );
			assert.deepEqual( s.options, { safety_net_email_log_toggle: 'on', safety_net_email_log_cleanup_toggle: 'on', safety_net_email_log_db_version: '1' } );
			assert.equal( s.cleanup?.schedule, 'daily', 'Switching the cleanup back on did not schedule it again' );
			const resumed = await send( [ { to: 'someone@example.com', subject: 'Sent after logging is switched back on', message: 'x' } ] );
			assert.deepEqual( resumed.entries?.map( ( entry ) => [ entry.subject, entry.status ] ), [ [ 'Sent after logging is switched back on', 'blocked' ] ], 'Logging did not resume' );
			assert.equal( await pause(), pauseBefore, 'Saving the Email log form changed the renewal pause on the Tools tab' );
			assert.match( ( await site.get( `${ TAB }&settings-updated=true`, { jar: site.adminJar } ) ).text, /Settings saved\./ );
		} finally {
			await site.php( "delete_option( 'safety_net_email_log_toggle' ); delete_option( 'safety_net_email_log_cleanup_toggle' ); return true;", { label: 'switching logging and cleanup back to their defaults' } );
		}
	} );

	test( 'E10: saving the Tools tab form leaves the email log switches alone', async () => {
		const read = () => site.php( "return array_map( 'sn_test_raw_option', array( 'safety_net_email_log_toggle', 'safety_net_email_log_cleanup_toggle', 'safety_net_pause_renewal_actions_toggle' ) );" );
		await site.php( "update_option( 'safety_net_email_log_toggle', 'on' ); update_option( 'safety_net_email_log_cleanup_toggle', 'on' ); return true;", { label: 'storing both email log switches as on' } );
		try {
			assert.deepEqual( await read(), [ 'on', 'on', 'on' ], 'The switches are not all stored as on, so this proves nothing' );
			await saveToolsForm( site );
			const [ logging, cleanup, renewalPause ] = await read();
			assert.notEqual( renewalPause, 'on', 'Saving the Tools form did not turn its unticked renewal pause off, so the form may not have been saved' );
			assert.deepEqual( [ logging, cleanup ], [ 'on', 'on' ], 'Saving the Tools form, which holds no email log switch, switched one off' );
		} finally {
			await site.php( "update_option( 'safety_net_pause_renewal_actions_toggle', 'on' ); delete_option( 'safety_net_email_log_toggle' ); delete_option( 'safety_net_email_log_cleanup_toggle' ); return true;", { label: 'turning the renewal pause back on and the email log switches back to their defaults' } );
		}
	} );

	test( 'E11: WP-Cron\'s daily cleanup deletes only entries older than 6 months, nothing deletes them with the cleanup switched off, and opening the tab deletes them too', async () => {
		const recent = ( await log() ).map( ( entry ) => entry.id );

		try {
			const ages = { 'Seven months old': '-7 months', 'Just over six months old': '-6 months -1 hour', 'Just under six months old': '-6 months +1 hour', 'One day old': '-1 day' };
			assert.deepEqual( Object.keys( await seedAged( ages ) ), Object.keys( ages ), 'Adding the aged entries failed' );
			assert.deepEqual( await aged(), Object.keys( ages ), 'Adding the aged entries failed' );
			assert.deepEqual( ( await state() ).cleanup, { due: true, schedule: 'daily' }, 'The daily cleanup is not scheduled and due, so WP-Cron would not run it' );
			await site.get( '/wp-cron.php?doing_wp_cron' );
			assert.deepEqual( await aged(), [ 'Just under six months old', 'One day old' ], 'WP-Cron\'s cleanup did not delete exactly the entries older than 6 months' );
			const kept = ( await log() ).map( ( entry ) => entry.id );
			assert.deepEqual( recent.filter( ( id ) => ! kept.includes( id ) ), [], 'The cleanup deleted recent entries' );
			assert.deepEqual( ( await state() ).cleanup, { due: false, schedule: 'daily' }, 'The cleanup did not run from WP-Cron, or was not scheduled again for the next day' );

			await site.php( "update_option( 'safety_net_email_log_cleanup_toggle', 'off' ); return true;", { label: 'switching the cleanup off' } );
			try {
				assert.equal( ( await state() ).cleanup, null, 'The cleanup event is still scheduled with its switch off' );
				await seedAged( { 'Seven months old, cleanup off': '-7 months' } );
				await site.php( "do_action( 'safety_net_email_log_cleanup' ); return true;", { label: 'running the cleanup event' } );
				await site.get( TAB, { jar: site.adminJar } );
				assert.ok( ( await aged() ).includes( 'Seven months old, cleanup off' ), 'An entry older than 6 months was deleted with the cleanup switched off' );
			} finally {
				await site.php( "delete_option( 'safety_net_email_log_cleanup_toggle' ); return true;", { label: 'switching the cleanup back to its default' } );
			}
			assert.deepEqual( ( await state() ).cleanup, { due: true, schedule: 'daily' }, 'Switching the cleanup back on did not schedule it again' );
			await site.get( TAB, { jar: site.adminJar } );
			assert.deepEqual( await aged(), [ 'Just under six months old', 'One day old' ], 'Opening the Email log tab did not delete the entry older than 6 months, which WP-Cron has not run on' );
		} finally {
			// Their dates are older than entries logged before them, so they would break the list order checked later.
			await site.php( "global $wpdb; $wpdb->delete( $wpdb->prefix . 'safety_net_email_log', array( 'recipients' => 'aged@example.com' ) ); return true;", { label: 'removing the aged entries' } );
		}
	} );

	test( 'E12: the tab lists the entries newest first, 20 a page, with dates in the site\'s timezone and every value escaped', async () => {
		const saved = await site.php( "$saved = array(); foreach ( array( 'timezone_string', 'date_format', 'time_format' ) as $name ) { $saved[ $name ] = get_option( $name ); } update_option( 'timezone_string', 'Asia/Tokyo' ); update_option( 'date_format', 'Y-m-d' ); update_option( 'time_format', 'H:i' ); return $saved;", { label: 'setting the site\'s timezone to Asia/Tokyo' } );
		try {
			const bulk = await send( Array.from( { length: 21 }, ( _, i ) => ( { to: `bulk${ i + 1 }@example.com`, subject: `Bulk ${ i + 1 }`, message: 'x' } ) ) );
			assert.equal( bulk.entries?.length, 21, 'Logging the bulk emails failed' );
			assertLoggedNow( bulk.entries );
			const entries = ( await log() ).reverse();
			const pages = Math.ceil( entries.length / 20 );
			assert.ok( pages >= 2, 'The log fits on one page, so paging is not tested' );
			assert.ok( entries.some( ( entry ) => entry.status === 'blocked_recipients' ) && entries.some( ( entry ) => entry.status === 'allowed' ), 'The log lacks an entry of every status, so their labels are not tested' );
			const loaded = await site.getAll( Array.from( { length: pages }, ( _, i ) => ( { path: i === 0 ? TAB : `${ TAB }&paged=${ i + 1 }`, jar: site.adminJar } ) ) );
			for ( const [ i, res ] of loaded.entries() ) {
				const page = i + 1;
				const listed = listedEntries( res.text );
				const expected = entries.slice( ( page - 1 ) * 20, page * 20 );
				assert.deepEqual( listed.map( ( row ) => [ row.id, row.status ] ), expected.map( ( entry ) => [ entry.id, entry.status ] ), `Page ${ page } does not list the entries newest first, 20 a page` );
				assert.deepEqual( listed.map( ( row ) => row.cells.map( decode ) ), expected.map( ( entry ) => [ tokyoTime( entry.logged_at ), entry.recipients, entry.subject, LABELS[ entry.status ] ] ), `Page ${ page } shows the wrong date (in Asia/Tokyo), recipients, subject or status` );
				assert.deepEqual( listed.flatMap( ( row ) => row.cells ).filter( ( cell ) => /[<>]/.test( cell ) ), [], `Page ${ page } shows a logged value unescaped` );
				assert.match( res.text, new RegExp( `class="displaying-num">\\s*${ entries.length } entries\\s*<` ) );
				assert.match( res.text, /Only emails sent through wp_mail\(\) appear here\./ );
				assert.match( res.text, /The log is emptied whenever Safety Net deletes users and orders\./ );
				assert.match( actionForm( res.text, 'safety_net_email_log_clear' ).html, /data-safety-net-confirm="[^"]+"/, 'Clear log does not ask for confirmation' );
			}
			assert.match( loaded[ 0 ].text, /<a class="next page-numbers" href="[^"]*tab=email-log[^"]*paged=2"/, 'The first page does not link to the next one' );
		} finally {
			await site.php( `foreach ( ${ phpValue( saved ) } as $name => $value ) { update_option( $name, $value ); } return true;`, { label: 'restoring the site\'s timezone' } );
		}
	} );

	test( 'E13: an entry shows its details escaped, an HTML or WooCommerce multipart message in a sandboxed frame that gets it as written, and a plain-text message escaped', async () => {
		const html = await entryBySubject( WOO.subject );
		const multipart = await entryBySubject( WOO_MULTIPART.subject );
		const plain = await entryBySubject( ORDER.subject );
		const [ htmlView, multipartView, plainView, missing ] = ( await site.getAll( [ html.id, multipart.id, plain.id, 999999 ].map( ( id ) => ( { path: entryPath( id ), jar: site.adminJar } ) ) ) ).map( ( res ) => res.text );

		for ( const [ view, entry ] of [ [ htmlView, html ], [ multipartView, multipart ] ] ) {
			const iframe = view.match( /<iframe\b[^>]*>/ )?.[ 0 ];
			assert.ok( iframe, `The ${ entry.content_type } message is not shown in a frame` );
			assert.match( iframe, /\ssandbox=""/, 'The frame is not sandboxed with every restriction' );
			assert.doesNotMatch( iframe, /allow-/, 'The frame\'s sandbox allows something' );
			const srcdoc = iframe.match( /\ssrcdoc="([^"]*)"/ )?.[ 1 ];
			assert.ok( srcdoc !== undefined, 'The frame has no srcdoc' );
			assert.doesNotMatch( srcdoc, /[<>]/, 'The frame\'s srcdoc holds unescaped markup' );
			const doc = decode( srcdoc );
			assert.ok( doc.endsWith( entry.message ), `The frame does not get the logged message as written: ${ doc }` );
			const head = doc.slice( 0, doc.length - entry.message.length );
			assert.ok( head.startsWith( CSP ), `The frame's document does not start with the Content-Security-Policy that blocks scripts and remote images: ${ head }` );
			assert.match( head, /^(?:<meta [^<>]*>)+$/, 'The frame gets more than meta tags before the message' );
			assert.doesNotMatch( view, /<pre class="safety-net-email-log-message">/, `The ${ entry.content_type } message is also shown as source` );
		}

		assert.equal( decode( detail( htmlView, 'From' ) ), html.from_address );
		assert.equal( decode( detail( htmlView, 'To' ) ), html.recipients );
		assert.equal( decode( detail( htmlView, 'Subject' ) ), html.subject );
		assert.equal( decode( detail( htmlView, 'Status' ) ), 'Blocked' );
		const headers = detail( htmlView, 'Headers' )?.match( /^<pre>([\s\S]*)<\/pre>$/ )?.[ 1 ];
		assert.ok( headers !== undefined && ! /[<>]/.test( headers ), 'The headers are not shown escaped in <pre>' );
		assert.equal( decode( headers ), html.headers );
		assert.match( detail( htmlView, 'Attachments' ), /^terms\.pdf<br \/>/ );
		assert.doesNotMatch( detail( htmlView, 'From' ), /[<>]/, 'The sender is shown unescaped' );

		const pre = plainView.match( /<pre class="safety-net-email-log-message">([\s\S]*?)<\/pre>/ )?.[ 1 ];
		assert.ok( pre !== undefined, 'The plain-text message is not shown in <pre>' );
		assert.doesNotMatch( pre, /[<>]/, 'The plain-text message is shown unescaped' );
		assert.equal( decode( pre ), plain.message, 'The plain-text message is not shown as written' );
		assert.doesNotMatch( plainView, /<iframe/ );

		for ( const [ view, entry ] of [ [ htmlView, html ], [ plainView, plain ] ] ) {
			for ( const action of [ 'safety_net_email_log_send', 'safety_net_email_log_delete' ] ) {
				assert.equal( actionForm( view, action ).fields.entry, entry.id, `The ${ action } form is not for entry ${ entry.id }` );
			}
		}
		assert.match( missing, /That log entry does not exist\./ );
	} );

	test( 'E14: Send to me sends one copy, only to the current administrator, with the logged sender, content type and message, without Cc, Bcc or attachments, and logs nothing', async () => {
		const entry = await entryBySubject( WOO.subject );
		assert.match( entry.headers, /^Cc: /m, 'The entry has no Cc, so dropping it is not tested' );
		assert.match( entry.headers, /^Bcc: /m, 'The entry has no Bcc, so dropping it is not tested' );
		assert.notEqual( entry.attachments, '', 'The entry has no attachments, so dropping them is not tested' );
		const multipart = await entryBySubject( WOO_MULTIPART.subject );
		assert.equal( multipart.content_type, 'multipart/alternative; charset=UTF-8', 'The multipart entry is not stored as WooCommerce sent it, so this proves nothing' );
		await capture( 1 );
		try {
			// Without a boundary, a multipart content type would make the copy an invalid MIME message around its HTML.
			for ( const copied of [ entry, multipart ] ) {
				const { fields } = actionForm( ( await site.get( entryPath( copied.id ), { jar: site.adminJar } ) ).text, 'safety_net_email_log_send' );
				const before = { mail: capturedMail( site ).length, entries: await log(), log: site.logMark() };
				const res = await site.post( ADMIN_POST, fields, { jar: site.adminJar, follow: false, expect: 302 } );
				assert.deepEqual( redirectArgs( site, res ), { page: 'safety_net_options', tab: 'email-log', result: 'sent', entry: copied.id } );
				const mail = capturedMail( site ).slice( before.mail );
				assert.equal( mail.length, 1, 'Send to me did not send exactly one email' );
				const { verdict, atts } = mail[ 0 ];
				assert.deepEqual( { verdict, to: atts.to, subject: atts.subject, message: atts.message, headers: atts.headers, attachments: atts.attachments }, {
					verdict: null,
					to: admin,
					subject: `[Safety Net copy] ${ copied.subject }`,
					message: copied.message,
					headers: [ `From: ${ copied.from_address }`, `Content-Type: ${ HTML_COPY }` ],
					attachments: [],
				}, `The copy of "${ copied.subject }" was not let through to the administrator alone, as HTML, with the logged sender and redacted message` );
				assert.deepEqual( await log(), before.entries, 'Sending the copy changed the log' );
				assert.ok( site.logEntriesSince( before.log ).some( ( line ) => line.includes( `Email sent: [Safety Net copy] ${ copied.subject } (copy of a logged email)` ) ), 'The copy was not logged to debug.log as sent' );
				assert.equal( notice( ( await site.get( res.location, { jar: site.adminJar } ) ).text ), 'success: A copy was sent to your email address.' );
			}
		} finally {
			await capture( null );
		}
	} );

	test( 'E15: a copy that a wp_mail filter also sends to someone else is blocked, and a user who can manage options but is not an administrator gets no copy', async () => {
		const entry = await entryBySubject( WOO.subject );
		const { fields } = actionForm( ( await site.get( entryPath( entry.id ), { jar: site.adminJar } ) ).text, 'safety_net_email_log_send' );
		await capture( { bcc: 'outsider@example.com' } );
		try {
			const before = { mail: capturedMail( site ).length, last: await site.php( 'return sn_test_email_log_last_id();' ) };
			const res = await site.post( ADMIN_POST, fields, { jar: site.adminJar, follow: false, expect: 302 } );
			assert.equal( redirectArgs( site, res ).result, 'not-sent' );
			const mail = capturedMail( site ).slice( before.mail );
			assert.equal( mail.length, 1, 'The copy never reached Safety Net, so this proves nothing' );
			assert.ok( mail[ 0 ].atts.headers.includes( 'Bcc: outsider@example.com' ), 'The wp_mail filter did not add the Bcc, so this proves nothing' );
			assert.equal( mail[ 0 ].verdict, false, 'A copy that also goes to someone who is not an administrator was let through' );
			assert.deepEqual( ( await log( before.last ) ).map( ( added ) => [ added.subject, added.status ] ), [ [ `[Safety Net copy] ${ entry.subject }`, 'blocked' ] ], 'The blocked copy was not handled like any other email' );
			assert.match( notice( ( await site.get( res.location, { jar: site.adminJar } ) ).text ), /^error: The copy was not sent\./ );
		} finally {
			await capture( null );
		}

		const manager = await site.php( "$id = sn_test_create_user( 'log_manager', 'editor' ); ( new WP_User( $id ) )->add_cap( 'manage_options' ); wp_set_password( 'password', $id ); return array( 'id' => $id, 'admin' => in_array( 'log_manager@example.com', SafetyNet\\Utilities\\get_admin_emails(), true ) );", { label: 'creating an editor who can manage options' } );
		try {
			assert.equal( manager.admin, false, 'The editor counts as an administrator, so this proves nothing' );
			const jar = await site.login( { user: 'log_manager', jar: new CookieJar() } );
			const view = await site.get( entryPath( entry.id ), { jar } );
			const managerForm = actionForm( view.text, 'safety_net_email_log_send' );
			await capture( 1 );
			try {
				const before = { mail: capturedMail( site ).length, entries: await log() };
				const res = await site.post( ADMIN_POST, managerForm.fields, { jar, follow: false, expect: 302 } );
				assert.deepEqual( redirectArgs( site, res ), { page: 'safety_net_options', tab: 'email-log', result: 'not-admin', entry: entry.id } );
				assert.deepEqual( capturedMail( site ).slice( before.mail ), [], 'A copy was sent to a user who is not an administrator' );
				assert.deepEqual( await log(), before.entries );
				assert.match( notice( ( await site.get( res.location, { jar } ) ).text ), /^error: The copy was not sent: copies only go to administrators/ );
			} finally {
				await capture( null );
			}
		} finally {
			await site.php( `require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( ${ manager.id } ); return true;`, { label: 'deleting the editor' } );
		}
	} );

	test( 'E15b: a recipient that a phpmailer_init callback adds to a copy makes PHPMailer refuse it, and an email sent while a copy is sent gets the usual rules', async () => {
		const entry = await entryBySubject( WOO_MULTIPART.subject );
		const copy = ( options ) => site.php( `return sn_test_send_copy( ${ entry.id }, ${ phpValue( options ) } );`, { label: 'sending a copy through a PHPMailer that sends nothing' } );
		const delivery = ( { sent, delivered, recipients, flag } ) => ( { sent, delivered, recipients, flag } );

		const alone = await copy( {} );
		assert.deepEqual( delivery( alone ), { sent: true, delivered: 1, recipients: [ admin ], flag: false }, 'The copy did not reach PHPMailer for the administrator alone' );
		assert.deepEqual( [ alone.content_type, alone.errors, alone.entries ], [ 'text/html', [], [] ], 'The copy of a multipart entry was not sent as HTML, failed or was logged' );

		const archived = await copy( { bcc: 'archive@example.com' } );
		assert.deepEqual( delivery( archived ), { sent: false, delivered: 0, recipients: [], flag: false }, 'A copy that a phpmailer_init callback also sent to someone who is not an administrator was sent' );
		assert.match( archived.errors.join( '\n' ), /at least one recipient/, 'PHPMailer did not refuse the copy for lack of recipients' );

		const nested = await copy( { nested: admin } );
		assert.deepEqual( delivery( nested ), { sent: true, delivered: 1, recipients: [ admin ], flag: false }, 'The email sent while the copy was sent changed the copy' );
		assert.deepEqual( nested.nested, [ false ], 'An email to an administrator sent while a copy was sent was let through as if it were the copy' );
		assert.deepEqual( nested.entries.map( ( added ) => [ added.recipients, added.subject, added.status ] ), [ [ admin, 'Nested email while a copy is sent', 'blocked' ] ], 'The email sent while the copy was sent was not logged like any other' );
	} );

	test( 'E16: Send to me, Delete and Clear log refuse a bad, missing or another action\'s nonce, a subscriber and a logged-out visitor, and change nothing', async () => {
		const entries = await log();
		assert.ok( entries.length >= 2, 'The log has fewer than two entries, so this proves little' );
		const [ first, second ] = entries;
		const firstView = ( await site.get( entryPath( first.id ), { jar: site.adminJar } ) ).text;
		const nonces = {
			send: actionForm( firstView, 'safety_net_email_log_send' ).fields._wpnonce,
			delete: actionForm( firstView, 'safety_net_email_log_delete' ).fields._wpnonce,
		};
		const requests = {
			send: { action: 'safety_net_email_log_send', entry: first.id },
			delete: { action: 'safety_net_email_log_delete', entry: first.id },
			clear: { action: 'safety_net_email_log_clear' },
		};
		const subscriber = await site.php( "$id = sn_test_create_user( 'log_subscriber', 'subscriber' ); wp_set_password( 'password', $id ); return $id;", { label: 'creating a subscriber with a known password' } );
		await capture( 1 );
		try {
			const before = { mail: capturedMail( site ).length, log: site.logMark() };
			const refusedForAdmin = [
				[ requests.send, 'bad' ],
				[ requests.send, null ],
				[ { ...requests.send, entry: second.id }, nonces.send ],
				[ requests.send, nonces.delete ],
				[ requests.delete, 'bad' ],
				[ requests.delete, null ],
				[ { ...requests.delete, entry: second.id }, nonces.delete ],
				[ requests.delete, nonces.send ],
				[ requests.clear, 'bad' ],
				[ requests.clear, null ],
				[ requests.clear, nonces.delete ],
			];
			for ( const [ form, nonce ] of refusedForAdmin ) {
				const res = await site.post( ADMIN_POST, nonce === null ? form : { ...form, _wpnonce: nonce }, { jar: site.adminJar, follow: false, expect: 403 } );
				assert.match( res.text, /The link you followed has expired\./, `${ JSON.stringify( form ) } with the nonce ${ nonce } was not refused for its nonce` );
			}

			const jar = await site.login( { user: 'log_subscriber', jar: new CookieJar() } );
			const own = await noncesForSession( site, jar, 'log_subscriber', [ `safety_net_email_log_send_${ first.id }`, `safety_net_email_log_delete_${ first.id }`, 'safety_net_email_log_clear' ] );
			for ( const [ form, nonce ] of [ [ requests.send, own[ `safety_net_email_log_send_${ first.id }` ] ], [ requests.delete, own[ `safety_net_email_log_delete_${ first.id }` ] ], [ requests.clear, own.safety_net_email_log_clear ] ] ) {
				const res = await site.post( ADMIN_POST, { ...form, _wpnonce: nonce }, { jar, follow: false, expect: 403 } );
				assert.match( res.text, /You do not have permission to do that\./, `${ form.action } did not refuse a subscriber with a valid nonce` );
			}
			assert.match( ( await site.get( TAB, { jar, expect: 403 } ) ).text, /Sorry, you are not allowed to access this page\./ );

			for ( const form of Object.values( requests ) ) {
				await site.post( ADMIN_POST, { ...form, _wpnonce: nonces.send }, { follow: false, expect: 400 } );
			}
			assert.match( ( await site.get( TAB, { follow: false, expect: 302 } ) ).location, /wp-login\.php/ );

			assert.deepEqual( await log(), entries, 'A refused request changed the log' );
			assert.deepEqual( capturedMail( site ).slice( before.mail ), [], 'A refused Send to me sent an email' );
			assert.deepEqual( site.logEntriesSince( before.log ).filter( ( line ) => /copy of a logged email|WordPress database error|SN_TEST attributed/.test( line ) ), [], 'A refused request sent a copy, or logged a Safety Net warning or database error' );
		} finally {
			await capture( null );
			await site.php( `require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( ${ subscriber } ); return true;`, { label: 'deleting the subscriber' } );
		}
	} );

	test( 'E17: safety_net_hide_admin hides the Email log tab and its actions, and emails are still logged and old entries deleted', async () => {
		const { fields } = actionForm( ( await site.get( TAB, { jar: site.adminJar } ) ).text, 'safety_net_email_log_clear' );
		const before = await log();
		await site.php( "update_option( 'sn_test_hide_admin', 1 ); return true;" );
		try {
			assert.match( ( await site.get( TAB, { jar: site.adminJar, expect: 403 } ) ).text, /Sorry, you are not allowed to access this page\./ );
			await site.post( ADMIN_POST, fields, { jar: site.adminJar, follow: false, expect: 400 } );
			assert.deepEqual( await log(), before, 'Clear log ran although safety_net_hide_admin hides it' );
			const sent = await send( [ { to: 'someone@example.com', subject: 'Sent while the admin page is hidden', message: 'x' } ] );
			assert.deepEqual( sent.entries?.map( ( entry ) => [ entry.subject, entry.status ] ), [ [ 'Sent while the admin page is hidden', 'blocked' ] ], 'Hiding the admin page stopped logging' );

			await site.php( "wp_clear_scheduled_hook( 'safety_net_email_log_cleanup' ); return true;", { label: 'unscheduling the cleanup' } );
			assert.equal( ( await state() ).cleanup?.schedule, 'daily', 'init did not schedule the cleanup again while the admin page is hidden' );
			await seedAged( { 'Seven months old, admin page hidden': '-7 months' } );
			assert.deepEqual( await aged(), [ 'Seven months old, admin page hidden' ], 'Adding the aged entry failed' );
			await site.php( "do_action( 'safety_net_email_log_cleanup' ); return true;", { label: 'running the cleanup event' } );
			assert.deepEqual( await aged(), [], 'The cleanup did not delete the entry older than 6 months while the admin page is hidden' );
		} finally {
			await site.php( "delete_option( 'sn_test_hide_admin' ); return true;" );
		}
	} );

	test( 'E18: Delete removes one entry, and Clear log removes them all and keeps the table', async () => {
		const before = await log();
		const target = before[ 1 ];
		const { fields } = actionForm( ( await site.get( entryPath( target.id ), { jar: site.adminJar } ) ).text, 'safety_net_email_log_delete' );
		const res = await site.post( ADMIN_POST, fields, { jar: site.adminJar, follow: false, expect: 302 } );
		assert.deepEqual( redirectArgs( site, res ), { page: 'safety_net_options', tab: 'email-log', result: 'deleted' } );
		assert.deepEqual( await log(), before.filter( ( entry ) => entry.id !== target.id ), 'Delete did not remove exactly that entry' );
		assert.ok( before.length > 21, 'The log fits on one page, so the pagination links are not tested' );
		const deleted = ( await site.get( res.location, { jar: site.adminJar } ) ).text;
		assert.equal( notice( deleted ), 'success: The log entry was deleted.' );
		const next = deleted.match( /<a class="next page-numbers" href="([^"]*)"/ )?.[ 1 ];
		assert.ok( next, 'The page after Delete has no link to the next page' );
		assert.doesNotMatch( decode( next ), /[?&]result=/, 'The next page link shows the Delete notice again' );
		assert.doesNotMatch( settingsForm( deleted ).fields._wp_http_referer, /[?&]result=/, 'Saving the switches after Delete would show its notice again' );
		assert.doesNotMatch( decode( deleted.match( /<link id="wp-admin-canonical" rel="canonical" href="([^"]*)"/ )?.[ 1 ] ?? 'result=missing' ), /[?&]result=/, 'wp-admin does not remove the result from the address bar' );
		const again = await site.post( ADMIN_POST, fields, { jar: site.adminJar, follow: false, expect: 302 } );
		assert.equal( redirectArgs( site, again ).result, 'not-found' );
		assert.match( ( await site.get( entryPath( target.id ), { jar: site.adminJar } ) ).text, /That log entry does not exist\./ );

		const list = await site.get( TAB, { jar: site.adminJar } );
		const clear = actionForm( list.text, 'safety_net_email_log_clear' );
		const cleared = await site.post( ADMIN_POST, clear.fields, { jar: site.adminJar, follow: false, expect: 302 } );
		assert.deepEqual( redirectArgs( site, cleared ), { page: 'safety_net_options', tab: 'email-log', result: 'cleared' } );
		assert.deepEqual( await log(), [], 'Clear log left entries' );
		const s = await state();
		assert.deepEqual( [ s.table, s.options.safety_net_email_log_db_version, s.cleanup?.schedule ], [ true, '1', 'daily' ], 'Clear log removed the table, its version option or the cleanup event' );
		const page = ( await site.get( cleared.location, { jar: site.adminJar } ) ).text;
		assert.equal( notice( page ), 'success: The email log was cleared.' );
		assert.match( page, /No emails have been logged\./ );
	} );

	test( 'E19: the Tools page\'s Delete button empties the log and keeps its table', async () => {
		const sent = await send( [ { to: 'customer@example.com', subject: 'Before the Tools delete', message: 'x' }, { to: 'customer@example.com', subject: 'Also before the Tools delete', message: 'x' } ] );
		assert.equal( sent.entries?.length, 2, 'Logging emails failed, so this proves nothing' );
		const { nonces } = await getToolsPage( site );
		assert.deepEqual( await postAjax( site, 'safety_net_delete_users', nonces[ 'safety-net-delete-users' ] ), { success: true, message: 'Users, orders, and subscriptions have been successfully deleted!' } );
		assert.deepEqual( await log(), [], 'The Tools Delete button left entries in the email log' );
		assert.equal( ( await state() ).table, true, 'The Tools Delete button dropped the email log table' );
	} );

	test( 'E20: with its version option lost, as after importing a database, the Tools page\'s Delete still empties the table, so its entries never come back', async () => {
		const sent = await send( [ { to: 'customer@example.com', subject: 'Logged before the option was lost', message: 'x' } ] );
		assert.equal( sent.entries?.length, 1, 'Logging an email failed, so this proves nothing' );
		await site.php( "delete_option( 'safety_net_email_log_db_version' ); return true;", { label: 'losing the email log\'s version option' } );
		const { nonces } = await getToolsPage( site );
		assert.deepEqual( await postAjax( site, 'safety_net_delete_users', nonces[ 'safety-net-delete-users' ] ), { success: true, message: 'Users, orders, and subscriptions have been successfully deleted!' } );
		assert.deepEqual( await log(), [], 'The Tools Delete button left the entries of a table whose option was lost' );
		const next = await send( [ { to: 'customer@example.com', subject: 'Logged after the option was lost', message: 'x' } ] );
		assert.deepEqual( ( await log() ).map( ( entry ) => entry.subject ), [ 'Logged after the option was lost' ], 'Old entries came back when the next email created the table again' );
		assert.equal( next.entries?.length, 1 );
		assert.deepEqual( ( await state() ).options, { safety_net_email_log_db_version: '1' } );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		// E2b's refused CREATE TABLE is the one database error Safety Net may cause.
		site.assertCleanLog( [ { pattern: REFUSED_CREATE } ] );
	} );
} );
