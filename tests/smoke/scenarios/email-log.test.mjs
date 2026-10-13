import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { after, before, describe, test } from 'node:test';
import { assertToolsAssets, assertToolsPage, firstLoad, getToolsPage, noncesForSession, postAjax, saveToolsForm, toolsPagePath } from '../lib/checks.mjs';
import { config } from '../lib/config.mjs';
import { CookieJar } from '../lib/http.mjs';
import { bootSite } from '../lib/site.mjs';

const PAGE = '/wp-admin/admin.php?page=safety_net_email_log';

const PAGE_ARGS = { page: 'safety_net_email_log' };

const SETTINGS = '/wp-admin/admin.php?page=safety_net_email_log_settings';

const SETTINGS_ARGS = { page: 'safety_net_email_log_settings' };

const SETTINGS_NOTES = [ 'Only emails sent through wp_mail() are logged. Plugins that send email another way are neither logged nor blocked.', 'The log is emptied whenever Safety Net deletes users and orders.' ];

const RESULTS_NOTE = /<div class="notice notice-info inline safety-net-email-log-notes"><p>Would send and Would fail: whether wp_mail\(\) would have sent a blocked email, [^<]*without contacting a mail server\. Sent and Failed: the real result for emails Safety Net let through\.<\/p><\/div>/;

const MENU = [ { label: 'All Emails', href: 'admin.php?page=safety_net_email_log' }, { label: 'Settings', href: 'admin.php?page=safety_net_email_log_settings' } ];

const AJAX = '/wp-admin/admin-ajax.php';

const ADMIN_POST = '/wp-admin/admin-post.php';

const TOGGLES = [ 'safety_net_email_log_toggle', 'safety_net_email_log_cleanup_toggle' ];

const NO_LOG = { table: false, options: {}, cleanup: null };

const PLAIN = 'text/plain; charset=UTF-8';

const LABELS = { blocked: 'Blocked', blocked_recipients: 'Blocked: not only administrators', allowed: 'Let through' };

const OUTCOMES = [ 'would_send', 'would_fail', 'sent', 'failed' ];

const RESULTS = { would_send: 'Would send', would_fail: 'Would fail', sent: 'Sent', failed: 'Failed', '': '—' };

const VIEWS = { all: 'All', would_send: 'Would send', would_fail: 'Would fail', sent: 'Sent', failed: 'Failed' };

const UNKNOWN_RESULT = '<span aria-hidden="true">—</span><span class="screen-reader-text">Unknown</span>';

const CSP = `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:">`;

const NO_RECIPIENT = 'You must provide at least one recipient email address.';

const EMPTY_BODY = 'Message body empty';

const MAIL_FUNCTION = 'Could not instantiate mail function.';

const INVALID_SENDER = 'Invalid address:  (From): not-a-sender';

const skipped = ( kind, value ) => ( { attachment: `Skipped attachment that cannot be read: ${ value }`, header: `Skipped invalid header: ${ value }` }[ kind ] ?? `Skipped invalid ${ kind } address: ${ value }` );

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

// A file PHPMailer could attach, written in the request that sends the email, since PHP workers may not share /tmp.
const ATTACHMENT = { path: '/tmp/sn-test-attachment.txt', name: 'sn-test-attachment.txt', content: 'SN-ATTACHMENT-CONTENT' };

const writeAttachment = `file_put_contents( '${ ATTACHMENT.path }', '${ ATTACHMENT.content }' );`;

const DRY_RUNS = [
	{ to: 'valid@example.com', subject: 'Dry run: valid', message: 'Hello — café', attachments: [ ATTACHMENT.path ] },
	{ to: 'not-an-address', subject: 'Dry run: invalid To', message: 'Hello' },
	{ to: 'valid@example.com', subject: 'Dry run: empty body', message: '' },
	{ to: 'valid@example.com, not-an-address', subject: 'Dry run: one invalid To of two', message: 'Hello' },
	{
		to: 'valid@example.com',
		subject: 'Dry run: skipped parts',
		message: 'Hello',
		headers: [ 'Cc: bad-cc', 'Cc: Good Cc <good-cc@example.com>', 'Bcc: bad-bcc@', 'Reply-To: Reply <bad reply@example.com>', 'X-Broken: one\rtwo', 'X-Fine: yes' ],
		attachments: [ '/sn-test/missing.pdf', 'https://example.com/remote.pdf', 'phar:///tmp/archive.phar/inner.txt', ATTACHMENT.path ],
	},
	{ to: 'valid@example.com', subject: 'Dry run: invalid sender', message: 'Hello', headers: [ 'From: Sender <not-a-sender>' ] },
];

const SEARCH = [
	{ to: 'needle-to@example.com', subject: 'Search by recipient', message: 'x' },
	{ to: 'search@example.com', subject: 'Search NEEDLE-SUBJECT', message: 'x' },
	{ to: 'search@example.com', subject: 'Search by body', message: 'The body hides a needle-body.' },
	{ to: 'search@example.com', subject: 'Search needle-subject with an empty body', message: '' },
	{ to: 'search@example.com', subject: 'Discount 100% off', message: 'x' },
	{ to: 'search@example.com', subject: 'Literal a_b underscore', message: 'x' },
	{ to: 'search@example.com', subject: 'Literal axb lookalike', message: 'x' },
	{ to: 'search@example.com', subject: 'Search a redacted key', message: 'https://example.test/?key=NEEDLEKEY1' },
];

// Each holds an entity, which the page must show as written, not decoded.
const LONG_ERROR = `<script>alert("error")</script> & &amp; <img src=x onerror=alert(1)> ${ 'refused '.repeat( 20 ) }end`;

const MARKUP_ATTACHMENT = '<b onmouseover="alert(1)">bold</b> &amp; co.pdf';

const ENTITY_EMAIL = { to: 'Tom &amp; Jerry <tj@example.com>', subject: 'Attachment name with markup &amp; an entity', message: 'x', headers: [ 'X-Entity: &lt;kept&gt; &amp; shown' ], attachments: { [ MARKUP_ATTACHMENT ]: '/sn-test/missing.pdf' } };

const OUTSIDE_BASEDIR = '/sn-test-outside/customer-invoice-123.pdf';

const HTML_COPY = 'text/html; charset=UTF-8';

const REFUSED_CREATE = /for query CREATE TABLE sn_test_refused_create \(/;

const COULD_NOT_RECORD = 'Safety Net: the email log could not record an email: ';

const COULD_NOT_RECORD_RESULT = 'Safety Net: the email log could not record the result of an email: ';

const COULD_NOT_CHECK = 'Safety Net: the email log could not check whether an email would be sent: ';

const COULD_NOT_REBUILD = 'Safety Net: the email log could not rebuild an email: ';

const VALIDATOR_FAILED = 'sn-test validator failed';

const escapeRegExp = ( value ) => value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

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

const pageWith = ( args = {} ) => `/wp-admin/admin.php?${ new URLSearchParams( { ...PAGE_ARGS, ...args } ) }`;

const entryPath = ( id, args = {} ) => pageWith( { ...args, entry: id } );

// The query arguments of a link or form field as the page wrote it.
const queryOf = ( url ) => Object.fromEntries( new URL( decode( url ), 'http://sn-test.invalid' ).searchParams );

const pathOf = ( url ) => {
	const parsed = new URL( decode( url ), 'http://sn-test.invalid' );
	return parsed.pathname + parsed.search;
};

const utcTime = ( loggedAt ) => Date.parse( `${ loggedAt.replace( ' ', 'T' ) }Z` );

function assertLoggedNow( entries ) {
	for ( const { subject, logged_at: loggedAt } of entries ) {
		const age = Date.now() - utcTime( loggedAt );
		assert.ok( age > -60_000 && age < 600_000, `"${ subject }" was logged at ${ loggedAt }, which is not the current UTC time` );
	}
}

// The scenario's date and time formats (Y-m-d H:i) in Asia/Tokyo, which is UTC+9 all year.
const tokyoTime = ( loggedAt ) => new Date( utcTime( loggedAt ) + 9 * 3_600_000 ).toISOString().slice( 0, 16 ).replace( 'T', ' ' );

const DAYS = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];

const MONTHS = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];

// PHPMailer's MessageDate as gmdate( 'D, j M Y H:i:s O' ) writes it.
function rawDate( loggedAt ) {
	const date = new Date( utcTime( loggedAt ) );
	const time = [ date.getUTCHours(), date.getUTCMinutes(), date.getUTCSeconds() ].map( ( part ) => String( part ).padStart( 2, '0' ) ).join( ':' );
	return `${ DAYS[ date.getUTCDay() ] }, ${ date.getUTCDate() } ${ MONTHS[ date.getUTCMonth() ] } ${ date.getUTCFullYear() } ${ time } +0000`;
}

const lines = ( value, separator ) => value.split( separator ).map( ( line ) => line.trim() ).filter( Boolean );

// What the JSON view must hold for an entry.
const entryJson = ( entry ) => ( {
	id: Number( entry.id ),
	logged_at: `${ entry.logged_at.replace( ' ', 'T' ) }Z`,
	status: entry.status,
	outcome: entry.outcome,
	error: entry.error,
	from: entry.from_address,
	to: lines( entry.recipients, ',' ),
	subject: entry.subject,
	headers: lines( entry.headers, '\n' ),
	content_type: entry.content_type,
	attachments: lines( entry.attachments, '\n' ),
	message: entry.message,
} );

// The Error column shortens an error to 120 characters and keeps the whole error in the title.
function errorShown( error ) {
	const chars = [ ...error ];
	return chars.length <= 120 ? { text: error, title: undefined } : { text: `${ chars.slice( 0, 119 ).join( '' ) }…`, title: error };
}

const matches = ( entry, term ) => [ entry.recipients, entry.subject, entry.message ].some( ( value ) => value.toLowerCase().includes( term.toLowerCase() ) );

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
	assert.ok( form, 'The Email Log Settings page has no form for the safety-net-email-log settings group' );
	return {
		html: form,
		fields: hiddenFields( form ),
		action: form.match( /^<form\b[^>]*\saction="([^"]*)"/ )?.[ 1 ],
		checkboxes: [ ...form.matchAll( /<input\b[^>]*type="checkbox"[^>]*>/g ) ].map( ( [ input ] ) => input.match( /\sname="([^"]+)"/ )?.[ 1 ] ),
	};
}

function toggles( html ) {
	return Object.fromEntries( TOGGLES.map( ( name ) => {
		const input = html.match( new RegExp( `<input id="${ name }"[^>]*>` ) )?.[ 0 ];
		assert.ok( input, `The Email Log Settings page has no ${ name } checkbox` );
		return [ name, /\schecked=/.test( input ) ];
	} ) );
}

const settingsSaved = ( html ) => ( html.match( /Settings saved\./g ) ?? [] ).length;

// The address the list's warning that logging is off links to, '' for a warning without that link, or null without a warning.
function loggingOffLink( html ) {
	if ( ! /Logging is switched off/.test( html ) ) {
		return null;
	}
	return html.match( /<div class="notice notice-warning inline"><p>\s*Logging is switched off, so new emails are not logged\. Turn it on in <a href="([^"]*)">Settings<\/a>\.\s*<\/p><\/div>/ )?.[ 1 ] ?? '';
}

// Each row of the list, with the values as the page wrote them (still escaped) in raw.
function listedEntries( html ) {
	return [ ...html.matchAll( /<tr class="safety-net-email-log-row status-([a-z_]+) outcome-([a-z_]+)">([\s\S]*?)<\/tr>/g ) ].map( ( [ , status, outcome, row ] ) => {
		const cells = Object.fromEntries( [ ...row.matchAll( /<(td|th) class='([a-z]+) column-[^']*'[^>]*>([\s\S]*?)<\/\1>/g ) ].map( ( [ , , column, cell ] ) => [ column, cell ] ) );
		const title = cells.title?.match( /<strong><a class="row-title safety-net-email-log-view" href="([^"]*)" data-entry="(\d+)">([\s\S]*?)<\/a><\/strong>/ );
		const view = cells.title?.match( /<span class='view'><a class="safety-net-email-log-view" href="([^"]*)" data-entry="(\d+)" aria-label="([^"]*)">View<\/a><\/span>/ );
		const error = cells.error?.match( /^<span class="safety-net-email-log-error"(?: title="([^"]*)")?>([\s\S]*)<\/span>$/ );
		return {
			id: title?.[ 2 ],
			status,
			outcome,
			href: title?.[ 1 ],
			view: view ? { href: view[ 1 ], id: view[ 2 ], label: view[ 3 ] } : null,
			raw: { date: cells.date, recipients: cells.recipients, subject: title?.[ 3 ], status: cells.status, outcome: cells.outcome, error: error ? error[ 2 ] : cells.error, errorTitle: error?.[ 1 ] },
		};
	} );
}

const shown = ( { raw } ) => ( {
	date: decode( raw.date ),
	recipients: decode( raw.recipients ),
	subject: decode( raw.subject ),
	status: decode( raw.status ),
	result: raw.outcome === UNKNOWN_RESULT ? '—' : decode( raw.outcome ),
	error: decode( raw.error ),
	errorTitle: raw.errorTitle === undefined ? undefined : decode( raw.errorTitle ),
} );

// Values that are missing or hold markup; an unshortened error has no title.
const escapedValues = ( rows ) => rows.flatMap( ( { raw } ) => [ raw.date, raw.recipients, raw.subject, raw.status, raw.error, raw.errorTitle ?? '' ] ).filter( ( value ) => value === undefined || /[<>"]/.test( value ) );

function views( html ) {
	return Object.fromEntries( [ ...html.matchAll( /<li class='([a-z_]+)'><a href="([^"]*)"( class="current" aria-current="page")?>([^<]*) <span class="count">\(([\d,]+)\)<\/span><\/a>/g ) ].map( ( [ , key, href, current, label, count ] ) => [ key, { href, current: Boolean( current ), label, count: Number( count.replace( /,/g, '' ) ) } ] ) );
}

const viewCounts = ( html ) => Object.fromEntries( Object.entries( views( html ) ).map( ( [ key, { count } ] ) => [ key, count ] ) );

const currentView = ( html ) => Object.entries( views( html ) ).filter( ( [ , view ] ) => view.current ).map( ( [ key ] ) => key );

function displayed( html ) {
	const count = html.match( /<span class="displaying-num">([\d,]+) items?<\/span>/ )?.[ 1 ];
	assert.ok( count !== undefined, 'The list does not say how many entries it holds' );
	return Number( count.replace( /,/g, '' ) );
}

const emptyState = ( html ) => html.match( /<span class="safety-net-email-log-empty">([^<]*)<\/span>/ )?.[ 1 ] ?? null;

const pagingLink = ( html, which ) => html.match( new RegExp( `<a class='${ which }-page button' href='([^']*)'>` ) )?.[ 1 ];

const searchedFor = ( html ) => {
	const subtitle = html.match( /<span class="subtitle">\s*Search results for: <strong>([\s\S]*?)<\/strong>\s*<\/span>/ )?.[ 1 ];
	return subtitle === undefined ? null : subtitle;
};

const searchBox = ( html ) => html.match( /<input type="search" id="safety-net-email-log-search-input" name="s" value="([^"]*)" \/>/ )?.[ 1 ];

const viewNonce = ( html ) => {
	const nonce = html.match( /<dialog id="safety-net-email-log-dialog"[^>]*\sdata-nonce="([a-f0-9]+)"/ )?.[ 1 ];
	assert.ok( nonce, 'The Email Log page has no dialog with a nonce for viewing entries' );
	return nonce;
};

const detail = ( html, label ) => html.match( new RegExp( `<th scope="row">${ label }</th>\\s*<td(?: class="[^"]*")?>([\\s\\S]*?)</td>` ) )?.[ 1 ].trim();

const notice = ( html ) => {
	const match = html.match( /<div class="notice notice-(\w+) safety-net-email-log-result"><p>([\s\S]*?)<\/p><\/div>/ );
	return match ? `${ match[ 1 ] }: ${ decode( match[ 2 ] ) }` : null;
};

const menuIds = ( html ) => [ ...html.matchAll( /<li class="[^"]*\bmenu-top\b[^"]*" id="([^"]+)">/g ) ].map( ( [ , id ] ) => id );

// The Email Log menu item with its classes, its own link and its submenu items, or null without it.
function emailLogMenu( html ) {
	const match = html.match( /<li class="([^"]*)" id="toplevel_page_safety_net_email_log">\s*(<a href='([^']*)'[^>]*>[\s\S]*?<\/a>)(?:\s*<ul class='wp-submenu wp-submenu-wrap'>([\s\S]*?)<\/ul>)?<\/li>/ );
	if ( ! match ) {
		return null;
	}
	const [ , classes, link, href, submenu = '' ] = match;
	return {
		classes: classes.split( ' ' ),
		link,
		href,
		items: [ ...submenu.matchAll( /<li(?: class="([^"]*)")?><a href='([^']*)'([^>]*)>([^<]*)<\/a><\/li>/g ) ].map( ( [ , itemClasses = '', itemHref, attributes, label ] ) => ( {
			label,
			href: itemHref,
			current: itemClasses.split( ' ' ).includes( 'current' ) && /\saria-current="page"/.test( attributes ),
		} ) ),
	};
}

// The submenu items as they must be, with the one of the current page, if any, marked current.
const menuItems = ( current = null ) => MENU.map( ( item ) => ( { ...item, current: item.label === current } ) );

async function adminCss( site, html ) {
	const url = html.match( /<link[^>]+href=['"]([^'"]*assets\/css\/admin\.css[^'"]*)['"]/ )?.[ 1 ];
	assert.ok( url, 'admin.css is not enqueued' );
	return ( await site.get( url.replace( /&#038;/g, '&' ) ) ).text;
}

// The details table and the three views of an entry, with PHPMailer's random Message-ID left out.
function entryParts( html ) {
	const table = html.match( /<table class="widefat striped safety-net-email-log-entry">[\s\S]*?<\/table>/ )?.[ 0 ];
	const panels = html.match( /<div class="safety-net-email-log-views">[\s\S]*?(?=<div class="safety-net-email-log-actions">)/ )?.[ 0 ];
	assert.ok( table && panels, 'The entry has no details table or views' );
	return { table, panels: panels.replace( /Message-ID: &lt;[^&]*&gt;/g, 'Message-ID: (random)' ) };
}

function rawView( html ) {
	const panel = html.match( /<div role="tabpanel" class="safety-net-email-log-panel safety-net-email-log-panel-raw"[\s\S]*?(?=<div role="tabpanel"|$)/ )?.[ 0 ];
	assert.ok( panel, 'The entry has no Raw panel' );
	const source = panel.match( /<pre class="safety-net-email-log-raw">([\s\S]*?)<\/pre>/ )?.[ 1 ];
	const error = panel.match( /<div class="notice notice-error inline safety-net-email-log-raw-error"><p>([\s\S]*?)<\/p><\/div>/ )?.[ 1 ];
	const note = panel.match( /<p class="description">([\s\S]*?)<\/p>/ )?.[ 1 ];
	if ( source === undefined ) {
		return { error: error === undefined ? undefined : decode( error ), panel };
	}
	assert.doesNotMatch( source, /[<>]/, 'The raw message is shown unescaped' );
	const text = decode( source ).replace( /\r\n/g, '\n' );
	const split = text.indexOf( '\n\n' );
	const headers = {};
	for ( const line of text.slice( 0, split ).split( '\n' ) ) {
		headers[ line.slice( 0, line.indexOf( ':' ) ) ] = line.slice( line.indexOf( ':' ) + 2 );
	}
	return { headers, body: text.slice( split + 2 ).replace( /\n$/, '' ), text, note: note === undefined ? undefined : decode( note ), error, panel };
}

function jsonView( html ) {
	const pre = html.match( /<pre class="safety-net-email-log-json">([\s\S]*?)<\/pre>/ )?.[ 1 ];
	assert.ok( pre !== undefined, 'The entry has no JSON view' );
	assert.doesNotMatch( pre, /[<>]/, 'The JSON view is shown unescaped' );
	return { text: decode( pre ), value: JSON.parse( decode( pre ) ) };
}

function redirectArgs( site, res ) {
	assert.ok( res.location, `The action did not redirect (HTTP ${ res.status })` );
	const url = new URL( res.location, site.url );
	assert.equal( url.pathname, '/wp-admin/admin.php', `The action redirected to ${ res.location }` );
	return Object.fromEntries( url.searchParams );
}

function capturedMail( site ) {
	const file = path.join( site.outDir, 'mail.jsonl' );
	return existsSync( file ) ? readFileSync( file, 'utf8' ).split( '\n' ).filter( Boolean ).map( ( line ) => JSON.parse( line ) ) : [];
}

function parseJson( res ) {
	try {
		return JSON.parse( res.text );
	} catch {
		assert.fail( `The response is not JSON: ${ res.text.slice( 0, 500 ) }` );
	}
}

describe( 'email-log: the email log of a regular plugin on a staging site', () => {
	let site;
	let admin;
	let defaultFrom;

	const log = ( after = 0 ) => site.php( `return sn_test_email_log( ${ after } );`, { label: 'reading the email log' } );
	const state = () => site.php( 'return sn_test_email_log_state();', { label: 'reading the email log\'s table, options and cleanup event' } );
	const send = ( emails, label = 'sending emails' ) => site.php( `return sn_test_send_emails( ${ phpValue( emails ) } );`, { label } );
	const sendForResults = ( emails, label = 'sending emails through to wp_mail()\'s result' ) => site.php( `return sn_test_send_for_results( ${ phpValue( emails ) } );`, { label } );
	const capture = ( value ) => site.php( value ? `update_option( 'sn_test_capture_mail', ${ phpValue( value ) } ); return true;` : "delete_option( 'sn_test_capture_mail' ); return true;", { label: value ? 'capturing email in every request' : 'no longer capturing email' } );
	const seedAged = ( ages ) => site.php( `
global $wpdb;
$ids = array();
foreach ( ${ phpValue( ages ) } as $subject => $age ) {
	$wpdb->insert( $wpdb->prefix . 'safety_net_email_log', array( 'logged_at' => gmdate( 'Y-m-d H:i:s', strtotime( $age ) ), 'status' => 'blocked', 'outcome' => 'would_send', 'error' => '', 'recipients' => 'aged@example.com', 'subject' => $subject, 'message' => '', 'headers' => '', 'from_address' => '', 'content_type' => '', 'attachments' => '' ) );
	$ids[ $subject ] = $wpdb->insert_id;
}
return $ids;`, { label: 'adding entries of different ages' } );
	const aged = async () => ( await log() ).filter( ( entry ) => entry.recipients === 'aged@example.com' ).map( ( entry ) => entry.subject );
	const safetyNetDbErrors = ( mark ) => site.logEntriesSince( mark ).filter( ( line ) => /WordPress database error/.test( line ) && /SafetyNet\\/.test( line ) );
	const ajaxEntry = ( id, nonce, { jar = site.adminJar, expect = 200 } = {} ) => site.get( `${ AJAX }?${ new URLSearchParams( { action: 'safety_net_email_log_entry', entry: String( id ), ...( nonce === null ? {} : { _wpnonce: nonce } ) } ) }`, { jar, expect } );

	// One read of the log for every subject, the first entry with each.
	async function entriesBySubject( subjects ) {
		const entries = await log();
		return subjects.map( ( subject ) => {
			const entry = entries.find( ( candidate ) => candidate.subject === subject );
			assert.ok( entry, `No log entry has the subject "${ subject }"` );
			return entry;
		} );
	}

	const entryBySubject = async ( subject ) => ( await entriesBySubject( [ subject ] ) )[ 0 ];

	async function createUser( login, role ) {
		return site.php( `$id = sn_test_create_user( '${ login }', '${ role }' ); wp_set_password( 'password', $id ); return $id;`, { label: `creating the ${ role } ${ login } with a known password` } );
	}

	const deleteUser = ( id ) => site.php( `require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( ${ id } ); return true;`, { label: `deleting user ${ id }` } );

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

	test( 'E21: Email Log is a top-level menu item right after Tools for administrators, with All Emails and Settings under it, each current on its own page and with the assets; Tools > Safety Net stays and only links to it, and a subscriber gets none of them', async () => {
		const [ dashboard, page, settings, tools, oldTab ] = await site.getAll( [ '/wp-admin/', PAGE, SETTINGS, toolsPagePath(), `${ toolsPagePath() }&tab=email-log` ].map( ( target ) => ( { path: target, jar: site.adminJar } ) ) );
		const menu = menuIds( dashboard.text );
		assert.ok( menu.includes( 'menu-tools' ), `The admin menu has no Tools item, so its order proves nothing: ${ menu.join( ', ' ) }` );
		assert.equal( menu[ menu.indexOf( 'menu-tools' ) + 1 ], 'toplevel_page_safety_net_email_log', `The Email Log menu item does not come right after Tools: ${ menu.join( ', ' ) }` );
		const item = emailLogMenu( dashboard.text );
		assert.ok( item, 'The dashboard has no Email Log menu item' );
		assert.equal( item.href, 'admin.php?page=safety_net_email_log', 'The Email Log menu item does not link to the list' );
		assert.match( item.link, /\bdashicons-email-alt\b/, 'The Email Log menu item has the wrong icon' );
		assert.match( item.link, /<div class='wp-menu-name'>Email Log<\/div>/ );
		assert.ok( item.classes.includes( 'wp-not-current-submenu' ), `The Email Log menu item is open on the dashboard: ${ item.classes.join( ' ' ) }` );
		assert.deepEqual( item.items, menuItems(), 'The Email Log menu item does not hold exactly All Emails and Settings, linking to their pages, or one is current on the dashboard' );
		const toolsMenu = dashboard.text.match( /<li class="[^"]*" id="menu-tools">[\s\S]*?<\/ul><\/li>/ )?.[ 0 ] ?? '';
		assert.match( toolsMenu, /<a href='(?:tools|admin)\.php\?page=safety_net_options'[^>]*>Safety Net<\/a>/, 'Tools > Safety Net is no longer in the Tools menu' );
		assert.doesNotMatch( dashboard.text, /assets\/(?:js\/safety-net-admin\.js|css\/admin\.css)/, 'The Safety Net assets load on the dashboard' );

		assert.match( page.text, /<title>Safety Net Email Log &lsaquo; /, 'The Email Log page has the wrong title' );
		assert.equal( ( page.text.match( /<h1\b/g ) ?? [] ).length, 1, 'The list is rendered more than once, or has no heading' );
		assert.match( page.text, /<h1 class="wp-heading-inline">Safety Net Email Log<\/h1>/ );
		assert.match( settings.text, /<title>Email Log Settings &lsaquo; /, 'The Email Log Settings page has the wrong title' );
		assert.equal( ( settings.text.match( /<h1\b/g ) ?? [] ).length, 1, 'The Settings page is rendered more than once, or has no heading' );
		assert.match( settings.text, /<div class="wrap safety-net-email-log-settings-page">\s*<h1>Email Log Settings<\/h1>/ );
		for ( const [ res, current ] of [ [ page, 'All Emails' ], [ settings, 'Settings' ] ] ) {
			const own = emailLogMenu( res.text );
			assert.ok( own?.classes.includes( 'wp-has-current-submenu' ) && own.classes.includes( 'wp-menu-open' ), `The Email Log menu item is not open on the ${ current } page: ${ own?.classes.join( ' ' ) }` );
			assert.deepEqual( own.items, menuItems( current ), `${ current } is not the one current submenu item on its page` );
		}
		await assertToolsAssets( site, page.text, '/wp-content/plugins/safety-net/', 'the Email Log page' );
		await assertToolsAssets( site, settings.text, '/wp-content/plugins/safety-net/', 'the Email Log Settings page' );
		assert.match( await adminCss( site, settings.text ), /input\[type="checkbox"\]\.safety-net-toggle:checked:after\s*\{[^}]*content: 'ON'/, 'admin.css no longer draws the switches as toggles' );
		assert.deepEqual( TOGGLES.filter( ( name ) => ! new RegExp( `<input id="${ name }" class="safety-net-toggle"` ).test( settings.text ) ), [], 'A switch on the Settings page lacks the class admin.css draws as a toggle' );
		const params = page.text.match( /\bsafety_net_params = (\{[^;]*\});/ )?.[ 1 ];
		assert.ok( params, 'The Email Log page does not give its script safety_net_params, so the popup cannot load entries' );
		assert.equal( new URL( JSON.parse( params ).ajax_url ).pathname, '/wp-admin/admin-ajax.php', 'safety_net_params does not point the popup at admin-ajax.php' );
		const a11y = page.text.search( /<script\b[^>]*\sid=['"]wp-a11y-js['"]/ );
		assert.ok( a11y !== -1 && a11y < page.text.search( /<script\b[^>]*\sid=['"]safety-net-admin-js['"]/ ), 'wp.a11y, which the popup announces its state with, is not loaded before the script' );

		assertToolsPage( tools );
		const link = tools.text.match( /<p class="safety-net-email-log-link">([\s\S]*?)<\/p>/ )?.[ 1 ];
		assert.ok( link, 'The Tools page does not link to the Email Log page' );
		const href = link.match( /<a href="([^"]*)">Email Log<\/a>/ )?.[ 1 ];
		assert.ok( href, 'The Tools page\'s link to the Email Log page has no "Email Log" link' );
		assert.deepEqual( [ new URL( decode( href ) ).pathname, queryOf( href ) ], [ '/wp-admin/admin.php', PAGE_ARGS ] );
		assert.doesNotMatch( tools.text, /nav-tab/, 'The Tools page still has tabs' );
		assert.match( tools.text, /<h3>Tools<\/h3>\s*<form action="options.php" method="post">/, 'The Tools page lost its form' );
		assert.doesNotMatch( tools.text, /safety_net_email_log_(?:cleanup_)?toggle/, 'The Tools page holds an email log switch, which saving its form would switch off' );
		assert.doesNotMatch( oldTab.text, /safety-net-email-log-(?:settings|filter|page|empty)/, 'The round-1 tab address still shows the log on the Tools page' );
		assert.match( oldTab.text, /<h3>Tools<\/h3>\s*<form action="options.php" method="post">/ );

		const subscriber = await createUser( 'menu_subscriber', 'subscriber' );
		try {
			const jar = await site.login( { user: 'menu_subscriber', jar: new CookieJar() } );
			const own = await site.get( '/wp-admin/', { jar } );
			assert.ok( menuIds( own.text ).includes( 'menu-dashboard' ), 'The subscriber got no admin menu, so this proves nothing' );
			assert.equal( emailLogMenu( own.text ), null, 'A subscriber sees the Email Log menu item' );
			assert.doesNotMatch( own.text, /page=safety_net_email_log/, 'A subscriber gets a link to an Email Log page' );
			for ( const target of [ PAGE, SETTINGS ] ) {
				assert.match( ( await site.get( target, { jar, expect: 403 } ) ).text, /Sorry, you are not allowed to access this page\./, `A subscriber can open ${ target }` );
			}
		} finally {
			await deleteUser( subscriber );
		}
	} );

	test( 'E21b: with the Email Log menu title translated, which changes the Settings page\'s hook suffix, the Settings page still gets the assets', async () => {
		await site.php( "update_option( 'sn_test_translate', 1 ); return true;", { label: 'translating the Email Log menu title' } );
		try {
			const settings = ( await site.get( SETTINGS, { jar: site.adminJar } ) ).text;
			assert.match( emailLogMenu( settings )?.link ?? '', /<div class='wp-menu-name'>Journal des e-mails<\/div>/, 'The Email Log menu title is not translated, so this proves nothing' );
			assert.match( settings, /\badminpage = 'journal-des-e-mails_page_safety_net_email_log_settings'/, 'The Settings page\'s hook suffix does not come from the translated menu title' );
			await assertToolsAssets( site, settings, '/wp-content/plugins/safety-net/', 'the Email Log Settings page with a translated menu title' );
		} finally {
			await site.php( "delete_option( 'sn_test_translate' ); return true;", { label: 'removing the translation' } );
		}
	} );

	test( 'E2: with logging switched off, emails are still blocked or let through, no table, option or cleanup event is created, and the empty list warns that logging is off', async () => {
		await site.php( "update_option( 'safety_net_email_log_toggle', 'off' ); return true;", { label: 'switching logging off' } );
		try {
			const sent = await send( [ { to: 'someone@example.com', subject: 'Sent while logging is off', message: 'x' }, { ...RESET, message: 'x' } ] );
			assert.deepEqual( sent, { verdicts: [ false, null ], entries: null }, 'Safety Net did not block the regular email and let the password reset through (false, null), or the log table was created' );
			assert.deepEqual( await state(), { ...NO_LOG, options: { safety_net_email_log_toggle: 'off' } }, 'Logging switched off created the table, its version option or the cleanup event' );
			const [ page, settings ] = await site.getAll( [ PAGE, SETTINGS ].map( ( target ) => ( { path: target, jar: site.adminJar } ) ) );
			assert.deepEqual( toggles( settings.text ), { safety_net_email_log_toggle: false, safety_net_email_log_cleanup_toggle: true } );
			assert.equal( emptyState( page.text ), 'No emails have been logged.', 'The Email Log page without a table does not say the log is empty' );
			assert.equal( pathOf( loggingOffLink( page.text ) ?? '' ), SETTINGS, 'The list without a table does not warn that logging is off, with a link to the Settings page' );
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

	test( 'E3: an email sent before init can be the first one, and creates the log with its entry and its dry run', async () => {
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
		const early = { recipients: 'someone@example.com', message: 'body', headers: '', from_address: defaultFrom, content_type: PLAIN, attachments: '', error: '' };
		// The password reset is stopped by the capture's pre_wp_mail filter, so wp_mail() never reports a result for it.
		assert.deepEqual( s.entries.map( withoutIdAndDate ), [ { status: 'blocked', outcome: 'would_send', ...early, subject: 'Early order receipt' }, { status: 'allowed', outcome: '', ...early, subject: 'Early Password Reset' } ] );
		assertLoggedNow( s.entries );
		assert.deepEqual( s.state, { table: true, options: { safety_net_email_log_db_version: '1' }, cleanup: { due: true, schedule: 'daily' } }, 'The first email did not record the table version, or init did not schedule the daily cleanup once the table existed' );
	} );

	test( 'E3b: a sender filter that throws while an email is logged changes no decision, logs no entry and is reported in debug.log', async () => {
		const mark = site.logMark();
		const sent = await site.php( `$throw = static function () { throw new RuntimeException( 'sn-test sender filter failed' ); }; add_filter( 'wp_mail_from', $throw ); try { return sn_test_send_emails( ${ phpValue( [ { to: 'someone@example.com', subject: 'Sent while a sender filter throws', message: 'x' }, { to: 'someone@example.com', subject: RESET.subject, message: 'x' } ] ) } ); } finally { remove_filter( 'wp_mail_from', $throw ); }`, { label: 'sending emails while a wp_mail_from filter throws' } );
		assert.deepEqual( sent, { verdicts: [ false, null ], entries: [] }, 'A throwing filter changed Safety Net\'s decisions, or an entry was logged' );
		assert.equal( site.logEntriesSince( mark ).filter( ( line ) => line.includes( `${ COULD_NOT_RECORD }sn-test sender filter failed` ) ).length, 2, 'debug.log does not report each email the log could not record' );
	} );

	test( 'E3c: with the table gone but its option left, the page, the cleanup and Clear log forget the table without a database error, and the next emails create it again', async () => {
		const mark = site.logMark();
		const dropped = await site.php( "global $wpdb; $wpdb->query( \"DROP TABLE {$wpdb->prefix}safety_net_email_log\" ); return sn_test_email_log_state();", { label: 'dropping the email log table and keeping its option' } );
		assert.deepEqual( [ dropped.table, dropped.options ], [ false, { safety_net_email_log_db_version: '1' } ], 'Dropping the table failed, or its option is gone, so this proves nothing' );

		assert.equal( emptyState( ( await site.get( PAGE, { jar: site.adminJar } ) ).text ), 'No emails have been logged.', 'The Email Log page without its table does not show an empty log' );
		assert.deepEqual( ( await state() ).options, {}, 'Opening the Email Log page did not forget the missing table' );
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

	test( 'E4: each email is recorded with Safety Net\'s decision, its dry run, its recipients, subject, message, headers and attachment names only', async () => {
		const sent = await send( [ ORDER, RESET, PAIR ] );
		assert.deepEqual( sent.verdicts, [ false, null, false ], 'Safety Net did not block the regular emails and let the password reset through' );
		const defaults = { from_address: defaultFrom, content_type: PLAIN };
		assert.deepEqual( sent.entries?.map( withoutIdAndDate ), [
			{ status: 'blocked', outcome: 'would_send', error: [ skipped( 'attachment', 'report.csv' ), skipped( 'attachment', 'invoice-1.pdf' ) ].join( '\n' ), recipients: 'one@example.com, Two <two@example.com>', subject: ORDER.subject, message: ORDER.message, headers: 'Cc: cc@example.com\nBcc: bcc@example.com\nReply-To: Shop <reply@example.com>', ...defaults, attachments: 'report.csv\ninvoice-1.pdf' },
			{ status: 'allowed', outcome: '', error: '', recipients: RESET.to, subject: RESET.subject, message: RESET.message.replace( 'SECRETKEY1', '[redacted]' ), headers: 'X-SN-Test: reset', ...defaults, attachments: 'one.txt\ntwo.txt' },
			{ status: 'blocked', outcome: 'would_fail', error: EMPTY_BODY, recipients: 'a@example.com, b@example.com', subject: PAIR.subject, message: '', headers: '', ...defaults, attachments: '' },
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
	'outcomes' => array_column( (array) $sent['entries'], 'outcome' ),
	'invalid'  => $invalid ? array( 'valid' => mb_check_encoding( $invalid['subject'] . $invalid['message'] . $invalid['headers'], 'UTF-8' ), 'subject' => $invalid['subject'], 'message' => $invalid['message'] ) : null,
	'cut'      => $cut ? array( 'valid' => mb_check_encoding( $cut['subject'] . $cut['headers'], 'UTF-8' ), 'subject' => strlen( $cut['subject'] ), 'prefix' => str_starts_with( $long, $cut['subject'] ), 'message' => $cut['message'] === $long, 'headers' => strlen( $cut['headers'] ) ) : null,
);`, { label: 'sending emails with invalid UTF-8 and over-long text' } );
		assert.deepEqual( [ s.verdicts, s.count ], [ [ false, false ], 2 ], 'An email with invalid UTF-8 or over-long text was not blocked and logged' );
		assert.deepEqual( s.outcomes, [ 'would_send', 'would_send' ], 'The dry run did not check an email with invalid UTF-8 or over-long text' );
		assert.equal( s.invalid.valid, true, 'Invalid UTF-8 was stored' );
		assert.ok( s.invalid.subject.startsWith( 'Invalid ' ) && s.invalid.subject.endsWith( '( UTF-8' ) && s.invalid.message.startsWith( 'Body ' ) && s.invalid.message.endsWith( ' end' ), `Replacing invalid UTF-8 lost the valid text: ${ JSON.stringify( s.invalid ) }` );
		assert.deepEqual( s.cut, { valid: true, subject: 65534, prefix: true, message: true, headers: 65535 }, 'Text over 64 KB was not cut on a character boundary to fit its column, or the message, which has no such limit, was cut' );
	} );

	test( 'E5: while SAFETY_NET_DELETE_DATA keeps data, a password reset to someone who is not an administrator is recorded as blocked_recipients, with a dry run', async () => {
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
		assert.deepEqual( kept.sent.entries?.map( ( entry ) => [ entry.recipients, entry.status, entry.outcome ] ), [ [ 'customer@example.com', 'blocked_recipients', 'would_send' ], [ admin, 'allowed', '' ] ] );
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
		assert.deepEqual( sent.entries?.map( ( entry ) => [ entry.subject, entry.from_address, entry.content_type, entry.outcome ] ), [
			[ WOO.subject, 'Example Shop <shop@example.com>', 'text/html; charset=UTF-8', 'would_send' ],
			[ WOO_MULTIPART.subject, 'Example Shop <shop@example.com>', 'multipart/alternative; charset=UTF-8', 'would_send' ],
			[ 'Sent with a wp_mail_charset filter', defaultFrom, 'text/plain; charset=ISO-8859-1', 'would_send' ],
			[ 'Sent after the WooCommerce filters were removed', defaultFrom, PLAIN, 'would_send' ],
			[ 'Sender and type from the headers', 'Named Sender <named@example.com>', 'text/html; charset=ISO-8859-1', 'would_send' ],
			[ 'Bare From header', 'WordPress <bare@example.com>', PLAIN, 'would_send' ],
		] );
		assert.deepEqual( withoutIdAndDate( sent.entries[ 0 ] ), {
			status: 'blocked',
			outcome: 'would_send',
			error: skipped( 'attachment', 'terms.pdf' ),
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

	test( 'E22: a blocked email gets a dry run: Would send when PHPMailer would accept it, Would fail with PHPMailer\'s error when it would not, and notes on what wp_mail() would skip', async () => {
		const sent = await site.php( `${ writeAttachment } return sn_test_send_emails( ${ phpValue( DRY_RUNS ) } );`, { label: 'sending emails for the dry run' } );
		assert.deepEqual( sent.verdicts, DRY_RUNS.map( () => false ), 'Safety Net did not block every email' );
		assert.deepEqual( sent.entries?.map( ( entry ) => [ entry.subject, entry.status, entry.outcome, entry.error ] ), [
			[ 'Dry run: valid', 'blocked', 'would_send', '' ],
			[ 'Dry run: invalid To', 'blocked', 'would_fail', [ NO_RECIPIENT, skipped( 'To', 'not-an-address' ) ].join( '\n' ) ],
			[ 'Dry run: empty body', 'blocked', 'would_fail', EMPTY_BODY ],
			[ 'Dry run: one invalid To of two', 'blocked', 'would_send', skipped( 'To', 'not-an-address' ) ],
			[ 'Dry run: skipped parts', 'blocked', 'would_send', [
				skipped( 'Cc', 'bad-cc' ),
				skipped( 'Bcc', 'bad-bcc@' ),
				skipped( 'Reply-To', 'bad reply@example.com' ),
				skipped( 'header', 'X-Broken' ),
				skipped( 'attachment', 'missing.pdf' ),
				skipped( 'attachment', 'remote.pdf' ),
				skipped( 'attachment', 'inner.txt' ),
			].join( '\n' ) ],
			[ 'Dry run: invalid sender', 'blocked', 'would_fail', INVALID_SENDER ],
		], 'The dry run did not report what PHPMailer would refuse, or what wp_mail() would skip, as wp_mail() would' );
		assert.equal( sent.entries[ 0 ].attachments, ATTACHMENT.name, 'The readable attachment was not logged by name, so this proves nothing about it' );
	} );

	test( 'E22b: the dry run never opens a URL or a stream wrapper path, and notes an attachment outside open_basedir without a PHP warning that names its path', async () => {
		const wrapped = { to: 'valid@example.com', subject: 'Dry run: stream wrapper attachment', message: 'Hello', attachments: [ 'sntest://host/file.pdf' ] };
		const restricted = { to: 'valid@example.com', subject: 'Dry run: attachment outside open_basedir', message: 'Hello', attachments: [ OUTSIDE_BASEDIR ] };
		const s = await site.php( `
if ( ! class_exists( 'SN_Test_Stat_Recorder', false ) ) {
	class SN_Test_Stat_Recorder {
		public static $stats = array();
		public $context;

		public function url_stat( $path, $flags ) {
			self::$stats[] = $path;
			return false;
		}
	}
}
stream_wrapper_register( 'sntest', 'SN_Test_Stat_Recorder' );
try {
	$wrapped = sn_test_send_emails( ${ phpValue( [ wrapped ] ) } );
} finally {
	stream_wrapper_unregister( 'sntest' );
}

// Last in this load, since open_basedir can only be narrowed until the load ends.
ini_set( 'open_basedir', implode( PATH_SEPARATOR, array( ABSPATH, SN_TEST_OUT, '/fixtures', '/internal', sys_get_temp_dir() ) ) );
$warnings = array();
set_error_handler(
	static function ( $errno, $message ) use ( &$warnings ) {
		$warnings[] = $message;
		return true;
	}
);
try {
	$restricted = sn_test_send_emails( ${ phpValue( [ restricted ] ) } );
	$during     = $warnings;
	is_file( '${ OUTSIDE_BASEDIR }' );
} finally {
	restore_error_handler();
}
return array(
	'wrapped'    => $wrapped,
	'stats'      => SN_Test_Stat_Recorder::$stats,
	'restricted' => $restricted,
	'warnings'   => array_values( array_filter( $during, static fn( $message ) => str_contains( $message, 'open_basedir' ) || str_contains( $message, 'sn-test-outside' ) ) ),
	'proof'      => array_slice( $warnings, count( $during ) ),
);`, { label: 'sending blocked emails with a stream wrapper attachment and one outside open_basedir' } );
		assert.deepEqual( [ s.wrapped.verdicts, s.restricted.verdicts ], [ [ false ], [ false ] ], 'Safety Net did not block the emails' );
		assert.deepEqual( s.wrapped.entries?.map( ( entry ) => [ entry.subject, entry.outcome, entry.error ] ), [ [ wrapped.subject, 'would_send', skipped( 'attachment', 'file.pdf' ) ] ], 'The stream wrapper attachment was not noted as one wp_mail() would skip' );
		assert.deepEqual( s.stats, [], 'The dry run opened a stream wrapper path, which could open a phar:// archive or connect to a remote host' );
		assert.equal( s.proof.length, 1, `open_basedir did not refuse a path outside it, so this proves nothing: ${ JSON.stringify( s.proof ) }` );
		assert.match( s.proof[ 0 ], /open_basedir restriction in effect/ );
		assert.deepEqual( s.restricted.entries?.map( ( entry ) => [ entry.subject, entry.outcome, entry.error ] ), [ [ restricted.subject, 'would_send', skipped( 'attachment', 'customer-invoice-123.pdf' ) ] ], 'The attachment outside open_basedir was not noted as one wp_mail() would skip' );
		assert.deepEqual( s.warnings, [], 'The dry run raised a PHP warning with the path of an attachment outside open_basedir' );
	} );

	test( 'E23: the dry run and the Raw view only run PHPMailer\'s checks: they never send, attach no file, fire no mail hook, leave wp_mail()\'s mailer alone and put PHPMailer\'s validator back', async () => {
		const order = await entryBySubject( ORDER.subject );
		const emails = [
			{ to: 'valid@example.com', subject: 'Recorded dry run: valid', message: 'Hello', attachments: [ ATTACHMENT.path ], headers: [ 'Cc: cc@example.com' ] },
			{ to: 'not-an-address', subject: 'Recorded dry run: invalid To', message: 'Hello' },
			{ to: 'valid@example.com', subject: 'Recorded dry run: HTML', message: '<p>Hello</p>', headers: [ 'Content-Type: text/html' ] },
		];
		const s = await site.php( `${ writeAttachment } return sn_test_record_mailers( ${ phpValue( emails ) }, ${ order.id } );`, { label: 'sending blocked emails and rebuilding a raw message with a mailer that records its calls' } );
		assert.deepEqual( s.sent, [ false, false, false ], 'Safety Net did not block the emails' );
		assert.deepEqual( s.entries.map( ( entry ) => [ entry.subject, entry.outcome, entry.error ] ), [
			[ 'Recorded dry run: valid', 'would_send', '' ],
			[ 'Recorded dry run: invalid To', 'would_fail', [ NO_RECIPIENT, skipped( 'To', 'not-an-address' ) ].join( '\n' ) ],
			[ 'Recorded dry run: HTML', 'would_send', '' ],
		], 'The dry run did not check the emails with the recording mailer, so this proves nothing' );
		assert.deepEqual( s.dry_run, [ 'preSend', 'preSend', 'preSend' ], 'The dry run did more than preSend() with its own mailer: it sent, connected or attached a file' );
		assert.equal( s.raw_built, true, 'The Raw view was not rebuilt with the recording mailer, so this proves nothing' );
		assert.deepEqual( s.raw, [ 'preSend' ], 'Rebuilding the Raw view did more than preSend() with its own mailer' );
		assert.deepEqual( s.hooks, { phpmailer_init: 0, wp_mail_succeeded: 0, wp_mail_failed: 0 }, 'The dry run or the Raw view fired a mail hook' );
		assert.deepEqual( s.outer, { same: true, to: [ 'outer@example.com' ], subject: 'Outer' }, 'The dry run or the Raw view replaced or changed the mailer wp_mail() keeps in $GLOBALS[\'phpmailer\']' );
		assert.equal( s.validator, true, 'The dry run did not put PHPMailer\'s validator back' );
	} );

	test( 'E23b: a dry run that fails for another reason than PHPMailer refusing the email logs the entry without a result, reports it in debug.log and puts PHPMailer\'s validator back', async () => {
		const mark = site.logMark();
		const email = { to: 'valid@example.com', subject: 'Dry run: the is_email filter throws', message: 'Hello' };
		const s = await site.php( `
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
$validator = static fn( $email ) => (bool) is_email( $email );
PHPMailer\\PHPMailer\\PHPMailer::$validator = $validator;
$throw = static function () {
	throw new RuntimeException( '${ VALIDATOR_FAILED }' );
};
add_filter( 'is_email', $throw );
try {
	$sent = sn_test_send_emails( ${ phpValue( [ email ] ) } );
} finally {
	remove_filter( 'is_email', $throw );
}
return array(
	'sent'      => $sent,
	'validator' => PHPMailer\\PHPMailer\\PHPMailer::$validator === $validator,
);`, { label: 'sending a blocked email while an is_email filter throws' } );
		assert.deepEqual( s.sent.verdicts, [ false ], 'A dry run that failed changed Safety Net\'s decision' );
		assert.deepEqual( s.sent.entries?.map( ( entry ) => [ entry.subject, entry.status, entry.outcome, entry.error ] ), [ [ email.subject, 'blocked', '', '' ] ], 'The email whose dry run failed was not logged without a result' );
		assert.equal( s.validator, true, 'The dry run did not put PHPMailer\'s validator back after it threw' );
		assert.deepEqual( site.logEntriesSince( mark ).filter( ( line ) => line.includes( COULD_NOT_CHECK ) ).map( ( line ) => line.slice( line.indexOf( COULD_NOT_CHECK ) ).trim() ), [ `${ COULD_NOT_CHECK }${ VALIDATOR_FAILED }` ], 'debug.log does not report the one dry run that failed' );
		const view = ( await site.get( entryPath( s.sent.entries[ 0 ].id ), { jar: site.adminJar } ) ).text;
		assert.equal( detail( view, 'Result' ), UNKNOWN_RESULT, 'An entry without a result does not say so to screen readers' );
	} );

	test( 'E24: an email Safety Net lets through gets wp_mail()\'s real result: Failed with PHPMailer\'s error, Sent, or none when a later filter stops it', async () => {
		const s = await sendForResults( [
			{ to: 'someone@example.com', subject: '[Site] Password Reset through mail()', deliver: 'mail' },
			{ to: 'someone@example.com', subject: '[Site] Password Reset delivered' },
			{ to: 'someone@example.com', subject: '[Site] Password Reset refused', refuse: 'sn-test: the mail server refused the email' },
			{ to: 'someone@example.com', subject: '[Site] Password Reset from an invalid sender', from: 'not-a-sender' },
			{ to: 'someone@example.com', subject: '[Site] Password Reset stopped by a later filter', stop: true },
			{ to: 'someone@example.com', subject: 'Blocked while results are recorded' },
		] );
		assert.deepEqual( s.results.map( ( result ) => result.sent ), [ false, true, false, false, false, false ], 'wp_mail() did not return what each email\'s mailer did, so the results below prove nothing' );
		assert.deepEqual( s.delivered, [ '[Site] Password Reset delivered' ] );
		assert.deepEqual( s.hooks, { wp_mail_succeeded: 1, wp_mail_failed: 3, phpmailer_init: 3 }, 'wp_mail() did not report one success and three failures' );
		assert.deepEqual( s.entries?.map( ( entry ) => [ entry.subject, entry.status, entry.outcome, entry.error ] ), [
			[ '[Site] Password Reset through mail()', 'allowed', 'failed', MAIL_FUNCTION ],
			[ '[Site] Password Reset delivered', 'allowed', 'sent', '' ],
			[ '[Site] Password Reset refused', 'allowed', 'failed', 'sn-test: the mail server refused the email' ],
			[ '[Site] Password Reset from an invalid sender', 'allowed', 'failed', INVALID_SENDER ],
			[ '[Site] Password Reset stopped by a later filter', 'allowed', '', '' ],
			[ 'Blocked while results are recorded', 'blocked', 'would_send', '' ],
		], 'An email let through does not hold the result wp_mail() reported for it' );
	} );

	test( 'E24b: the result of a Send to me copy updates no entry, not even one still waiting for the result of an email with the same recipient and subject', async () => {
		const reset = await entryBySubject( RESET.subject );
		const s = await site.php( `
$admin   = get_userdata( 1 )->user_email;
$subject = '[Safety Net copy] ' . SafetyNet\\EmailLog\\get_entry( ${ reset.id } )->subject;
$waiting = sn_test_send_for_results( array( array( 'to' => $admin, 'subject' => $subject, 'stop' => true ) ) );
$pending = SafetyNet\\EmailLog\\pending_results();
$before  = did_action( 'wp_mail_succeeded' );
$copy    = sn_test_send_copy( ${ reset.id } );
return array(
	'waiting'   => $waiting['entries'],
	'pending'   => $pending,
	'key'       => SafetyNet\\EmailLog\\mail_key( array( 'to' => $admin, 'subject' => $subject ) ),
	'succeeded' => did_action( 'wp_mail_succeeded' ) - $before,
	'copy'      => array( 'sent' => $copy['sent'], 'delivered' => $copy['delivered'], 'recipients' => $copy['recipients'], 'entries' => $copy['entries'] ),
	'after'     => SafetyNet\\EmailLog\\pending_results(),
	'entry'     => sn_test_email_log( (int) $waiting['entries'][0]['id'] - 1 )[0] ?? null,
);`, { label: 'sending a copy while an email with the same recipient and subject waits for its result' } );
		const [ waiting ] = s.waiting;
		assert.deepEqual( [ s.waiting.length, waiting.status, waiting.outcome ], [ 1, 'allowed', '' ], 'The email with the copy\'s recipient and subject was not logged as let through and waiting' );
		assert.deepEqual( s.pending.filter( ( item ) => item.key === s.key ).map( ( item ) => String( item.id ) ), [ waiting.id ], 'The waiting email is not on the stack with the copy\'s recipient and subject, so this proves nothing' );
		assert.deepEqual( s.copy, { sent: true, delivered: 1, recipients: [ admin ], entries: [] }, 'The copy was not sent to the administrator alone, or was logged' );
		assert.equal( s.succeeded, 1, 'wp_mail() did not report the copy as sent, so this proves nothing' );
		assert.deepEqual( [ s.entry?.id, s.entry?.outcome, s.entry?.error ], [ waiting.id, '', '' ], 'The copy\'s result was recorded in the entry of another email' );
		assert.ok( s.after.some( ( item ) => String( item.id ) === waiting.id ), 'The copy\'s result took the waiting email off the stack' );
	} );

	test( 'E25: nested emails get their own results, last in first out, and a result that comes after logging was switched off, the table was forgotten or its update is refused changes nothing and throws nothing', async () => {
		const mark = site.logMark();
		const s = await sendForResults( [
			{ to: 'someone@example.com', subject: '[Site] Password Reset outer', nested: [
				{ to: 'someone@example.com', subject: '[Site] Password Reset inner refused', refuse: 'sn-test: the inner email was refused' },
				{ to: 'someone@example.com', subject: '[Site] Password Reset inner stopped', stop: true },
				{ to: 'someone@example.com', subject: '[Site] Password Reset inner delivered' },
			] },
			{ to: 'someone@example.com', subject: '[Site] Password Reset twice', nested: [
				{ to: 'someone@example.com', subject: '[Site] Password Reset twice', refuse: 'sn-test: the inner one of the two was refused' },
			] },
			{ to: 'someone@example.com', subject: '[Site] Password Reset while logging is switched off', during: 'logging_off' },
			{ to: 'someone@example.com', subject: '[Site] Password Reset while the table is forgotten', during: 'forget_table' },
			{ to: 'someone@example.com', subject: '[Site] Password Reset whose update is refused', during: 'refuse_update' },
		], 'sending nested emails, and emails whose result comes after the log changed' );
		assert.deepEqual( s.results, [
			{ subject: '[Site] Password Reset outer', sent: true, nested: [ { subject: '[Site] Password Reset inner refused', sent: false }, { subject: '[Site] Password Reset inner stopped', sent: false }, { subject: '[Site] Password Reset inner delivered', sent: true } ] },
			{ subject: '[Site] Password Reset twice', sent: true, nested: [ { subject: '[Site] Password Reset twice', sent: false } ] },
			{ subject: '[Site] Password Reset while logging is switched off', sent: true },
			{ subject: '[Site] Password Reset while the table is forgotten', sent: true },
			{ subject: '[Site] Password Reset whose update is refused', sent: true },
		], 'The emails were not sent, refused or stopped as planned, so the results below prove nothing' );
		assert.deepEqual( s.entries?.map( ( entry ) => [ entry.subject, entry.outcome, entry.error ] ), [
			[ '[Site] Password Reset outer', 'sent', '' ],
			[ '[Site] Password Reset inner refused', 'failed', 'sn-test: the inner email was refused' ],
			[ '[Site] Password Reset inner stopped', '', '' ],
			[ '[Site] Password Reset inner delivered', 'sent', '' ],
			[ '[Site] Password Reset twice', 'sent', '' ],
			[ '[Site] Password Reset twice', 'failed', 'sn-test: the inner one of the two was refused' ],
			[ '[Site] Password Reset while logging is switched off', '', '' ],
			[ '[Site] Password Reset while the table is forgotten', '', '' ],
			[ '[Site] Password Reset whose update is refused', '', '' ],
		], 'A result was recorded in the wrong entry, or although the log had changed' );
		const after = await state();
		assert.deepEqual( [ after.table, after.options ], [ true, { safety_net_email_log_db_version: '1' } ], 'Undoing the changes made while sending failed, so later tests would not prove anything' );
		assert.deepEqual( safetyNetDbErrors( mark ), [], 'The refused update was reported as a database error' );
		assert.equal( site.logEntriesSince( mark ).filter( ( line ) => line.includes( COULD_NOT_RECORD_RESULT ) ).length, 1, 'debug.log does not report the one result the log could not record' );
	} );

	test( 'E8: Email Log > Settings shows both switches on by default, in a form of their own that comes back to it, followed by the notes on what is logged and when the log is emptied; the list keeps only the note on the results, above its views', async () => {
		assert.deepEqual( ( await state() ).options, { safety_net_email_log_db_version: '1' }, 'A switch is stored, so this does not test the defaults' );
		const [ settings, list ] = ( await site.getAll( [ SETTINGS, PAGE ].map( ( target ) => ( { path: target, jar: site.adminJar } ) ) ) ).map( ( res ) => res.text );
		assert.deepEqual( toggles( settings ), { safety_net_email_log_toggle: true, safety_net_email_log_cleanup_toggle: true }, 'A switch without a stored option does not show as on' );
		const form = settingsForm( settings );
		assert.equal( form.action, 'options.php' );
		assert.deepEqual( form.checkboxes, [ ...TOGGLES, 'safety_net_send_emails_toggle' ], 'The Settings form must hold exactly the two log switches and Send emails' );
		assert.deepEqual( queryOf( form.fields._wp_http_referer ), SETTINGS_ARGS, 'Saving the switches would not come back to the Settings page' );
		assert.match( form.html, /<input\b[^>]*type="submit"[^>]*value="Save Changes"/, 'The Settings form has no Save Changes button' );
		assert.match( settings, /Keep a log of the emails Safety Net blocks or lets through\./ );
		assert.match( settings, /Delete log entries older than 6 months, once a day\./ );
		const formEnd = settings.indexOf( '</form>', settings.indexOf( 'class="safety-net-email-log-settings"' ) );
		const notes = SETTINGS_NOTES.map( ( note ) => settings.indexOf( `<p>${ note }</p>` ) );
		assert.ok( notes.every( ( at ) => at !== -1 ), 'The Settings page lacks a note on what is logged or when the log is emptied' );
		assert.match( settings, /<div class="notice notice-info inline safety-net-email-log-notes">\s*<p>Only emails sent through wp_mail\(\) are logged\.[^<]*<\/p>\s*<p>The log is emptied[^<]*<\/p>\s*<\/div>/, 'The Settings notes are not in an inline info notice' );
		assert.ok( formEnd !== -1 && formEnd < notes[ 0 ] && notes[ 0 ] < notes[ 1 ], 'The notes do not follow the Settings form, what is logged first' );
		assert.doesNotMatch( settings, RESULTS_NOTE, 'The Settings page has the list\'s note on the results' );

		assert.deepEqual( forms( list ).filter( ( candidate ) => hiddenFields( candidate ).option_page !== undefined || /action="[^"]*options\.php"/.test( candidate ) ), [], 'The list still has a settings form' );
		assert.doesNotMatch( list, /safety_net_email_log_(?:cleanup_)?toggle|safety-net-email-log-settings"/, 'The list still holds an email log switch' );
		assert.deepEqual( SETTINGS_NOTES.filter( ( note ) => list.includes( note ) ), [], 'The list still has a note that moved to the Settings page' );
		assert.equal( loggingOffLink( list ), null, 'The list warns that logging is off while it is on' );
		const note = list.search( RESULTS_NOTE );
		const views = list.indexOf( "<ul class='subsubsub'>" );
		const table = list.indexOf( 'class="wp-list-table' );
		const headerEnd = list.indexOf( '<hr class="wp-header-end">' );
		assert.ok( headerEnd !== -1 && note !== -1 && views !== -1 && table !== -1, 'The list lacks the end of its header, the note on the results, the views or the table' );
		assert.ok( headerEnd < note && note < views && views < table, 'The note on the results does not come between the heading and the views' );
	} );

	test( 'E9: saving Email Log > Settings turns an unchecked switch off and a checked one on, and returns to Settings with "Settings saved.", which the list never shows; entries stay, the cleanup event follows its own switch, and the list warns, linking to Settings, only while logging is off', async () => {
		const form = settingsForm( ( await site.get( SETTINGS, { jar: site.adminJar } ) ).text );
		const pause = () => site.php( "return sn_test_raw_option( 'safety_net_pause_renewal_actions_toggle' );" );
		const pauseBefore = await pause();
		// The list loads first: had it shown the saved notice, the Settings page would no longer get it.
		const save = async ( checked ) => {
			const res = await site.post( '/wp-admin/options.php', { ...form.fields, ...Object.fromEntries( checked.map( ( name ) => [ name, 'on' ] ) ) }, { jar: site.adminJar, follow: false, expect: 302 } );
			assert.deepEqual( redirectArgs( site, res ), { ...SETTINGS_ARGS, 'settings-updated': 'true' }, `Saving the Settings form redirected to ${ res.location }` );
			const list = ( await site.get( `${ PAGE }&settings-updated=true`, { jar: site.adminJar } ) ).text;
			assert.doesNotMatch( list, /Settings saved\.|\bsettings-error\b/, 'The list shows the notices of the Settings form' );
			const back = ( await site.get( pathOf( res.location ), { jar: site.adminJar } ) ).text;
			assert.equal( settingsSaved( back ), 1, 'The Settings page does not say "Settings saved." once after saving' );
			assert.deepEqual( toggles( back ), Object.fromEntries( TOGGLES.map( ( name ) => [ name, checked.includes( name ) ] ) ), 'The Settings page does not show the switches as they were saved' );
			return { state: await state(), list };
		};
		const before = await log();

		try {
			let saved = await save( [ 'safety_net_email_log_cleanup_toggle' ] );
			assert.deepEqual( saved.state.options, { safety_net_email_log_toggle: 'off', safety_net_email_log_cleanup_toggle: 'on', safety_net_email_log_db_version: '1' } );
			assert.equal( saved.state.cleanup?.schedule, 'daily', 'Switching logging off unscheduled the cleanup, which has its own switch' );
			assert.equal( pathOf( loggingOffLink( saved.list ) ?? '' ), SETTINGS, 'The list does not warn that logging is off, with a link to the Settings page' );
			assert.ok( listedEntries( saved.list ).length > 0, 'The list with logging off no longer shows the entries already logged' );
			assert.deepEqual( await send( [ { to: 'someone@example.com', subject: 'Sent while logging is switched off', message: 'x' } ] ), { verdicts: [ false ], entries: [] }, 'An email was logged with logging switched off, or not blocked' );
			assert.deepEqual( await log(), before, 'Switching logging off changed the entries already logged' );

			saved = await save( [] );
			assert.deepEqual( saved.state.options, { safety_net_email_log_toggle: 'off', safety_net_email_log_cleanup_toggle: 'off', safety_net_email_log_db_version: '1' } );
			assert.equal( saved.state.cleanup, null, 'The cleanup event is still scheduled with its switch off' );
			assert.notEqual( loggingOffLink( saved.list ), null, 'The list does not warn that logging is off' );

			saved = await save( [ 'safety_net_email_log_toggle' ] );
			assert.deepEqual( saved.state.options, { safety_net_email_log_toggle: 'on', safety_net_email_log_cleanup_toggle: 'off', safety_net_email_log_db_version: '1' } );
			assert.equal( loggingOffLink( saved.list ), null, 'The list warns that logging is off while only the cleanup is' );

			saved = await save( TOGGLES );
			assert.deepEqual( saved.state.options, { safety_net_email_log_toggle: 'on', safety_net_email_log_cleanup_toggle: 'on', safety_net_email_log_db_version: '1' } );
			assert.equal( saved.state.cleanup?.schedule, 'daily', 'Switching the cleanup back on did not schedule it again' );
			assert.equal( loggingOffLink( saved.list ), null, 'The list still warns that logging is off' );
			const resumed = await send( [ { to: 'someone@example.com', subject: 'Sent after logging is switched back on', message: 'x' } ] );
			assert.deepEqual( resumed.entries?.map( ( entry ) => [ entry.subject, entry.status ] ), [ [ 'Sent after logging is switched back on', 'blocked' ] ], 'Logging did not resume' );
			assert.equal( await pause(), pauseBefore, 'Saving the Settings form changed the renewal pause on the Tools page' );
		} finally {
			await site.php( "delete_option( 'safety_net_email_log_toggle' ); delete_option( 'safety_net_email_log_cleanup_toggle' ); return true;", { label: 'switching logging and cleanup back to their defaults' } );
		}
	} );

	test( 'E10: saving the Tools page form leaves the email log switches alone', async () => {
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

	test( 'E11: WP-Cron\'s daily cleanup deletes only entries older than 6 months, nothing deletes them with the cleanup switched off, and opening the page deletes them too', async () => {
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
				await site.get( PAGE, { jar: site.adminJar } );
				assert.ok( ( await aged() ).includes( 'Seven months old, cleanup off' ), 'An entry older than 6 months was deleted with the cleanup switched off' );
			} finally {
				await site.php( "delete_option( 'safety_net_email_log_cleanup_toggle' ); return true;", { label: 'switching the cleanup back to its default' } );
			}
			assert.deepEqual( ( await state() ).cleanup, { due: true, schedule: 'daily' }, 'Switching the cleanup back on did not schedule it again' );
			await site.get( PAGE, { jar: site.adminJar } );
			assert.deepEqual( await aged(), [ 'Just under six months old', 'One day old' ], 'Opening the Email Log page did not delete the entry older than 6 months, which WP-Cron has not run on' );
		} finally {
			// Their dates are older than entries logged before them, so they would break the list order checked later.
			await site.php( "global $wpdb; $wpdb->delete( $wpdb->prefix . 'safety_net_email_log', array( 'recipients' => 'aged@example.com' ) ); return true;", { label: 'removing the aged entries' } );
		}
	} );

	test( 'E12: the page lists the entries newest first, 20 a page, with dates in the site\'s timezone, Safety Net\'s decision, the result, the error and every value escaped', async () => {
		const saved = await site.php( "$saved = array(); foreach ( array( 'timezone_string', 'date_format', 'time_format' ) as $name ) { $saved[ $name ] = get_option( $name ); } update_option( 'timezone_string', 'Asia/Tokyo' ); update_option( 'date_format', 'Y-m-d' ); update_option( 'time_format', 'H:i' ); return $saved;", { label: 'setting the site\'s timezone to Asia/Tokyo' } );
		try {
			const bulk = await send( Array.from( { length: 21 }, ( _, i ) => ( { to: `bulk${ i + 1 }@example.com`, subject: `Bulk ${ i + 1 }`, message: 'x' } ) ) );
			assert.equal( bulk.entries?.length, 21, 'Logging the bulk emails failed' );
			assertLoggedNow( bulk.entries );
			const entries = ( await log() ).reverse();
			const pages = Math.ceil( entries.length / 20 );
			assert.ok( pages >= 2, 'The log fits on one page, so paging is not tested' );
			assert.ok( entries.some( ( entry ) => entry.status === 'blocked_recipients' ) && entries.some( ( entry ) => entry.status === 'allowed' ), 'The log lacks an entry of every status, so their labels are not tested' );
			assert.deepEqual( [ '', ...OUTCOMES ].filter( ( outcome ) => ! entries.some( ( entry ) => entry.outcome === outcome ) ), [], 'The log lacks an entry of every result, so their labels are not tested' );
			const loaded = await site.getAll( Array.from( { length: pages }, ( _, i ) => ( { path: i === 0 ? PAGE : `${ PAGE }&paged=${ i + 1 }`, jar: site.adminJar } ) ) );
			for ( const [ i, res ] of loaded.entries() ) {
				const page = i + 1;
				const listed = listedEntries( res.text );
				const expected = entries.slice( ( page - 1 ) * 20, page * 20 );
				assert.deepEqual( listed.map( ( row ) => [ row.id, row.status, row.outcome ] ), expected.map( ( entry ) => [ entry.id, entry.status, entry.outcome || 'unknown' ] ), `Page ${ page } does not list the entries newest first, 20 a page` );
				assert.deepEqual( listed.map( shown ), expected.map( ( entry ) => ( {
					date: tokyoTime( entry.logged_at ),
					recipients: entry.recipients,
					subject: entry.subject,
					status: LABELS[ entry.status ],
					result: RESULTS[ entry.outcome ],
					error: errorShown( entry.error ).text,
					errorTitle: errorShown( entry.error ).title,
				} ) ), `Page ${ page } shows the wrong date (in Asia/Tokyo), recipients, subject, status, result or error` );
				assert.deepEqual( escapedValues( listed ), [], `Page ${ page } shows a logged value unescaped` );
				assert.deepEqual( listed.filter( ( row ) => ! row.view || row.view.id !== row.id || row.view.href !== row.href ).map( ( row ) => row.id ), [], `A row on page ${ page } has no View action, or it opens another entry than its subject` );
				assert.deepEqual( listed.map( ( row ) => queryOf( row.href ) ), expected.map( ( entry ) => ( { ...PAGE_ARGS, ...( page > 1 ? { paged: String( page ) } : {} ), entry: entry.id } ) ), `The View links on page ${ page } do not open their entry with the list's page` );
				assert.equal( displayed( res.text ), entries.length );
				assert.match( res.text, RESULTS_NOTE, `Page ${ page } lacks the note on the results` );
				assert.match( actionForm( res.text, 'safety_net_email_log_clear' ).html, /data-safety-net-confirm="[^"]+"/, 'Clear log does not ask for confirmation' );
			}
			const next = pagingLink( loaded[ 0 ].text, 'next' );
			assert.ok( next, 'The first page does not link to the next one' );
			assert.deepEqual( queryOf( next ), { ...PAGE_ARGS, paged: '2' } );
			const headings = [ ...loaded[ 0 ].text.matchAll( /<h2 class='screen-reader-text'>([^<]*)<\/h2>/g ) ].map( ( [ , heading ] ) => heading );
			assert.deepEqual( [ 'Filter email log', 'Email log navigation', 'Email log' ].filter( ( heading ) => ! headings.includes( heading ) ), [], `The list's headings for screen readers do not name the email log: ${ headings.join( ', ' ) }` );
			assert.deepEqual( headings.filter( ( heading ) => /^Items list|^Filter items list$/.test( heading ) ), [], 'The list keeps core\'s generic headings for screen readers' );
			const hideable = [ ...loaded[ 0 ].text.matchAll( /<input class="hide-column-tog"[^>]*\svalue="([^"]+)"/g ) ].map( ( [ , column ] ) => column );
			assert.deepEqual( hideable, [ 'date', 'recipients', 'status', 'outcome', 'error' ], 'Screen Options can hide the Subject column, which holds the links that open the entries, or cannot hide the others' );
		} finally {
			await site.php( `foreach ( ${ phpValue( saved ) } as $name => $value ) { update_option( $name, $value ); } return true;`, { label: 'restoring the site\'s timezone' } );
		}
	} );

	test( 'E26: the views filter the list by result, each with the count of all its entries, and an unknown result shows them all', async () => {
		const entries = ( await log() ).reverse();
		const counts = { all: entries.length, ...Object.fromEntries( OUTCOMES.map( ( outcome ) => [ outcome, entries.filter( ( entry ) => entry.outcome === outcome ).length ] ) ) };
		assert.deepEqual( OUTCOMES.filter( ( outcome ) => counts[ outcome ] === 0 ), [], 'A result has no entries, so its view is not tested' );
		assert.ok( entries.some( ( entry ) => entry.outcome === '' ), 'No entry lacks a result, so leaving those out of the result views is not tested' );
		const requested = [ '', ...OUTCOMES, 'bogus' ];
		const pages = await site.getAll( requested.map( ( outcome ) => ( { path: outcome ? pageWith( { outcome } ) : PAGE, jar: site.adminJar } ) ) );
		for ( const [ i, res ] of pages.entries() ) {
			const outcome = OUTCOMES.includes( requested[ i ] ) ? requested[ i ] : '';
			const listedViews = views( res.text );
			assert.deepEqual( Object.fromEntries( Object.entries( listedViews ).map( ( [ key, view ] ) => [ key, view.label ] ) ), VIEWS, 'The views are not All, Would send, Would fail, Sent and Failed' );
			assert.deepEqual( viewCounts( res.text ), counts, `The view counts with outcome=${ requested[ i ] } are wrong` );
			assert.deepEqual( Object.fromEntries( Object.entries( listedViews ).map( ( [ key, view ] ) => [ key, queryOf( view.href ) ] ) ), Object.fromEntries( Object.keys( VIEWS ).map( ( key ) => [ key, { ...PAGE_ARGS, ...( key === 'all' ? {} : { outcome: key } ) } ] ) ), 'A view does not link to its filter' );
			assert.deepEqual( currentView( res.text ), [ outcome || 'all' ], `outcome=${ requested[ i ] } does not make its view the current one` );
			const expected = outcome ? entries.filter( ( entry ) => entry.outcome === outcome ) : entries;
			assert.equal( displayed( res.text ), expected.length, `outcome=${ requested[ i ] } does not count its entries` );
			assert.deepEqual( listedEntries( res.text ).map( ( row ) => [ row.id, row.outcome ] ), expected.slice( 0, 20 ).map( ( entry ) => [ entry.id, entry.outcome || 'unknown' ] ), `outcome=${ requested[ i ] } does not list its entries, newest first` );
			const hidden = hiddenFields( forms( res.text ).find( ( form ) => /class="safety-net-email-log-filter"/.test( form ) ) ?? '' );
			assert.deepEqual( hidden, { page: 'safety_net_email_log', ...( outcome ? { outcome } : {} ) }, `The search form with outcome=${ requested[ i ] } does not keep the view, or keeps an unknown one` );
		}

		const past = await site.get( pageWith( { outcome: 'sent', paged: '9' } ), { jar: site.adminJar } );
		assert.match( past.chain[ 0 ], / -> 302$/, 'A page past the last one did not redirect' );
		assert.deepEqual( queryOf( past.path ), { ...PAGE_ARGS, outcome: 'sent', paged: String( Math.max( 1, Math.ceil( counts.sent / 20 ) ) ) }, 'A page past the last one did not go to the last page of its view' );
	} );

	test( 'E27: the search finds text in the recipients, subject or message, takes % and _ literally, never finds a redacted key, combines with a view, keeps its own counts out of the views and survives paging', async () => {
		const added = await send( SEARCH, 'sending emails to search for' );
		assert.equal( added.entries?.length, SEARCH.length, 'Logging the emails to search for failed' );
		const entries = ( await log() ).reverse();
		const counts = viewCounts( ( await site.get( PAGE, { jar: site.adminJar } ) ).text );
		const searches = [
			[ 'needle', '', 4 ],
			[ 'needle-to', '', 1 ],
			[ 'NEEDLE-SUBJECT', '', 2 ],
			[ 'needle-body', '', 1 ],
			[ 'needle', 'would_fail', 1 ],
			[ 'needle', 'sent', 0 ],
			[ '%', '', undefined ],
			[ 'a_b', '', undefined ],
			[ 'NEEDLEKEY1', '', 0 ],
			[ '<b>needle</b>', '', 0 ],
		];
		const literal = ( term ) => entries.filter( ( entry ) => matches( entry, term ) );
		assert.ok( literal( '%' ).length > 0 && literal( '%' ).length < entries.length, 'Every entry or none holds a %, so matching it literally is not tested' );
		assert.ok( entries.some( ( entry ) => /a.b/i.test( entry.subject ) && ! matches( entry, 'a_b' ) ), 'No entry matches a_b only as a pattern, so matching _ literally is not tested' );
		const pages = await site.getAll( searches.map( ( [ s, outcome ] ) => ( { path: pageWith( { ...( outcome ? { outcome } : {} ), s } ), jar: site.adminJar } ) ) );
		for ( const [ i, res ] of pages.entries() ) {
			const [ s, outcome, count ] = searches[ i ];
			const expected = literal( s ).filter( ( entry ) => ! outcome || entry.outcome === outcome );
			if ( count !== undefined ) {
				assert.equal( expected.length, count, `The log holds ${ expected.length } entries for "${ s }"${ outcome ? ` in ${ outcome }` : '' }, not ${ count }, so the search is not tested as planned` );
			}
			assert.equal( displayed( res.text ), expected.length, `Searching for "${ s }"${ outcome ? ` in ${ outcome }` : '' } did not count the entries that hold it` );
			assert.deepEqual( listedEntries( res.text ).map( ( row ) => row.id ), expected.slice( 0, 20 ).map( ( entry ) => entry.id ), `Searching for "${ s }"${ outcome ? ` in ${ outcome }` : '' } did not list the entries that hold it` );
			assert.equal( emptyState( res.text ), expected.length ? null : 'No entries match.', `Searching for "${ s }" does not say that nothing matches` );
			assert.deepEqual( viewCounts( res.text ), counts, `Searching for "${ s }" changed the view counts` );
			assert.deepEqual( Object.values( views( res.text ) ).map( ( view ) => queryOf( view.href ).s ), Object.keys( VIEWS ).map( () => s ), `The views do not keep the search for "${ s }"` );
			assert.deepEqual( currentView( res.text ), [ outcome || 'all' ] );
			const subtitle = searchedFor( res.text );
			assert.ok( subtitle !== null && ! /[<>]/.test( subtitle ) && decode( subtitle ) === s, `The page does not say, escaped, that it shows the results for "${ s }": ${ subtitle }` );
			assert.equal( decode( searchBox( res.text ) ?? '' ), s, `The search box does not hold "${ s }"` );
		}

		const blank = await site.get( pageWith( { s: '   ' } ), { jar: site.adminJar } );
		assert.equal( displayed( blank.text ), entries.length, 'A blank search did not list every entry' );
		assert.equal( searchedFor( blank.text ), null, 'A blank search is shown as a search' );

		const list = { outcome: 'would_send', s: 'bulk' };
		const bulk = entries.filter( ( entry ) => entry.outcome === list.outcome && matches( entry, list.s ) );
		assert.ok( bulk.length > 20, 'The search for "bulk" in Would send fits on one page, so paging is not tested' );
		const [ first, second ] = await site.getAll( [ pageWith( list ), pageWith( { ...list, paged: '2' } ) ].map( ( target ) => ( { path: target, jar: site.adminJar } ) ) );
		assert.deepEqual( listedEntries( first.text ).map( ( row ) => row.id ), bulk.slice( 0, 20 ).map( ( entry ) => entry.id ) );
		assert.deepEqual( listedEntries( second.text ).map( ( row ) => row.id ), bulk.slice( 20, 40 ).map( ( entry ) => entry.id ), 'The second page of a search in a view does not list the rest of its entries' );
		assert.deepEqual( Object.values( views( second.text ) ).map( ( view ) => queryOf( view.href ) ), Object.keys( VIEWS ).map( ( key ) => ( { ...PAGE_ARGS, ...( key === 'all' ? {} : { outcome: key } ), s: list.s } ) ), 'The views on page 2 do not keep the search, or keep the page number, which another view may not have' );
		assert.deepEqual( queryOf( pagingLink( first.text, 'next' ) ?? '' ), { ...PAGE_ARGS, ...list, paged: '2' }, 'The next page link does not keep the view and the search' );
		assert.deepEqual( queryOf( pagingLink( second.text, 'first' ) ?? '' ), { ...PAGE_ARGS, ...list }, 'The first page link does not keep the view and the search' );
		const filter = forms( second.text ).find( ( form ) => /class="safety-net-email-log-filter"/.test( form ) ) ?? '';
		assert.deepEqual( hiddenFields( filter ), { page: 'safety_net_email_log', outcome: list.outcome }, 'The search form does not keep the view' );
		assert.equal( searchBox( second.text ), list.s );
		assert.match( filter, /<input class='current-page' id='current-page-selector' type='text'\s+name='paged' value='2'/, 'The search form does not hold the page number' );
	} );

	test( 'E28: the list and the entry show markup escaped and entities as written, and a long error shortened with all of it, escaped, in its title', async () => {
		const failed = await sendForResults( [ { to: 'someone@example.com', subject: '[Site] Password Reset refused with markup', refuse: LONG_ERROR } ] );
		const blocked = await send( [ ENTITY_EMAIL ] );
		const entries = [ ...failed.entries, ...blocked.entries ];
		assert.deepEqual( entries.map( ( entry ) => [ entry.outcome, entry.error ] ), [ [ 'failed', LONG_ERROR ], [ 'would_send', skipped( 'attachment', MARKUP_ATTACHMENT ) ] ], 'The errors with markup were not logged, so this proves nothing' );
		const entity = entries[ 1 ];
		assert.deepEqual( [ entity.recipients, entity.subject, entity.headers ], [ ENTITY_EMAIL.to, ENTITY_EMAIL.subject, ENTITY_EMAIL.headers[ 0 ] ], 'The values with entities were not logged as written, so this proves nothing' );
		assert.ok( [ ...LONG_ERROR ].length > 120, 'The long error is not long enough to be shortened' );
		const page = await site.get( PAGE, { jar: site.adminJar } );
		const rows = listedEntries( page.text ).filter( ( row ) => entries.some( ( entry ) => entry.id === row.id ) );
		assert.equal( rows.length, 2, 'The entries with markup in their error are not on the first page' );
		assert.deepEqual( escapedValues( rows ), [], 'A value is shown unescaped' );
		assert.deepEqual( rows.map( ( row ) => [ shown( row ).error, shown( row ).errorTitle ] ), [ ...entries ].reverse().map( ( entry ) => [ errorShown( entry.error ).text, errorShown( entry.error ).title ] ), 'The Error column does not show the error as written, shortened with all of it in the title when it is long' );
		assert.deepEqual( rows.map( ( row ) => [ shown( row ).recipients, shown( row ).subject, decode( row.view?.label ?? '' ) ] ), [ ...entries ].reverse().map( ( entry ) => [ entry.recipients, entry.subject, `View “${ entry.subject }”` ] ), 'The To or Subject column, or the View link\'s label, does not show the logged value as written' );

		const [ longView, entityView ] = ( await site.getAll( entries.map( ( entry ) => ( { path: entryPath( entry.id ), jar: site.adminJar } ) ) ) ).map( ( res ) => res.text );
		for ( const [ view, entry ] of [ [ longView, entries[ 0 ] ], [ entityView, entity ] ] ) {
			for ( const [ label, value ] of [ [ 'Error', entry.error ], [ 'To', entry.recipients ], [ 'Subject', entry.subject ], [ 'From', entry.from_address ] ] ) {
				const cell = detail( view, label );
				assert.ok( cell !== undefined && ! /[<>]/.test( cell ), `The entry's ${ label } row is missing or unescaped` );
				assert.equal( decode( cell ), value, `The entry's ${ label } row does not show the logged value as written` );
			}
		}
		const headers = detail( entityView, 'Headers' )?.match( /^<pre>([\s\S]*)<\/pre>$/ )?.[ 1 ];
		assert.ok( headers !== undefined && ! /[<>]/.test( headers ), 'The headers are not shown escaped in <pre>' );
		assert.equal( decode( headers ), entity.headers, 'The headers are not shown as written' );
		const attachments = detail( entityView, 'Attachments' ).replace( /<br \/><span class="description">[^<]*<\/span>$/, '' );
		assert.ok( ! /[<>]/.test( attachments ), 'An attachment name is shown unescaped' );
		assert.equal( decode( attachments ), MARKUP_ATTACHMENT, 'An attachment name is not shown as written' );
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
		assert.equal( decode( detail( htmlView, 'Result' ) ), 'Would send' );
		assert.equal( decode( detail( htmlView, 'Error' ) ), html.error );
		assert.equal( decode( detail( plainView, 'Error' ) ), plain.error );
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
			assert.deepEqual( queryOf( view.match( /<a class="button" href="([^"]*)">Back to the log<\/a>/ )?.[ 1 ] ?? '' ), PAGE_ARGS, 'The entry page does not link back to the log' );
		}
		assert.match( missing, /That log entry does not exist\./ );
	} );

	test( 'E29: an entry shows its message, Raw and JSON as ARIA tabs, with every panel rendered and labelled for pages without JavaScript', async () => {
		const [ html, plain ] = await entriesBySubject( [ WOO.subject, ORDER.subject ] );
		const loaded = ( await site.getAll( [ html, plain ].map( ( entry ) => ( { path: entryPath( entry.id ), jar: site.adminJar } ) ) ) ).map( ( res ) => res.text );
		for ( const [ view, entry, label ] of [ [ loaded[ 0 ], html, 'HTML' ], [ loaded[ 1 ], plain, 'Text' ] ] ) {
			const kinds = [ 'message', 'raw', 'json' ];
			const tablist = view.match( /<div role="tablist" class="([^"]*)" aria-label="Message views">([\s\S]*?)<\/div>/ );
			assert.ok( tablist, 'The entry has no tab list' );
			assert.match( tablist[ 1 ], /\bhide-if-no-js\b/, 'The tabs show without JavaScript, where they do nothing' );
			const tabs = [ ...tablist[ 2 ].matchAll( /<button type="button" role="tab" class="safety-net-email-log-tab" id="([^"]+)" aria-controls="([^"]+)" aria-selected="(true|false)" tabindex="(-?\d)">([^<]*)<\/button>/g ) ].map( ( [ , id, controls, selected, tabindex, text ] ) => ( { id, controls, selected, tabindex, text } ) );
			assert.deepEqual( tabs, kinds.map( ( kind, i ) => ( { id: `safety-net-email-log-tab-${ kind }-${ entry.id }`, controls: `safety-net-email-log-panel-${ kind }-${ entry.id }`, selected: String( i === 0 ), tabindex: i === 0 ? '0' : '-1', text: [ label, 'Raw', 'JSON' ][ i ] } ) ), `The tabs of a ${ label } entry are not ${ label }, Raw and JSON, with the first one selected` );
			const panels = [ ...view.matchAll( /<div role="tabpanel" class="safety-net-email-log-panel safety-net-email-log-panel-([a-z]+)" id="([^"]+)" aria-labelledby="([^"]+)"([^>]*)>\s*<h4 class="hide-if-js">([^<]*)<\/h4>/g ) ].map( ( [ , kind, id, labelledBy, rest, heading ] ) => ( { kind, id, labelledBy, hidden: /\shidden\b/.test( rest ), heading } ) );
			assert.deepEqual( panels, kinds.map( ( kind, i ) => ( { kind, id: `safety-net-email-log-panel-${ kind }-${ entry.id }`, labelledBy: `safety-net-email-log-tab-${ kind }-${ entry.id }`, hidden: false, heading: [ label, 'Raw', 'JSON' ][ i ] } ) ), 'The panels are not all rendered, labelled by their tab and headed for pages without JavaScript' );
		}
	} );

	test( 'E30: Raw is the MIME source rebuilt from the entry, with its logged date, sender, recipients, headers, subject and redacted message and no attachment, or PHPMailer\'s error when it refuses the entry', async () => {
		const [ order, woo, attached, early, empty, invalid, reset ] = await entriesBySubject( [ ORDER.subject, WOO.subject, 'Dry run: valid', 'Sent after the WooCommerce filters were removed', PAIR.subject, 'Dry run: invalid To', RESET.subject ] );
		const [ orderView, wooView, attachedView, earlyView, emptyView, invalidView, resetView ] = ( await site.getAll( [ order, woo, attached, early, empty, invalid, reset ].map( ( entry ) => ( { path: entryPath( entry.id ), jar: site.adminJar } ) ) ) ).map( ( res ) => res.text );

		const raw = rawView( orderView );
		assert.equal( raw.error, undefined, `The raw message of a valid entry could not be rebuilt: ${ raw.error }` );
		assert.deepEqual( {
			Date: raw.headers.Date,
			From: raw.headers.From,
			To: raw.headers.To,
			Cc: raw.headers.Cc,
			Bcc: raw.headers.Bcc,
			'Reply-To': raw.headers[ 'Reply-To' ],
			Subject: raw.headers.Subject,
			'Content-Type': raw.headers[ 'Content-Type' ],
		}, {
			Date: rawDate( order.logged_at ),
			From: order.from_address,
			To: order.recipients,
			Cc: 'cc@example.com',
			Bcc: 'bcc@example.com',
			'Reply-To': 'Shop <reply@example.com>',
			Subject: order.subject,
			'Content-Type': order.content_type,
		}, 'The raw message does not have the entry\'s date, sender, recipients, headers, subject and content type' );
		assert.equal( raw.body, order.message, 'The raw message does not have the logged message' );
		assert.match( raw.note, /with the logged date and the redacted message\. Attachments are not included\./ );
		assert.doesNotMatch( raw.text, /Content-Disposition|multipart\/mixed|report\.csv|invoice-1\.pdf/, 'The raw message has an attachment' );

		const html = rawView( wooView );
		assert.deepEqual( [ html.headers.From, html.headers[ 'Content-Type' ], html.headers.Cc, html.headers.Bcc, html.headers[ 'Reply-To' ] ], [ 'Example Shop <shop@example.com>', 'text/html; charset=UTF-8', 'manager@example.com', 'archive@example.com', 'Example Shop <shop@example.com>' ] );
		assert.equal( html.body, woo.message, 'The raw message of an HTML entry does not have its redacted message as written' );
		assert.doesNotMatch( html.text, /WOOKEY1/, 'The raw message holds the redacted key' );

		const file = rawView( attachedView );
		assert.equal( attached.attachments, ATTACHMENT.name, 'The entry did not log its readable attachment, so this proves nothing' );
		assert.match( file.note, /Attachments are not included\./ );
		assert.doesNotMatch( file.text, new RegExp( `${ ATTACHMENT.content }|${ Buffer.from( ATTACHMENT.content ).toString( 'base64' ) }|Content-Disposition|${ ATTACHMENT.name.replace( /\./g, '\\.' ) }` ), 'The raw message has the attachment' );
		const earlyRaw = rawView( earlyView );
		assert.doesNotMatch( earlyRaw.note, /Attachments/, 'The raw message of an entry without attachments says it leaves them out' );

		const custom = rawView( resetView );
		assert.equal( custom.error, undefined, `The raw message of an entry with a custom header could not be rebuilt: ${ custom.error }` );
		assert.equal( custom.headers[ 'X-SN-Test' ], 'reset', 'The raw message does not have the entry\'s custom header' );
		for ( const [ rebuilt, entry ] of [ [ raw, order ], [ html, woo ], [ file, attached ], [ earlyRaw, early ], [ custom, reset ] ] ) {
			assert.equal( ( rebuilt.text.match( /^MIME-Version:/gm ) || [] ).length, 1, `The raw message of "${ entry.subject }" does not have exactly one MIME-Version header` );
		}

		assert.deepEqual( [ rawView( emptyView ).error, rawView( invalidView ).error ], [ `The raw message could not be rebuilt: ${ EMPTY_BODY }`, `The raw message could not be rebuilt: ${ NO_RECIPIENT }` ], 'An entry PHPMailer refuses does not show PHPMailer\'s error in place of the raw message' );
	} );

	test( 'E31: JSON is the whole entry, pretty-printed and escaped, which decodes to its fields', async () => {
		const subjects = [ ORDER.subject, WOO.subject, 'Dry run: valid', 'Dry run: skipped parts', '[Site] Password Reset through mail()' ];
		const entries = await entriesBySubject( subjects );
		const loaded = await site.getAll( entries.map( ( entry ) => ( { path: entryPath( entry.id ), jar: site.adminJar } ) ) );
		for ( const [ i, res ] of loaded.entries() ) {
			const { text, value } = jsonView( res.text );
			assert.deepEqual( Object.keys( value ), [ 'id', 'logged_at', 'status', 'outcome', 'error', 'from', 'to', 'subject', 'headers', 'content_type', 'attachments', 'message' ], 'The JSON view does not have the entry\'s fields in order' );
			assert.deepEqual( value, entryJson( entries[ i ] ), `The JSON view of "${ subjects[ i ] }" does not decode to the entry` );
			assert.match( text, /^\{\n {4}"id": \d+,\n/, 'The JSON view is not pretty-printed' );
			assert.doesNotMatch( text, /\\\//, 'The JSON view escapes slashes' );
		}
		assert.match( jsonView( loaded[ 2 ].text ).text, /"message": "Hello — café"/, 'The JSON view escapes Unicode' );
	} );

	test( 'E32: the popup gets an entry over admin-ajax.php as the same markup as its page, and refuses a bad, missing or another action\'s nonce, a subscriber, a logged-out visitor and a missing entry', async () => {
		const entry = await entryBySubject( WOO.subject );
		const list = await site.get( PAGE, { jar: site.adminJar } );
		const nonce = viewNonce( list.text );
		const dialog = list.text.match( /<dialog id="safety-net-email-log-dialog"[^>]*>[\s\S]*?<\/dialog>/ )?.[ 0 ];
		assert.match( dialog, /aria-labelledby="safety-net-email-log-dialog-title"/ );
		assert.match( dialog, /<button type="button" class="button safety-net-email-log-dialog-close">Close<\/button>/, 'The dialog has no Close button' );
		assert.match( dialog, /<div class="safety-net-email-log-dialog-body"><\/div>/, 'The dialog is not empty until an entry is opened' );
		for ( const text of [ 'loading', 'loaded', 'error' ] ) {
			assert.match( dialog, new RegExp( `\\sdata-${ text }="[^"]+"` ), `The dialog has no ${ text } text for the popup to show or announce` );
		}

		const res = await ajaxEntry( entry.id, nonce );
		const page = await site.get( entryPath( entry.id ), { jar: site.adminJar } );
		const body = parseJson( res );
		assert.equal( body.success, true, `The entry was not returned: ${ res.text.slice( 0, 300 ) }` );
		const fragment = body.data.html;
		assert.deepEqual( entryParts( fragment ), entryParts( page.text ), 'The popup does not get the same details and views as the entry\'s page' );
		assert.match( fragment, /safety-net-email-log-panel-message-\d+[\s\S]*safety-net-email-log-panel-raw-\d+[\s\S]*safety-net-email-log-panel-json-\d+/, 'The popup does not get the three views' );
		for ( const action of [ 'safety_net_email_log_send', 'safety_net_email_log_delete' ] ) {
			const form = actionForm( fragment, action );
			assert.equal( form.fields.entry, entry.id );
			assert.equal( form.fields._wp_http_referer, undefined, `The popup's ${ action } form names the AJAX request as its referer` );
		}
		assert.doesNotMatch( fragment, /Back to the log/, 'The popup has the page\'s Back link' );
		assert.doesNotMatch( fragment, /<(?:html|head|body|script)\b/, 'The popup gets more than the entry\'s markup' );
		for ( const [ html, where ] of [ [ fragment, 'popup' ], [ page.text, 'entry page' ] ] ) {
			assert.match( actionForm( html, 'safety_net_email_log_delete' ).html.match( /^<form\b[^>]*>/ )[ 0 ], /\sdata-safety-net-confirm="[^"]+"/, `Delete in the ${ where } does not ask for confirmation` );
		}

		// What the popup needs from the script, which no test here runs.
		const script = readFileSync( path.join( config.pluginDir, 'assets/js/safety-net-admin.js' ), 'utf8' );
		const contract = [ 'safety_net_params.ajax_url', "'safety_net_email_log_entry'", "'entry'", "'_wpnonce'", 'dataset.nonce', 'data.html', 'data.message', "document.addEventListener( 'submit'", "addEventListener( 'pointerdown'", 'dataset.loaded', "'aria-busy'", 'wp.a11y.speak' ];
		assert.deepEqual( contract.filter( ( needle ) => ! script.includes( needle ) ), [], 'safety-net-admin.js no longer builds the popup\'s request, reads its response, confirms forms added later or announces the popup\'s state as the page expects' );

		const clearNonce = actionForm( list.text, 'safety_net_email_log_clear' ).fields._wpnonce;
		for ( const [ bad, label ] of [ [ 'bad', 'a bad nonce' ], [ null, 'no nonce' ], [ clearNonce, 'the Clear log nonce' ] ] ) {
			const refused = await ajaxEntry( entry.id, bad, { expect: 403 } );
			assert.deepEqual( parseJson( refused ), { success: false, data: { message: 'Security check failed. Reload the page and try again.' } }, `The entry was returned with ${ label }` );
		}
		for ( const id of [ 999999, 'abc' ] ) {
			assert.deepEqual( parseJson( await ajaxEntry( id, nonce, { expect: 404 } ) ), { success: false, data: { message: 'That log entry does not exist.' } }, `entry=${ id } did not return an error` );
		}

		const subscriber = await createUser( 'ajax_subscriber', 'subscriber' );
		try {
			const jar = await site.login( { user: 'ajax_subscriber', jar: new CookieJar() } );
			const own = await noncesForSession( site, jar, 'ajax_subscriber', [ 'safety_net_email_log_view' ] );
			const refused = await ajaxEntry( entry.id, own.safety_net_email_log_view, { jar, expect: 403 } );
			assert.deepEqual( parseJson( refused ), { success: false, data: { message: 'You do not have permission to do that.' } }, 'A subscriber with a valid nonce got the entry' );
		} finally {
			await deleteUser( subscriber );
		}
		const loggedOut = await ajaxEntry( entry.id, nonce, { jar: null, expect: 400 } );
		assert.equal( loggedOut.text, '0', 'A logged-out visitor did not get admin-ajax.php\'s refusal' );
	} );

	test( 'E14: Send to me sends one copy, only to the current administrator, with the logged sender, content type and message, without Cc, Bcc or attachments, and changes nothing in the log', async () => {
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
				assert.deepEqual( redirectArgs( site, res ), { ...PAGE_ARGS, result: 'sent', entry: copied.id } );
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
				assert.deepEqual( redirectArgs( site, res ), { ...PAGE_ARGS, result: 'not-admin', entry: entry.id } );
				assert.deepEqual( capturedMail( site ).slice( before.mail ), [], 'A copy was sent to a user who is not an administrator' );
				assert.deepEqual( await log(), before.entries );
				assert.match( notice( ( await site.get( res.location, { jar } ) ).text ), /^error: The copy was not sent: copies only go to administrators/ );
			} finally {
				await capture( null );
			}
		} finally {
			await deleteUser( manager.id );
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

	test( 'E33: Send to me and Delete go back to the list with its result filter, search and page, from the popup and from the entry page, and to the plain page from anywhere else', async () => {
		const list = { outcome: 'would_send', s: 'bulk', paged: '2' };
		const listed = await site.get( pageWith( list ), { jar: site.adminJar } );
		assert.equal( listed.chain.length, 1, 'Page 2 of the search for "bulk" in Would send does not exist, so keeping the page is not tested' );
		const rows = listedEntries( listed.text );
		assert.ok( rows.length > 0, 'Page 2 of the search for "bulk" in Would send is empty, so keeping the page is not tested' );
		for ( const row of rows ) {
			assert.deepEqual( [ queryOf( row.href ), queryOf( row.view.href ) ], [ { ...PAGE_ARGS, ...list, entry: row.id }, { ...PAGE_ARGS, ...list, entry: row.id } ], 'A View link does not carry the list\'s view, search and page' );
		}
		const target = rows[ 0 ];
		const entryPage = ( await site.get( pathOf( target.href ), { jar: site.adminJar } ) ).text;
		assert.deepEqual( queryOf( entryPage.match( /<a class="button" href="([^"]*)">Back to the log<\/a>/ )?.[ 1 ] ?? '' ), { ...PAGE_ARGS, ...list }, 'The entry page\'s Back link does not return to the same view, search and page' );
		const pageSend = actionForm( entryPage, 'safety_net_email_log_send' );
		assert.deepEqual( queryOf( pageSend.fields._wp_http_referer ), { ...PAGE_ARGS, ...list, entry: target.id } );
		const fragment = parseJson( await ajaxEntry( target.id, viewNonce( listed.text ) ) ).data.html;
		const popupSend = actionForm( fragment, 'safety_net_email_log_send' );
		const fromList = { referer: `${ site.url }${ pageWith( list ) }` };
		const entries = await log();

		await capture( 1 );
		try {
			const before = capturedMail( site ).length;
			let res = await site.post( ADMIN_POST, pageSend.fields, { jar: site.adminJar, follow: false, expect: 302 } );
			assert.deepEqual( redirectArgs( site, res ), { ...PAGE_ARGS, ...list, entry: target.id, result: 'sent' }, 'Send to me from the entry page did not return to it with the list\'s view, search and page' );
			res = await site.post( ADMIN_POST, popupSend.fields, { jar: site.adminJar, follow: false, expect: 302, headers: fromList } );
			assert.deepEqual( redirectArgs( site, res ), { ...PAGE_ARGS, ...list, result: 'sent' }, 'Send to me from the popup did not return to the list with its view, search and page' );
			const elsewhere = [
				`${ site.url }${ toolsPagePath() }&outcome=sent&s=bulk&paged=2`,
				`${ site.url }/wp-admin/options-general.php?${ new URLSearchParams( { ...PAGE_ARGS, ...list } ) }`,
				`${ site.url }/wp-admin/admin.php?${ new URLSearchParams( { page: 'other_page', ...list } ) }`,
				`http://elsewhere.example${ pageWith( list ) }`,
				`${ site.url }${ pageWith( { outcome: 'bogus', s: '   ', paged: 'two' } ) }`,
				null,
			];
			for ( const referer of elsewhere ) {
				res = await site.post( ADMIN_POST, popupSend.fields, { jar: site.adminJar, follow: false, expect: 302, headers: referer ? { referer } : {} } );
				assert.deepEqual( redirectArgs( site, res ), { ...PAGE_ARGS, result: 'sent' }, `Send to me from ${ referer ?? 'no referer' } did not go to the plain Email Log page` );
			}
			assert.equal( capturedMail( site ).length - before, 2 + elsewhere.length, 'Not every Send to me sent its copy, so the redirects prove less' );
		} finally {
			await capture( null );
		}
		assert.deepEqual( await log(), entries, 'Send to me changed the log' );

		const matching = entries.filter( ( entry ) => entry.outcome === list.outcome && matches( entry, list.s ) );
		const popupDelete = actionForm( fragment, 'safety_net_email_log_delete' );
		const deleted = await site.post( ADMIN_POST, popupDelete.fields, { jar: site.adminJar, follow: false, expect: 302, headers: fromList } );
		assert.deepEqual( redirectArgs( site, deleted ), { ...PAGE_ARGS, ...list, result: 'deleted' }, 'Delete from the popup did not return to the list with its view, search and page' );
		assert.deepEqual( ( await log() ).map( ( entry ) => entry.id ), entries.filter( ( entry ) => entry.id !== target.id ).map( ( entry ) => entry.id ), 'Delete from the popup did not delete exactly its entry' );
		const lastPage = Math.max( 1, Math.ceil( ( matching.length - 1 ) / 20 ) );
		const after = await site.get( deleted.location, { jar: site.adminJar } );
		assert.deepEqual( queryOf( after.path ), lastPage < 2 ? { ...PAGE_ARGS, outcome: list.outcome, s: list.s, paged: '1', result: 'deleted' } : { ...PAGE_ARGS, ...list, result: 'deleted' }, 'The list after Delete did not go to its last page, or lost its view, search or result' );
		assert.equal( notice( after.text ), 'success: The log entry was deleted.', 'The list after Delete lost its notice' );

		const firstRow = listedEntries( ( await site.get( pageWith( { outcome: list.outcome, s: list.s } ), { jar: site.adminJar } ) ).text )[ 0 ];
		const firstPage = ( await site.get( pathOf( firstRow.href ), { jar: site.adminJar } ) ).text;
		const pageDelete = actionForm( firstPage, 'safety_net_email_log_delete' );
		const fromPage = await site.post( ADMIN_POST, pageDelete.fields, { jar: site.adminJar, follow: false, expect: 302 } );
		assert.deepEqual( redirectArgs( site, fromPage ), { ...PAGE_ARGS, outcome: list.outcome, s: list.s, result: 'deleted' }, 'Delete from the entry page did not return to the list with its view and search' );
		assert.equal( ( await log() ).some( ( entry ) => entry.id === firstRow.id ), false, 'Delete from the entry page did not delete its entry' );
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
		const subscriber = await createUser( 'log_subscriber', 'subscriber' );
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
			assert.match( ( await site.get( PAGE, { jar, expect: 403 } ) ).text, /Sorry, you are not allowed to access this page\./ );

			for ( const form of Object.values( requests ) ) {
				await site.post( ADMIN_POST, { ...form, _wpnonce: nonces.send }, { follow: false, expect: 400 } );
			}
			for ( const target of [ PAGE, SETTINGS ] ) {
				assert.match( ( await site.get( target, { follow: false, expect: 302 } ) ).location, /wp-login\.php/, `A logged-out visitor is not sent from ${ target } to the login form` );
			}

			assert.deepEqual( await log(), entries, 'A refused request changed the log' );
			assert.deepEqual( capturedMail( site ).slice( before.mail ), [], 'A refused Send to me sent an email' );
			assert.deepEqual( site.logEntriesSince( before.log ).filter( ( line ) => /copy of a logged email|WordPress database error|SN_TEST attributed/.test( line ) ), [], 'A refused request sent a copy, or logged a Safety Net warning or database error' );
		} finally {
			await capture( null );
			await deleteUser( subscriber );
		}
	} );

	test( 'E17: safety_net_hide_admin hides the Email Log menu item, the list, the Settings page, the popup and the actions, and emails are still logged and old entries deleted', async () => {
		const page = await site.get( PAGE, { jar: site.adminJar } );
		const { fields } = actionForm( page.text, 'safety_net_email_log_clear' );
		const nonce = viewNonce( page.text );
		const before = await log();
		assert.ok( before.length > 0, 'The log is empty, so the AJAX endpoint is not tested' );
		await site.php( "update_option( 'sn_test_hide_admin', 1 ); return true;" );
		try {
			for ( const target of [ PAGE, SETTINGS ] ) {
				assert.match( ( await site.get( target, { jar: site.adminJar, expect: 403 } ) ).text, /Sorry, you are not allowed to access this page\./, `safety_net_hide_admin left ${ target }` );
			}
			const dashboard = await site.get( '/wp-admin/', { jar: site.adminJar } );
			assert.ok( menuIds( dashboard.text ).includes( 'menu-tools' ), 'The dashboard has no admin menu, so this proves nothing' );
			assert.equal( emailLogMenu( dashboard.text ), null, 'safety_net_hide_admin left the Email Log menu item' );
			assert.doesNotMatch( dashboard.text, /page=safety_net_email_log/, 'safety_net_hide_admin left a link to an Email Log page' );
			assert.equal( ( await ajaxEntry( before[ 0 ].id, nonce, { expect: 400 } ) ).text, '0', 'safety_net_hide_admin left the popup\'s AJAX endpoint' );
			await site.post( ADMIN_POST, fields, { jar: site.adminJar, follow: false, expect: 400 } );
			assert.deepEqual( await log(), before, 'Clear log ran although safety_net_hide_admin hides it' );
			const sent = await send( [ { to: 'someone@example.com', subject: 'Sent while the admin page is hidden', message: 'x' } ] );
			assert.deepEqual( sent.entries?.map( ( entry ) => [ entry.subject, entry.status, entry.outcome ] ), [ [ 'Sent while the admin page is hidden', 'blocked', 'would_send' ] ], 'Hiding the admin page stopped logging' );

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

	test( 'E18: Delete removes one entry, and Clear log removes them all, keeps the table and returns to the list with its view, search and page', async () => {
		const before = await log();
		const target = before[ 1 ];
		const { fields } = actionForm( ( await site.get( entryPath( target.id ), { jar: site.adminJar } ) ).text, 'safety_net_email_log_delete' );
		const res = await site.post( ADMIN_POST, fields, { jar: site.adminJar, follow: false, expect: 302 } );
		assert.deepEqual( redirectArgs( site, res ), { ...PAGE_ARGS, result: 'deleted' } );
		assert.deepEqual( await log(), before.filter( ( entry ) => entry.id !== target.id ), 'Delete did not remove exactly that entry' );
		assert.ok( before.length > 21, 'The log fits on one page, so the pagination links are not tested' );
		const deleted = ( await site.get( res.location, { jar: site.adminJar } ) ).text;
		assert.equal( notice( deleted ), 'success: The log entry was deleted.' );
		const next = pagingLink( deleted, 'next' );
		assert.ok( next, 'The page after Delete has no link to the next page' );
		assert.doesNotMatch( decode( next ), /[?&]result=/, 'The next page link shows the Delete notice again' );
		assert.deepEqual( listedEntries( deleted ).filter( ( row ) => /[?&]result=/.test( decode( row.href ) ) ).map( ( row ) => row.id ), [], 'A View link shows the Delete notice again' );
		assert.doesNotMatch( actionForm( deleted, 'safety_net_email_log_clear' ).fields._wp_http_referer, /[?&]result=/, 'The Clear log form after Delete names a referer that shows the Delete notice again' );
		assert.doesNotMatch( decode( deleted.match( /<link id="wp-admin-canonical" rel="canonical" href="([^"]*)"/ )?.[ 1 ] ?? 'result=missing' ), /[?&]result=/, 'wp-admin does not remove the result from the address bar' );
		const again = await site.post( ADMIN_POST, fields, { jar: site.adminJar, follow: false, expect: 302 } );
		assert.equal( redirectArgs( site, again ).result, 'not-found' );
		assert.match( ( await site.get( entryPath( target.id ), { jar: site.adminJar } ) ).text, /That log entry does not exist\./ );

		const list = { outcome: 'would_send', s: 'example', paged: '2' };
		assert.ok( ( await log() ).filter( ( entry ) => entry.outcome === list.outcome && matches( entry, list.s ) ).length > 20, 'The search for "example" in Would send fits on one page, so Clear log keeping the page is not tested' );
		const filtered = await site.get( pageWith( list ), { jar: site.adminJar } );
		const clear = actionForm( filtered.text, 'safety_net_email_log_clear' );
		assert.deepEqual( queryOf( clear.fields._wp_http_referer ), { ...PAGE_ARGS, ...list } );
		const cleared = await site.post( ADMIN_POST, clear.fields, { jar: site.adminJar, follow: false, expect: 302 } );
		assert.deepEqual( redirectArgs( site, cleared ), { ...PAGE_ARGS, ...list, result: 'cleared' } );
		assert.deepEqual( await log(), [], 'Clear log left entries' );
		const s = await state();
		assert.deepEqual( [ s.table, s.options.safety_net_email_log_db_version, s.cleanup?.schedule ], [ true, '1', 'daily' ], 'Clear log removed the table, its version option or the cleanup event' );
		const page = ( await site.get( cleared.location, { jar: site.adminJar } ) ).text;
		assert.equal( notice( page ), 'success: The email log was cleared.' );
		assert.equal( emptyState( page ), 'No entries match.', 'The cleared list with a view and a search does not say that nothing matches' );
		const [ plain, view ] = ( await site.getAll( [ PAGE, pageWith( { outcome: 'sent' } ) ].map( ( target ) => ( { path: target, jar: site.adminJar } ) ) ) ).map( ( res ) => res.text );
		assert.equal( emptyState( plain ), 'No emails have been logged.' );
		assert.deepEqual( viewCounts( plain ), { all: 0, would_send: 0, would_fail: 0, sent: 0, failed: 0 } );
		assert.equal( emptyState( view ), 'No entries match.', 'An empty view without a search does not say that nothing matches' );
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

	test( 'debug.log has no fatal errors, no unexpected Safety Net warnings, no failed dry run but E23b\'s and no failed Raw view', () => {
		// E2b's refused CREATE TABLE is the one database error Safety Net may cause.
		site.assertCleanLog( [ { pattern: REFUSED_CREATE } ] );
		assert.deepEqual( site.logEntriesMatching( new RegExp( escapeRegExp( COULD_NOT_CHECK ) ) ).filter( ( line ) => ! line.includes( `${ COULD_NOT_CHECK }${ VALIDATOR_FAILED }` ) ), [], 'A dry run failed for another reason than PHPMailer refusing the email' );
		assert.deepEqual( site.logEntriesMatching( new RegExp( escapeRegExp( COULD_NOT_REBUILD ) ) ), [], 'A Raw view crashed' );
	} );
} );
