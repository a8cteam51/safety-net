import assert from 'node:assert/strict';
import { scrapeNonces } from './site.mjs';

export const STEP_FLAGS = [ 'options_scrubbed', 'plugins_deactivated', 'data_deleted', 'transients_deleted', 'webhooks_disabled' ];

export const TOOL_BUTTONS = [ 'safety-net-scrub-options', 'safety-net-deactivate-plugins', 'safety-net-disable-webhooks', 'safety-net-delete-users', 'safety-net-delete-transients' ];

export const AJAX_ACTIONS = [
	[ 'safety_net_scrub_options', 'safety-net-scrub-options', 'Options have been scrubbed.' ],
	[ 'safety_net_deactivate_plugins', 'safety-net-deactivate-plugins', 'Plugins have been deactivated.' ],
	[ 'safety_net_delete_users', 'safety-net-delete-users', 'Users, orders, and subscriptions have been successfully deleted!' ],
	[ 'safety_net_delete_transients', 'safety-net-delete-transients', 'Transients have been deleted.' ],
	[ 'safety_net_disable_webhooks', 'safety-net-disable-webhooks', 'Webhooks have been disabled.' ],
];

export const NO_PERMISSION = { success: false, message: 'You do not have permission to do that.' };

export const BAD_NONCE = { success: false, message: 'Security check failed. Refresh the page and try again.' };

export const GITHUB_RELEASE_URL = 'https://api.github.com/repos/a8cteam51/safety-net/releases/latest';

export const releaseHeaderUrl = ( tag ) => `https://raw.githubusercontent.com/a8cteam51/safety-net/${ tag }/safety-net.php`;

export function releaseHeader( requiresPhp ) {
	return { body: `<?php\n/*\n * Plugin Name: Safety Net\n * Version: 99.0.0\n${ requiresPhp ? ` * Requires PHP: ${ requiresPhp }\n` : '' }*/\n` };
}

export function githubRelease( tag, { asset = true } = {} ) {
	return {
		body: {
			tag_name: tag,
			html_url: `https://github.com/a8cteam51/safety-net/releases/tag/${ tag }`,
			assets: asset ? [ { name: 'safety-net.zip', browser_download_url: `https://github.com/a8cteam51/safety-net/releases/download/${ tag }/safety-net.zip` } ] : [ { name: 'other.zip', browser_download_url: 'https://example.com/other.zip' } ],
		},
	};
}

const describeProbe = ( line ) => `${ line.wp_cli ? 'wp-cli' : line.uri || '(PHP run)' } (blog ${ line.blog_id })`;

// Only the call under test counts, since any later request would also run the pass if this one had not.
export function assertAutomaticPassSince( site, before, { label, match, code = 200 } ) {
	const fresh = site.probe().slice( before ).filter( match );
	const ran = fresh.find( ( line ) => line.runs?.safety_net_scrub_options > 0 );
	assert.ok( ran, `${ label } did not run Safety Net's automatic pass (${ site.where() }). Its probe lines: ${ fresh.map( describeProbe ).join( ', ' ) || 'none' }` );
	const earlier = site.probe().slice( 0, before ).filter( ( line ) => line.blog_id === ran.blog_id && line.runs?.safety_net_scrub_options > 0 );
	assert.deepEqual( earlier.map( describeProbe ), [], `Safety Net already ran on blog ${ ran.blog_id } before ${ label }, so the harness enabled it too early (${ site.where() })` );
	if ( code !== null ) {
		assert.equal( ran.code, code, `The request that ran Safety Net returned ${ ran.code } (${ site.where() })` );
	}
	assert.equal( ran.fatal, null, `The request that ran Safety Net hit a fatal error: ${ JSON.stringify( ran.fatal ) }` );
	for ( const hook of [ 'safety_net_deactivate_plugins', 'safety_net_delete_data', 'safety_net_delete_transients', 'safety_net_disable_webhooks' ] ) {
		assert.equal( ran.runs[ hook ], 1, `${ hook } did not fire on the first load` );
	}
	return ran;
}

export const isHttpProbe = ( line ) => ! line.php_run && ! line.wp_cli;

export async function firstLoad( site, path = '/' ) {
	const before = site.probe().length;
	const res = await site.get( path );
	return { res, probe: assertAutomaticPassSince( site, before, { label: `GET ${ path }`, match: isHttpProbe } ) };
}

// The probe lines of HTTP requests made since `before`, for checks that must not be satisfied by a later PHP run.
export function httpProbeSince( site, before ) {
	return site.probe().slice( before ).filter( isHttpProbe );
}

export function assertStepFlags( flags, { woocommerce } ) {
	for ( const step of STEP_FLAGS ) {
		assert.equal( flags[ `safety_net_${ step }` ], '1', `safety_net_${ step } is not set; flags: ${ JSON.stringify( flags ) }` );
	}
	assert.equal( flags.safety_net_pause_renewal_actions_toggle, 'on' );
	if ( woocommerce ) {
		assert.equal( flags.safety_net_gateway_plugins_deactivated, '1', 'The gateway pass did not run although WooCommerce is active' );
		assert.equal( flags.safety_net_gateway_plugins_pending, undefined );
	} else {
		assert.equal( flags.safety_net_gateway_plugins_pending, '1', 'Without WooCommerce the gateway pass must stay pending' );
		assert.equal( flags.safety_net_gateway_plugins_deactivated, undefined );
	}
}

// Known-issue checks that look for an absent log line would pass on a site where Safety Net never ran.
export async function assertDataDeleted( site, { blog = null, ...options } = {} ) {
	const flags = await site.php( blog === null ? 'return sn_test_flags();' : `return sn_test_network_site_state( ${ blog } )['flags'];`, { label: 'reading the step flags', ...options } );
	assert.equal( flags.safety_net_data_deleted, '1', `Safety Net never deleted data${ blog === null ? '' : ` on blog ${ blog }` }, so this check would pass for the wrong reason. Flags: ${ JSON.stringify( flags ) }` );
}

export const FILTER_PROBE = "$crons = array( 'pmpro_cron_expire_memberships' => array( 'hook' => 'pmpro_cron_expire_memberships', 'interval' => 'daily' ) ); wp_get_ready_cron_jobs(); return array( 'jetpack' => apply_filters( 'jetpack_subscriptions_exclude_all_categories_except', array() ), 'pmpro' => apply_filters( 'pmpro_registered_crons', $crons ), 'pmpro_input' => $crons );";

export function assertNoFlags( flags ) {
	assert.deepEqual( flags, [], `Safety Net wrote its flags on a site where it must stay dormant: ${ JSON.stringify( flags ) }` );
}

export const toolsPagePath = ( prefix = '' ) => `${ prefix }/wp-admin/tools.php?page=safety_net_options`;

export function assertToolsPage( res ) {
	assert.match( res.text, /id="safety-net-settings-title"/, 'The Tools > Safety Net page has no title' );
	const nonces = scrapeNonces( res.text );
	assert.deepEqual( Object.keys( nonces ).sort(), [ ...TOOL_BUTTONS ].sort(), 'The Tools page is missing tool buttons or their nonces' );
	return { res, nonces };
}

export async function getToolsPage( site, { jar = site.adminJar, prefix = '' } = {} ) {
	return assertToolsPage( await site.get( toolsPagePath( prefix ), { jar } ) );
}

// Posts the form as the browser does with the pause checkbox unticked, which leaves the field out.
export async function saveToolsForm( site ) {
	const { res } = await getToolsPage( site );
	const nonce = res.text.match( /name="_wpnonce" value="([a-f0-9]+)"/ )?.[ 1 ];
	assert.ok( nonce, 'The Tools form has no settings nonce' );
	const save = await site.post( '/wp-admin/options.php', { option_page: 'safety-net', action: 'update', _wpnonce: nonce, _wp_http_referer: '/wp-admin/tools.php?page=safety_net_options' }, { jar: site.adminJar, follow: false, expect: 302 } );
	assert.match( save.location, /settings-updated=true/ );
}

// Fetching the enqueued assets also proves SAFETY_NET_URL is right for the install location.
export async function assertToolsAssets( site, html, expectedDir ) {
	const script = html.match( /<script[^>]+src=['"]([^'"]*assets\/js\/safety-net-admin\.js[^'"]*)['"]/ )?.[ 1 ];
	const style = html.match( /<link[^>]+href=['"]([^'"]*assets\/css\/admin\.css[^'"]*)['"]/ )?.[ 1 ];
	assert.ok( script, 'safety-net-admin.js is not enqueued on the Tools page' );
	assert.ok( style, 'admin.css is not enqueued on the Tools page' );
	for ( const url of [ script, style ] ) {
		assert.ok( url.includes( expectedDir ), `${ url } is not served from ${ expectedDir }` );
		const asset = await site.get( url.replace( /&#038;/g, '&' ) );
		assert.ok( asset.text.length > 0, `${ url } is empty` );
	}
}

export async function postAjax( site, action, nonce, { jar = site.adminJar, prefix = '' } = {} ) {
	const res = await site.post( `${ prefix }/wp-admin/admin-ajax.php`, { action, nonce }, { jar } );
	try {
		return JSON.parse( res.text );
	} catch {
		assert.fail( `${ action } did not return JSON (${ site.where() }): ${ res.text.slice( 0, 500 ) }` );
	}
}

// Seeds a subscriber, a transient and optionally an order after the automatic pass, so only the tools can remove them.
export async function runAjaxTools( site, nonces, { prefix = '', order = false } = {} ) {
	const php = { path: `${ prefix }/` };
	const seeded = await site.php( `return sn_test_seed_tool_targets( ${ order } );`, { ...php, label: 'seeding data for the AJAX tools' } );
	assert.deepEqual( seeded.state, { user: true, transient: 'x', transient_timeout: true, order: order ? true : null, admins: seeded.admins, ai: 'sk-test-tool' }, 'Seeding data for the AJAX tools failed' );
	for ( const [ action, button, message ] of AJAX_ACTIONS ) {
		assert.deepEqual( await postAjax( site, action, nonces[ button ], { prefix } ), { success: true, message }, `${ action } returned an unexpected response` );
	}
	const after = await site.php( `return sn_test_tool_targets( ${ seeded.user }, ${ seeded.order ?? 'null' }, json_decode( '${ JSON.stringify( seeded.admins ) }', true ) );`, { ...php, label: 'checking what the AJAX tools removed' } );
	assert.equal( after.user, false, 'The Delete tool left a subscriber created after the automatic pass' );
	assert.equal( after.transient, null, 'The Delete Transients tool left the _transient_ row' );
	assert.equal( after.transient_timeout, false, 'The Delete Transients tool left the _transient_timeout_ row' );
	if ( order ) {
		assert.equal( after.order, false, 'The Delete tool left an order created after the automatic pass' );
	}
	assert.deepEqual( after.admins, seeded.admins, 'The Delete tool removed an administrator' );
	assert.equal( after.ai, null, 'The Scrub Options tool left an AI provider key' );
}

// The seeds mark each secret inside a provider plugin's settings with an sn-secret- value.
const withoutSeededSecrets = ( value ) => {
	if ( typeof value === 'string' ) {
		return value.startsWith( 'sn-secret-' ) ? '' : value;
	}
	if ( value && typeof value === 'object' ) {
		return Array.isArray( value ) ? value.map( withoutSeededSecrets ) : Object.fromEntries( Object.entries( value ).map( ( [ key, item ] ) => [ key, withoutSeededSecrets( item ) ] ) );
	}
	return value;
};

// Only null (no row) counts as deleted; '' would mean the key was blanked and its row kept.
export function assertAiKeysScrubbed( now, seeded, label ) {
	const left = Object.entries( now.keys ).filter( ( [ , value ] ) => value !== null ).map( ( [ name ] ) => name );
	assert.deepEqual( left, [], `${ label } left these AI provider credentials` );
	assert.deepEqual( now.settings, withoutSeededSecrets( seeded.settings ), `${ label } did not blank exactly the secrets in AI provider plugins' settings` );
	assert.deepEqual( now.backups, [], `${ label } left backups of AI provider credentials` );
	assert.deepEqual( now.controls, seeded.controls, `${ label } changed options it must keep` );
}

// The scrub blanks to '' or array() by the type the option had, and backs up the seeded value.
export function assertOptionsBlanked( state, label ) {
	for ( const [ name, { seeded, value, backup } ] of Object.entries( state ) ) {
		const blank = typeof seeded === 'string' ? '' : [];
		assert.deepEqual( { value, backup }, { value: blank, backup: seeded }, `${ label }: ${ name } was not blanked with a backup of its value` );
	}
}

// Sentinels that each tool would change, to prove a refused AJAX request did nothing.
export async function seedAjaxSentinels( site, login ) {
	const seeded = await site.php( `return sn_test_seed_ajax_sentinels( '${ login }' );`, { label: 'seeding AJAX sentinels' } );
	return { seeded, mark: site.logMark() };
}

export async function assertAjaxSentinelsUntouched( site, { seeded, mark } ) {
	const now = await site.php( `return sn_test_ajax_sentinels( '${ seeded.login }' );`, { label: 'checking AJAX sentinels' } );
	assert.deepEqual( now, seeded.state, 'A refused AJAX request still changed data' );
	site.assertLogAlive();
	assert.deepEqual( site.logEntriesSince( mark ).filter( ( entry ) => /WordPress database error|SN_TEST attributed/.test( entry ) ), [], 'New Safety Net warnings or database errors were logged during the refused AJAX requests (a tool may have run)' );
}

// Nonces are tied to the session token in the logged-in cookie, so they are built from that cookie.
export async function noncesForSession( site, jar, login ) {
	const cookie = [ ...jar.cookies ].find( ( [ name ] ) => name.startsWith( 'wordpress_logged_in_' ) );
	assert.ok( cookie, `${ login } has no logged-in cookie` );
	const value = Buffer.from( decodeURIComponent( cookie[ 1 ] ) ).toString( 'base64' );
	const result = await site.php(
		`$_COOKIE[ LOGGED_IN_COOKIE ] = base64_decode( '${ value }' );
$user = wp_validate_auth_cookie( '', 'logged_in' );
if ( ! $user || get_userdata( $user )->user_login !== '${ login }' ) { throw new RuntimeException( 'The cookie does not log in ${ login }' ); }
wp_set_current_user( $user );
$nonces = array();
foreach ( json_decode( '${ JSON.stringify( TOOL_BUTTONS ) }', true ) as $button ) { $nonces[ $button ] = wp_create_nonce( $button ); }
return $nonces;`,
		{ label: `creating nonces for ${ login }` }
	);
	assert.deepEqual( Object.keys( result ).sort(), [ ...TOOL_BUTTONS ].sort() );
	return result;
}

export function assertNoIndex( html ) {
	const robots = html.match( /<meta name=['"]robots['"] content=['"]([^'"]+)['"]/ )?.[ 1 ] ?? '';
	assert.match( robots, /noindex/, `The page's robots meta tag is "${ robots }", expected noindex` );
	assert.doesNotMatch( robots, /max-image-preview/, `The page's robots meta tag is "${ robots }"` );
}

export function robotsLines( text ) {
	return text.split( '\n' ).map( ( line ) => line.trim() ).filter( Boolean );
}

export async function captureMail( site, options = {} ) {
	return site.php(
		`
$captured = array();
add_filter( 'pre_wp_mail', function ( $return, $atts ) use ( &$captured ) {
	$captured[ $atts['subject'] ] = $return;
	return false;
}, 11, 2 );
$blocked  = wp_mail( 'someone@example.com', 'Order receipt', 'body' );
$subject  = '[' . wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) . '] Password Reset';
wp_mail( 'someone@example.com', $subject, 'body' );
return array(
	'blocked'          => $blocked,
	'captured'         => $captured,
	'reset_subject'    => $subject,
	'password_change'  => apply_filters( 'send_password_change_email', true ),
	'email_change'     => apply_filters( 'send_email_change_email', true ),
	'stop_emails_hook' => has_filter( 'pre_wp_mail', 'SafetyNet\\\\Admin\\\\stop_emails' ),
);`,
		{ label: 'sending test emails', ...options }
	);
}

export function assertMailBlocked( mail ) {
	assert.equal( mail.stop_emails_hook, 10, 'stop_emails is not hooked to pre_wp_mail' );
	assert.equal( mail.blocked, false, 'wp_mail() reported success for a regular email' );
	assert.equal( mail.captured[ 'Order receipt' ], false, 'Safety Net did not short-circuit a regular email' );
	assert.equal( mail.captured[ mail.reset_subject ], null, 'Safety Net blocked a password reset email' );
	assert.equal( mail.password_change, false );
	assert.equal( mail.email_change, false );
}
