import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { after, before, describe, test } from 'node:test';
import { AJAX_ACTIONS, assertAiKeysScrubbed, assertMailBlocked, assertStepFlags, captureMail, firstLoad, getToolsPage, postAjax, runAjaxTools } from '../lib/checks.mjs';
import { CookieJar } from '../lib/http.mjs';
import { bootSite } from '../lib/site.mjs';

const NETWORK_STATE = "return array( 'users' => sn_test_users(), 'main' => sn_test_network_site_state( 1 ), 'shop' => sn_test_network_site_state( 2 ), 'empty' => sn_test_network_site_state( 3 ) );";

const SURVIVORS = [ 'admin', 'netadmin', 'shopowner', 'superhelper' ];

const NETWORK_EMAILS = "global $wpdb; return array( 'current' => get_site_option( 'admin_email' ), 'other' => $wpdb->get_var( \"SELECT meta_value FROM $wpdb->sitemeta WHERE site_id = 2 AND meta_key = 'admin_email'\" ) );";

const SCRUBBED_EMAIL = 'safetynet@scrubbedthis.option';
const OWNER_EMAIL = 'netowner@example.com';
const NETWORK_DENIED = [ 'ai/ai.php', 'ai-provider-for-anthropic/plugin.php', 'mailchimp-for-wp/mailchimp-for-wp.php' ];
const SET_NETWORK = `return sn_test_set_network( array( '${ NETWORK_DENIED.join( "', '" ) }' ), '${ OWNER_EMAIL }' );`;
// Without WooCommerce there is no gateway pass, so no gateway flag either.
const NETWORK_FLAGS = { safety_net_network_admin_email_scrubbed: '1', safety_net_network_plugins_deactivated: '1' };

const KEYS_PUT_BACK = 'Its password reset and confirmation keys are put back, so those links work until WordPress expires the keys, after 24 hours by default, or a newer request replaces them.';
const KEYS_FOR_SUPER_ADMINS = 'Its password reset and confirmation keys stay redacted, so those links will not work: only super admins get the keys back.';

const SITE_TOOLS = AJAX_ACTIONS.filter( ( [ action ] ) => [ 'safety_net_scrub_options', 'safety_net_deactivate_plugins' ].includes( action ) );

function assertNetworkUntouched( network, label ) {
	assert.equal( network.admin_email, OWNER_EMAIL, `${ label } changed the network admin email` );
	assert.deepEqual( NETWORK_DENIED.filter( ( plugin ) => ! network.plugins.includes( plugin ) ), [], `${ label } deactivated network-active plugins` );
}

function assertNetworkChanged( network, label ) {
	assert.equal( network.admin_email, SCRUBBED_EMAIL, `${ label } did not scrub the network admin email` );
	assert.deepEqual( NETWORK_DENIED.filter( ( plugin ) => network.plugins.includes( plugin ) ), [], `${ label } left denylisted plugins network-active` );
	assert.deepEqual( network.flags, NETWORK_FLAGS, `${ label } did not record the network-wide changes` );
}

describe( 'multisite-main-first: subdirectory network with Safety Net as an mu-plugin, main site loaded first', () => {
	let site;
	let seed;

	before( async () => {
		site = await bootSite( { name: 'multisite-main-first', env: 'staging', mode: 'mu', multisite: true } );
		seed = await site.php( 'return sn_test_seed_network();', { label: 'seeding the network' } );
		// The second network (netadmin's) gets an admin email of its own, which a run on this network must not touch.
		const emails = await site.php( `global $wpdb; $wpdb->insert( $wpdb->sitemeta, array( 'site_id' => 2, 'meta_key' => 'admin_email', 'meta_value' => 'network2@example.com' ) ); ${ NETWORK_EMAILS }`, { label: 'seeding network admin emails' } );
		assert.ok( emails.current && emails.current !== 'safetynet@scrubbedthis.option', `The network admin email was not seeded: ${ emails.current }` );
		assert.equal( emails.other, 'network2@example.com' );
		const network = await site.php( `sn_test_install_fixture_plugins(); return sn_test_set_network( array( '${ NETWORK_DENIED.join( "', '" ) }' ), get_site_option( 'admin_email' ) );`, { label: 'network-activating a denylisted plugin' } );
		assert.deepEqual( NETWORK_DENIED.filter( ( plugin ) => ! network.plugins.includes( plugin ) ), [], 'Network-activating the denylisted plugin failed' );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'M1: the main site runs first and keeps every site\'s administrators and all super admins', async () => {
		await firstLoad( site, '/' );
		const s = await site.php( NETWORK_STATE );
		assert.deepEqual( Object.keys( s.users ).sort(), SURVIVORS );
		assertStepFlags( s.main.flags, { woocommerce: false } );
		assert.equal( s.main.admin_email, 'safetynet@scrubbedthis.option' );
		assert.deepEqual( s.main.post_authors, [ 1 ] );
		assert.deepEqual( s.shop.flags, [], 'The shop site ran before it was loaded' );
		assert.equal( s.shop.admin_email, 'shopowner@example.com' );
		assert.equal( s.shop.klaviyo, 'pk_live_shop' );
	} );

	test( 'M13: the main site\'s first run deletes its own AI provider keys and leaves the other sites\' until they load', async () => {
		const s = await site.php( NETWORK_STATE );
		assertAiKeysScrubbed( s.main.ai, seed.ai.main, 'The main site\'s first run' );
		assert.deepEqual( s.shop.ai, seed.ai.shop, 'The main site\'s first run changed the shop\'s AI keys or their backups' );
		assert.deepEqual( s.empty.ai, seed.ai.empty, 'The main site\'s first run changed /empty/\'s AI keys or their backups' );
	} );

	test( 'K5/K11: the first site\'s run applies the network-wide changes once for the network, and then a super admin reverts them', async () => {
		assertNetworkChanged( await site.php( 'return sn_test_network_state();' ), 'The main site\'s first run' );
		assertNetworkUntouched( await site.php( SET_NETWORK, { label: 'reverting the network-wide changes' } ), 'Reverting the network-wide changes' );
	} );

	test( 'M1: the shop site runs on its own first load', async () => {
		await firstLoad( site, '/shop/' );
		const s = await site.php( NETWORK_STATE );
		assertStepFlags( s.shop.flags, { woocommerce: false } );
		assert.equal( s.shop.admin_email, 'safetynet@scrubbedthis.option' );
		assert.equal( s.shop.klaviyo, '' );
		assert.equal( s.shop.transient, null );
		assert.deepEqual( s.shop.post_authors, [ seed.users.shopowner ], 'Shop posts should go to the shop administrator' );
		assert.deepEqual( Object.keys( s.users ).sort(), SURVIVORS );
	} );

	test( 'M13: the shop\'s own first run deletes its AI provider keys', async () => {
		const s = await site.php( NETWORK_STATE );
		assertAiKeysScrubbed( s.shop.ai, seed.ai.shop, 'The shop\'s first run' );
		assert.deepEqual( s.empty.ai, seed.ai.empty, 'The shop\'s first run changed /empty/\'s AI keys or their backups' );
	} );

	test( 'M1: a site without its own administrator reassigns posts to a super admin', async () => {
		await firstLoad( site, '/empty/' );
		const s = await site.php( NETWORK_STATE );
		assertStepFlags( s.empty.flags, { woocommerce: false } );
		assert.deepEqual( s.empty.post_authors, [ 1 ] );
	} );

	test( 'K5/K11: the first runs of sites not loaded yet, and of a new site, leave the super admin\'s network-wide changes alone', async () => {
		assertNetworkUntouched( await site.php( 'return sn_test_network_state();' ), 'The first runs of /shop/ and /empty/' );
		const blog = await site.php( `$blog = wpmu_create_blog( DOMAIN_CURRENT_SITE, PATH_CURRENT_SITE . 'later/', 'Later', ${ seed.users.shopowner } ); if ( is_wp_error( $blog ) ) { throw new RuntimeException( $blog->get_error_message() ); } return $blog;`, { label: 'creating a site' } );
		await firstLoad( site, '/later/' );
		assertStepFlags( ( await site.php( `return sn_test_network_site_state( ${ blog } );` ) ).flags, { woocommerce: false } );
		const network = await site.php( 'return sn_test_network_state();' );
		assertNetworkUntouched( network, 'The new site\'s first run' );
		assert.deepEqual( network.flags, NETWORK_FLAGS );
	} );

	test( 'M4: front ends, REST routes, network admin and the subsite Tools page load', async () => {
		const [ , , , status ] = await site.getAll( [ '/', '/shop/', '/empty/', '/shop/wp-json/safety-net/v1/status', '/shop/?rest_route=/safety-net/v1/status' ] );
		const shopStatus = JSON.parse( status.text );
		assert.equal( shopStatus.data_deleted, true );
		assert.equal( shopStatus.data_kept, false );
		const jar = await site.login();
		const [ , , , , shopDashboard ] = await site.getAll( [ '/wp-admin/network/', '/wp-admin/network/plugins.php', '/wp-admin/network/sites.php', '/wp-admin/network/users.php', '/shop/wp-admin/', '/shop/wp-admin/plugins.php' ].map( ( path ) => ( { path, jar } ) ) );
		assert.match( shopDashboard.text, /Safety Net Activated/ );
	} );

	test( 'K5/K11: a site administrator\'s Scrub Options and Deactivate Plugins change only their own site', async () => {
		const seeded = await site.php( `wp_set_password( 'password', ${ seed.users.shopowner } ); switch_to_blog( 2 ); update_option( 'klaviyo_api_key', 'pk_live_again' ); update_option( 'connectors_ai_openai_api_key', 'sk-test-again-shop' ); update_option( 'admin_email', 'shopowner@example.com' ); sn_test_activate_plugins( array( 'wp-mail-smtp/wp_mail_smtp.php' ) ); $active = get_option( 'active_plugins' ); $ai = array( 'shop' => sn_test_raw_option( 'connectors_ai_openai_api_key' ) ); restore_current_blog(); update_option( 'connectors_ai_openai_api_key', 'sk-test-again-main' ); $ai['main'] = sn_test_raw_option( 'connectors_ai_openai_api_key' ); return array( 'active' => $active, 'ai' => $ai, 'super' => is_super_admin( ${ seed.users.shopowner } ), 'network' => sn_test_set_network( array( '${ NETWORK_DENIED.join( "', '" ) }' ), '${ OWNER_EMAIL }' ) );`, { label: 'seeding the shop site for its administrator' } );
		assert.equal( seeded.super, false, 'shopowner must not be a super admin' );
		assert.deepEqual( seeded.ai, { shop: 'sk-test-again-shop', main: 'sk-test-again-main' }, 'Re-seeding the AI provider keys on the shop and the main site failed' );
		assert.ok( seeded.active.includes( 'wp-mail-smtp/wp_mail_smtp.php' ), 'Activating a denylisted plugin on the shop site failed' );
		assertNetworkUntouched( seeded.network, 'Seeding' );
		const jar = await site.login( { user: 'shopowner', jar: new CookieJar(), redirectTo: '/shop/wp-admin/' } );
		const { nonces } = await getToolsPage( site, { jar, prefix: '/shop' } );
		for ( const [ action, button, message ] of SITE_TOOLS ) {
			assert.deepEqual( await postAjax( site, action, nonces[ button ], { jar, prefix: '/shop' } ), { success: true, message }, `${ action } returned an unexpected response` );
		}
		const s = await site.php( "switch_to_blog( 2 ); $active = get_option( 'active_plugins' ); restore_current_blog(); return array( 'active' => $active, 'main' => sn_test_network_site_state( 1 ), 'shop' => sn_test_network_site_state( 2 ), 'network' => sn_test_network_state() );" );
		assert.equal( s.shop.admin_email, SCRUBBED_EMAIL, 'Scrub Options did not scrub the shop\'s admin email' );
		assert.equal( s.shop.klaviyo, '', 'Scrub Options did not scrub the shop\'s options' );
		assert.equal( s.shop.ai.keys.connectors_ai_openai_api_key, null, 'Scrub Options did not delete the shop\'s AI provider key' );
		assert.equal( s.main.ai.keys.connectors_ai_openai_api_key, 'sk-test-again-main', 'The shop administrator\'s Scrub Options deleted the main site\'s AI provider key' );
		assert.ok( ! s.active.includes( 'wp-mail-smtp/wp_mail_smtp.php' ), 'Deactivate Plugins left the shop\'s denylisted plugin active' );
		assertNetworkUntouched( s.network, 'A site administrator\'s tools' );
		assert.deepEqual( s.network.flags, NETWORK_FLAGS );
	} );

	test( 'K5/K11: a super admin\'s Scrub Options and Deactivate Plugins on a subsite also apply the network-wide changes', async () => {
		const network = await site.php( SET_NETWORK, { label: 'reverting the network-wide changes' } );
		assertNetworkUntouched( network, 'Reverting the network-wide changes' );
		// The flags stay set: they only stop automatic runs, so a super admin's tools must still apply the changes.
		assert.deepEqual( network.flags, NETWORK_FLAGS );
		await site.login();
		const { nonces } = await getToolsPage( site, { prefix: '/shop' } );
		for ( const [ action, button, message ] of SITE_TOOLS ) {
			assert.deepEqual( await postAjax( site, action, nonces[ button ], { prefix: '/shop' } ), { success: true, message }, `${ action } returned an unexpected response` );
		}
		assertNetworkChanged( await site.php( 'return sn_test_network_state();' ), 'A super admin\'s tools' );
	} );

	test( 'S10/M12: the AJAX tools work on a subsite', async () => {
		const { nonces } = await getToolsPage( site, { prefix: '/shop' } );
		await runAjaxTools( site, nonces, { prefix: '/shop' } );
		assert.deepEqual( Object.keys( await site.php( 'return sn_test_users();' ) ).sort(), SURVIVORS );
	} );

	test( 'M12: the subsite discourages search engines and blocks email, and logs it in its own email log', async () => {
		const s = await site.php( "return array( 'blog' => get_current_blog_id(), 'filtered' => get_option( 'blog_public' ), 'raw' => sn_test_raw_option( 'blog_public' ) );", { path: '/shop/' } );
		assert.deepEqual( s, { blog: 2, filtered: 0, raw: '1' } );
		const mainMark = await site.php( 'return sn_test_email_log_last_id();', { label: 'reading the main site\'s last email log entry' } );
		const mail = await captureMail( site, { path: '/shop/' } );
		assertMailBlocked( mail );
		const logs = await site.php( `$shop = sn_test_email_log(); switch_to_blog( 1 ); $main = sn_test_email_log( ${ mainMark } ); restore_current_blog(); return array( 'shop' => $shop, 'main' => $main );`, { path: '/shop/', label: 'reading the shop\'s and the main site\'s email logs' } );
		assert.deepEqual( logs.shop?.slice( -2 ).map( ( entry ) => [ entry.subject, entry.status ] ), [ [ 'Order receipt', 'blocked' ], [ mail.reset_subject, 'allowed' ] ], 'The shop\'s emails are not at the end of its own email log' );
		assert.deepEqual( ( logs.main ?? [] ).map( ( entry ) => entry.subject ), [], 'The shop\'s emails were logged in the main site\'s email log' );
	} );

	test( 'M15: Send to me on a subsite puts the keys back for a super admin only, not for a site administrator, even one given manage_network_users, which lets them edit users', async () => {
		const reset = 'Reset your password: https://example.test/shop/wp-login.php?action=rp&key=SHOPKEY1&login=customer';
		const s = await site.php( `
wp_set_password( 'password', ${ seed.users.shopowner } );
$owner = new WP_User( ${ seed.users.shopowner } );
update_option( 'sn_test_capture_mail', 1 );
return array(
	'owner' => array( $owner->user_email, is_super_admin( $owner->ID ), $owner->has_cap( 'manage_options' ), $owner->has_cap( 'edit_users' ) ),
	'admin' => get_userdata( 1 )->user_email,
	'sent'  => sn_test_send_emails( array( array( 'to' => 'customer@example.com', 'subject' => '[Shop] Password Reset with a key', 'message' => '${ reset }' ) ) ),
);`, { path: '/shop/', label: 'logging a password reset with a key on the shop and capturing its email' } );
		const [ entry ] = s.sent.entries ?? [];
		const mail = () => {
			const file = path.join( site.outDir, 'mail.jsonl' );
			return existsSync( file ) ? readFileSync( file, 'utf8' ).split( '\n' ).filter( Boolean ).map( ( line ) => JSON.parse( line ) ) : [];
		};
		const copyAs = async ( jar ) => {
			const html = ( await site.get( `/shop/wp-admin/admin.php?page=safety_net_email_log&entry=${ entry.id }`, { jar } ) ).text;
			const form = [ ...html.matchAll( /<form\b[\s\S]*?<\/form>/g ) ].map( ( [ match ] ) => match ).find( ( candidate ) => candidate.includes( 'value="safety_net_email_log_send"' ) );
			assert.ok( form, 'The shop\'s entry has no Send to me form' );
			const fields = Object.fromEntries( [ ...form.matchAll( /<input type="hidden"[^>]*\sname="([^"]+)"[^>]*\svalue="([^"]*)"/g ) ].map( ( [ , name, value ] ) => [ name, value.replace( /&amp;|&#038;/g, '&' ) ] ) );
			const before = mail().length;
			const res = await site.post( '/shop/wp-admin/admin-post.php', fields, { jar, follow: false, expect: 302 } );
			const back = new URL( res.location, site.url );
			return {
				note: html.match( /<p class="description safety-net-email-log-copy-note">([^<]*)<\/p>/ )?.[ 1 ] ?? '',
				back: [ back.pathname, back.searchParams.get( 'result' ) ],
				copies: mail().slice( before ).map( ( { verdict, atts } ) => [ verdict, atts.to, atts.message ] ),
			};
		};
		try {
			assert.deepEqual( entry && [ entry.message, entry.secrets ], [ reset.replace( 'SHOPKEY1', '[redacted]' ), '["SHOPKEY1"]' ], 'The shop did not log the password reset with its key stored apart, so this proves nothing' );
			const [ ownerEmail, superAdmin, ...caps ] = s.owner;
			assert.deepEqual( [ superAdmin, caps ], [ false, [ true, false ] ], 'The shop\'s administrator is a super admin, cannot manage options or can edit users, so this proves nothing' );

			const ownerJar = await site.login( { user: 'shopowner', jar: new CookieJar(), redirectTo: '/shop/wp-admin/' } );
			const owner = await copyAs( ownerJar );
			assert.ok( owner.note.endsWith( ` ${ KEYS_FOR_SUPER_ADMINS }` ), `The shop's entry does not tell its administrator that the keys stay redacted: ${ owner.note }` );
			assert.deepEqual( [ owner.back, owner.copies ], [ [ '/shop/wp-admin/admin.php', 'sent' ], [ [ null, ownerEmail, entry.message ] ] ], 'The shop\'s administrator, who cannot edit users, did not get the redacted copy' );

			const delegated = await site.php( `
( new WP_User( ${ seed.users.shopowner } ) )->add_cap( 'manage_network_users' );
$owner   = new WP_User( ${ seed.users.shopowner } );
$restore = static function ( $id ) {
	wp_set_current_user( $id );
	return SafetyNet\\EmailLog\\can_restore_keys();
};
return array(
	'caps'    => array( is_super_admin( $owner->ID ), $owner->has_cap( 'manage_options' ), $owner->has_cap( 'edit_users' ) ),
	'restore' => array( $restore( $owner->ID ), $restore( 1 ) ),
	'super'   => is_super_admin( 1 ),
);`, { path: '/shop/', label: 'giving the shop\'s administrator manage_network_users and checking who gets the keys back' } );
			try {
				assert.deepEqual( [ delegated.caps, delegated.super ], [ [ false, true, true ], true ], 'The shop\'s administrator, given manage_network_users, cannot edit users or is a super admin, or the first user is no super admin, so this proves nothing' );
				assert.deepEqual( delegated.restore, [ false, true ], 'can_restore_keys() does not refuse a site administrator who can edit users, or does not allow a super admin' );
				const editor = await copyAs( ownerJar );
				assert.ok( editor.note.endsWith( ` ${ KEYS_FOR_SUPER_ADMINS }` ), `The shop's entry does not tell its administrator who can edit users that the keys stay redacted: ${ editor.note }` );
				assert.deepEqual( [ editor.back, editor.copies ], [ [ '/shop/wp-admin/admin.php', 'sent' ], [ [ null, ownerEmail, entry.message ] ] ], 'The shop\'s administrator who can edit users but is not a super admin got the keys back' );
			} finally {
				await site.php( `( new WP_User( ${ seed.users.shopowner } ) )->remove_cap( 'manage_network_users' ); return true;`, { path: '/shop/', label: 'taking manage_network_users back from the shop\'s administrator' } );
			}

			const superCopy = await copyAs( site.adminJar );
			assert.ok( superCopy.note.endsWith( ` ${ KEYS_PUT_BACK }` ), `The shop's entry does not tell a super admin that the keys are put back: ${ superCopy.note }` );
			assert.deepEqual( [ superCopy.back, superCopy.copies ], [ [ '/shop/wp-admin/admin.php', 'sent-with-keys' ], [ [ null, s.admin, reset ] ] ], 'A super admin did not get the copy with its key put back' );
		} finally {
			await site.php( "delete_option( 'sn_test_capture_mail' ); return true;", { path: '/shop/', label: 'no longer capturing the shop\'s email' } );
		}
	} );

	test( 'M14: deleting a site also drops its email log table', async () => {
		const s = await site.php( `
global $wpdb;
sn_test_send_emails( array( array( 'to' => 'someone@example.com', 'subject' => 'Sent on the main site', 'message' => 'x' ) ) );
$blog = wpmu_create_blog( DOMAIN_CURRENT_SITE, PATH_CURRENT_SITE . 'doomed/', 'Doomed', ${ seed.users.shopowner } );
if ( is_wp_error( $blog ) ) {
	throw new RuntimeException( $blog->get_error_message() );
}
switch_to_blog( $blog );
$sent = sn_test_send_emails( array( array( 'to' => 'someone@example.com', 'subject' => 'Sent on a site about to be deleted', 'message' => 'x' ) ) );
restore_current_blog();
$table   = $wpdb->get_blog_prefix( $blog ) . 'safety_net_email_log';
$created = sn_test_table_exists( $table );
$deleted = wp_delete_site( $blog );
return array( 'logged' => count( (array) $sent['entries'] ), 'created' => $created, 'deleted' => ! is_wp_error( $deleted ), 'left' => sn_test_table_exists( $table ), 'main' => sn_test_table_exists( $wpdb->prefix . 'safety_net_email_log' ) );`, { label: 'logging an email on a new site and deleting the site' } );
		assert.deepEqual( [ s.logged, s.created, s.deleted ], [ 1, true, true ], 'The new site did not log its email in its own table, or deleting it failed, so this proves nothing' );
		assert.equal( s.left, false, 'Deleting the site left its email log table behind' );
		assert.equal( s.main, true, 'Deleting a site dropped the main site\'s email log table' );
	} );

	test( 'M8: a site created after Safety Net was enabled runs on its first load and keeps its administrator', async () => {
		const created = await site.php( "$owner = sn_test_create_user( 'newowner', '' ); remove_user_from_blog( $owner, 1 ); $blog = wpmu_create_blog( DOMAIN_CURRENT_SITE, PATH_CURRENT_SITE . 'new/', 'New', $owner ); if ( is_wp_error( $blog ) ) { throw new RuntimeException( $blog->get_error_message() ); } return array( 'blog' => $blog, 'owner' => $owner );", { label: 'creating a site' } );
		await firstLoad( site, '/new/' );
		const s = await site.php( `return array( 'users' => sn_test_users(), 'state' => sn_test_network_site_state( ${ created.blog } ) );` );
		assertStepFlags( s.state.flags, { woocommerce: false } );
		assert.equal( s.users.newowner, created.owner );
	} );

	test( 'K11: the current network\'s admin email is scrubbed and other networks\' are left alone', async () => {
		assert.deepEqual( await site.php( NETWORK_EMAILS ), { current: 'safetynet@scrubbedthis.option', other: 'network2@example.com' } );
	} );

	test( 'K5/K11: a network the main site processed before network flags existed is not changed retroactively by a new site', async () => {
		const network = await site.php( `delete_site_option( 'safety_net_network_admin_email_scrubbed' ); delete_site_option( 'safety_net_network_plugins_deactivated' ); ${ SET_NETWORK }`, { label: 'simulating a network processed by an older version' } );
		assertNetworkUntouched( network, 'Simulating an older version' );
		assert.deepEqual( network.flags, {} );
		await site.php( `$blog = wpmu_create_blog( DOMAIN_CURRENT_SITE, PATH_CURRENT_SITE . 'legacy/', 'Legacy', ${ seed.users.shopowner } ); if ( is_wp_error( $blog ) ) { throw new RuntimeException( $blog->get_error_message() ); } return $blog;`, { label: 'creating a site' } );
		await firstLoad( site, '/legacy/' );
		const after = await site.php( 'return sn_test_network_state();' );
		assertNetworkUntouched( after, 'The new site\'s first run on an already processed network' );
		assert.deepEqual( after.flags, NETWORK_FLAGS, 'The network flags were not recorded for the already processed network' );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
