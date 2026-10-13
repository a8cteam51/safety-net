import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };

// Decodes once, as a browser does with text and attribute values.
export const decode = ( html ) => html.replace( /&(?:#(\d+)|#x([\da-f]+)|([a-z]+));/gi, ( entity, dec, hex, name ) => {
	if ( dec || hex ) {
		return String.fromCodePoint( dec ? Number( dec ) : parseInt( hex, 16 ) );
	}
	return ENTITIES[ name.toLowerCase() ] ?? entity;
} );

export const SEND_EMAILS = 'safety_net_send_emails_toggle';

export const SEND_EMAILS_SITE = 'safety_net_send_emails_site';

// What Safety Net stores for the address Send emails was switched on at: the md5 of the stored home option and WP_HOME, each as host, port and path, lowercased, without a trailing slash, the same one once.
export const siteKey = ( ...urls ) => createHash( 'md5' ).update( [ ...new Set( urls.map( ( url ) => {
	const parsed = new URL( url );
	return `${ parsed.host }${ parsed.pathname }`.toLowerCase().replace( /[/\\]+$/, '' );
} ) ) ].join( ' ' ) ).digest( 'hex' );

// Points the stored home option elsewhere, as copying the site with a search-replace does; WP_HOME keeps the site reachable.
export const setStoredHome = ( site, url, options = {} ) => site.php( `global $wpdb; $wpdb->update( $wpdb->options, array( 'option_value' => ${ JSON.stringify( url ) } ), array( 'option_name' => 'home' ) ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'home', 'options' ); return defined( 'WP_HOME' );`, { label: `storing ${ url } as the home option`, ...options } );

// The dashicon and the screen reader text each state of the status panel must have.
export const ICONS = { ok: [ 'yes-alt', 'OK:' ], warning: [ 'warning', 'Warning:' ], danger: [ 'dismiss', 'Danger:' ] };

// The rows of the Tools page's status panel, decoded, or null without the panel; a row the pattern does not read fails, so none is skipped.
export function statusRows( html ) {
	const panel = html.match( /<div class="safety-net-status">[\s\S]*?<\/table>\s*<\/div>/ )?.[ 0 ];
	if ( ! panel ) {
		return null;
	}
	const rows = [ ...panel.matchAll( /<tr class="safety-net-status-row safety-net-status-([a-z_]+) safety-net-status-([a-z]+)"><th scope="row">([^<]*)<\/th><td class="safety-net-status-state"><span class="dashicons dashicons-([a-z-]+)" aria-hidden="true"><\/span><span class="screen-reader-text">([^<]*)<\/span> ([^<]*)<\/td><td class="safety-net-status-details">([^<]*?)(?: <a href="([^"]*)">([^<]*)<\/a>)?<\/td><\/tr>/g ) ].map( ( [ , id, state, label, icon, severity, status, details, href, text ] ) => ( {
		id,
		label: decode( label ),
		state,
		status: decode( status ),
		details: decode( details ),
		link: href === undefined ? null : { url: decode( href ), text: decode( text ) },
		icon,
		severity: decode( severity ),
	} ) );
	assert.equal( rows.length, ( panel.match( /<tr class="safety-net-status-row /g ) ?? [] ).length, 'A status row holds markup it must not, such as an unescaped value' );
	return rows;
}

// The warnings that emails are switched on: their text, link and WP-CLI command, decoded, and any other markup they hold.
export function emailsNotices( html ) {
	return [ ...html.matchAll( /<div class="notice notice-error safety-net-emails-enabled"><p><strong>([^<]*)<\/strong> ([^<]*)<\/p><p>([\s\S]*?)<\/p><\/div>/g ) ].map( ( [ , title, text, how ] ) => {
		const link = how.match( /<a href="([^"]*)">([^<]*)<\/a>/ );
		return {
			title: decode( title ),
			text: decode( text ),
			link: link ? { url: decode( link[ 1 ] ), text: decode( link[ 2 ] ) } : null,
			command: decode( how.match( /<code>([^<]*)<\/code>/ )?.[ 1 ] ?? '' ),
			how: decode( how.replace( /<[^>]*>/g, '' ) ),
			markup: [ ...how.matchAll( /<\/?([a-z0-9]+)\b[^>]*>/gi ) ].map( ( [ , tag ] ) => tag.toLowerCase() ).filter( ( tag ) => ! [ 'a', 'code' ].includes( tag ) ),
		};
	} );
}

// The warnings that emails are switched on as the page has them, to check what they escape.
export const emailsNoticesHtml = ( html ) => [ ...html.matchAll( /<div class="notice notice-error safety-net-emails-enabled">[\s\S]*?<\/div>/g ) ].map( ( [ match ] ) => match ).join( '\n' );

// The renewal row shows only where something can renew, so a check that reads it runs with a pending renewal action, deleted after.
export async function withRenewal( site, check, { path = '/' } = {} ) {
	const id = await site.php( 'return sn_test_schedule_renewal();', { path, label: 'scheduling a renewal action' } );
	assert.ok( id > 0, 'Scheduling a renewal action failed' );
	try {
		return await check();
	} finally {
		await site.php( `return sn_test_delete_action( ${ id } );`, { path, label: 'deleting the renewal action' } );
	}
}

// The id, state and status of each row, which is what a check usually needs.
export const statusSummary = ( rows ) => rows.map( ( { id, state, status } ) => [ id, state, status ] );

// The rows as get_status_rows() returns them, without what the panel adds to show them.
export const withoutIcons = ( rows ) => rows.map( ( { icon, severity, ...row } ) => row );

// The settings form's hidden fields, and the switch's checkbox, the hidden field that keeps a disabled one's value, and its description.
export function sendEmailsForm( html ) {
	const form = [ ...html.matchAll( /<form\b[\s\S]*?<\/form>/g ) ].map( ( [ match ] ) => match ).find( ( candidate ) => /\sname=["']option_page["'] value=["']safety-net-email-log["']/.test( candidate ) );
	assert.ok( form, 'The Email Log Settings page has no form for the safety-net-email-log settings group' );
	const fields = {};
	for ( const [ input ] of form.matchAll( /<input\b[^>]*\stype=["']hidden["'][^>]*>/g ) ) {
		const name = input.match( /\sname=["']([^"']*)["']/ )?.[ 1 ];
		if ( name && name !== SEND_EMAILS ) {
			fields[ name ] = decode( input.match( /\svalue=["']([^"']*)["']/ )?.[ 1 ] ?? '' );
		}
	}
	assert.deepEqual( [ 'option_page', 'action', '_wpnonce', '_wp_http_referer' ].filter( ( name ) => ! fields[ name ] ), [], 'The Settings form lacks a hidden field options.php needs' );
	const row = form.match( new RegExp( `<tr class="([^"]*)"><th scope="row"><label for="${ SEND_EMAILS }">Send emails</label></th><td>([\\s\\S]*?)</td></tr>` ) );
	assert.ok( row, 'The Email Log Settings form has no Send emails row' );
	const input = row[ 2 ].match( new RegExp( `<input id="${ SEND_EMAILS }" class="([^"]*)" name="${ SEND_EMAILS }" type="checkbox"([^>]*) />` ) );
	assert.ok( input, 'The Send emails row has no checkbox' );
	const description = row[ 2 ].match( /<p class="([^"]*)" id="tagline-description">([^<]*)<\/p>/ );
	const hidden = row[ 2 ].match( new RegExp( `<input type="hidden" name="${ SEND_EMAILS }" value="([^"]*)" />` ) );
	return {
		html: form,
		fields,
		checkboxes: [ ...form.matchAll( /<input id="([a-z_]+)" class="[^"]*" name="[a-z_]+" type="checkbox"/g ) ].map( ( [ , name ] ) => name ),
		input: {
			classes: input[ 1 ].split( ' ' ),
			checked: /\schecked=/.test( input[ 2 ] ),
			disabled: /\sdisabled=/.test( input[ 2 ] ),
			confirm: input[ 2 ].match( /\sdata-safety-net-confirm="([^"]*)"/ )?.[ 1 ],
		},
		hidden: hidden ? decode( hidden[ 1 ] ) : null,
		description: description ? { classes: description[ 1 ].split( ' ' ), text: decode( description[ 2 ] ) } : null,
	};
}
