import assert from 'node:assert/strict';
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

	test( 'M12: the subsite discourages search engines and blocks email', async () => {
		const s = await site.php( "return array( 'blog' => get_current_blog_id(), 'filtered' => get_option( 'blog_public' ), 'raw' => sn_test_raw_option( 'blog_public' ) );", { path: '/shop/' } );
		assert.deepEqual( s, { blog: 2, filtered: 0, raw: '1' } );
		assertMailBlocked( await captureMail( site, { path: '/shop/' } ) );
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
