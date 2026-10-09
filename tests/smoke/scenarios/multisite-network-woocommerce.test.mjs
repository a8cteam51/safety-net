import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertDataDeleted, assertStepFlags, firstLoad } from '../lib/checks.mjs';
import { seedWooCommerceSite } from '../lib/fixtures.mjs';
import { bootSite, MULTISITE_URL } from '../lib/site.mjs';

const ORDERS = "global $wpdb; $counts = array(); foreach ( array( 1, 2 ) as $blog ) { $counts[ $blog ] = sn_test_count( $wpdb->get_blog_prefix( $blog ) . 'wc_orders', \"type = 'shop_order'\" ); } return $counts;";
const SITE_FLAGS = "return array( 1 => sn_test_network_site_state( 1 )['flags'], 2 => sn_test_network_site_state( 2 )['flags'], 3 => sn_test_network_site_state( 3 )['flags'] );";
const PMPRO_TABLES = [ 'pmpro_membership_orders', 'pmpro_membership_ordermeta', 'pmpro_subscriptions', 'pmpro_subscriptionmeta', 'pmpro_memberships_users', 'pmpro_discount_codes_uses' ];
const PMPRO_META = "sn_test_count( $GLOBALS['wpdb']->usermeta, \"user_id = 1 AND meta_key IN ( 'pmpro_stripe_customerid', 'pmpro_bfirstname' )\" )";
const SIGNUPS = "sn_test_count( $GLOBALS['wpdb']->signups, \"user_login = 'pending'\" )";
// A denylisted plugin and a plugin with an online gateway, then an offline gateway and an unrelated plugin that must stay.
const NETWORK_DENIED = [ 'mailchimp-for-wp/mailchimp-for-wp.php', 'zz-checkout/zz-checkout.php' ];
const NETWORK_KEPT = [ 'zz-offline-cod/zz-offline-cod.php', 'barcode-label-printer/barcode-label-printer.php' ];
const SCRUBBED_EMAIL = 'safetynet@scrubbedthis.option';
const OWNER_EMAIL = 'netowner@example.com';
const NETWORK_FLAGS = { safety_net_network_admin_email_scrubbed: '1', safety_net_network_gateway_plugins_deactivated: '1', safety_net_network_plugins_deactivated: '1' };
const plain = ( text ) => text.replace( /\x1B\[[0-9;]*m/g, '' );

function assertNetworkChanged( network, label ) {
	assert.equal( network.admin_email, SCRUBBED_EMAIL, `${ label } did not scrub the network admin email` );
	assert.deepEqual( NETWORK_DENIED.filter( ( plugin ) => network.plugins.includes( plugin ) ), [], `${ label } left denylisted or gateway plugins network-active` );
	assert.deepEqual( [ 'safety-net/safety-net.php', 'woocommerce/woocommerce.php', ...NETWORK_KEPT ].filter( ( plugin ) => ! network.plugins.includes( plugin ) ), [], `${ label } deactivated plugins it should have kept` );
	assert.deepEqual( network.flags, NETWORK_FLAGS, `${ label } did not record the network-wide changes` );
}

function assertNetworkUntouched( network, label ) {
	assert.equal( network.admin_email, OWNER_EMAIL, `${ label } changed the network admin email` );
	assert.deepEqual( NETWORK_DENIED.filter( ( plugin ) => ! network.plugins.includes( plugin ) ), [], `${ label } deactivated network-active plugins` );
}

describe( 'multisite-network-woocommerce: Safety Net and WooCommerce network-activated', () => {
	let site;
	let seed;

	before( async () => {
		site = await bootSite( { name: 'multisite-network-woocommerce', env: 'staging', mode: 'network', multisite: true, woocommerce: 'network', wpCli: true } );
		seed = await site.php( `$seed = sn_test_seed_network(); sn_test_install_fixture_plugins(); update_site_option( 'active_sitewide_plugins', array_merge( (array) get_site_option( 'active_sitewide_plugins', array() ), array_fill_keys( array( '${ [ ...NETWORK_DENIED, ...NETWORK_KEPT ].join( "', '" ) }' ), time() ) ) ); return $seed;`, { label: 'seeding the network' } );
		const network = Object.keys( await site.php( "return get_site_option( 'active_sitewide_plugins' );", { label: 'reading the network-active plugins' } ) );
		assert.deepEqual( [ ...NETWORK_DENIED, ...NETWORK_KEPT ].filter( ( plugin ) => ! network.includes( plugin ) ), [], 'Network-activating the fixture plugins failed' );
		for ( const path of [ '/', '/shop/' ] ) {
			await seedWooCommerceSite( site, { hpos: true, path, customer: seed.users.customer2 } );
		}
		const pmproMeta = await site.php( `global $wpdb; foreach ( array( '${ PMPRO_TABLES.join( "', '" ) }' ) as $table ) { $wpdb->query( "CREATE TABLE {$wpdb->get_blog_prefix( 2 )}$table ( id INTEGER PRIMARY KEY )" ); $wpdb->insert( $wpdb->get_blog_prefix( 2 ) . $table, array( 'id' => 1 ) ); } update_user_meta( 1, 'pmpro_stripe_customerid', 'cus_x' ); update_user_meta( 1, 'pmpro_bfirstname', 'Admin' ); return ${ PMPRO_META };`, { label: 'creating PMPro tables on the shop site' } );
		assert.equal( pmproMeta, 2, 'Seeding PMPro user meta failed' );
		const signups = await site.php( `$GLOBALS['wpdb']->insert( $GLOBALS['wpdb']->signups, array( 'domain' => '', 'path' => '', 'title' => '', 'user_login' => 'pending', 'user_email' => 'pending@example.com', 'registered' => current_time( 'mysql', true ), 'activation_key' => 'sn-test-pending', 'meta' => '' ) ); return ${ SIGNUPS };`, { label: 'adding a pending signup to the network' } );
		assert.equal( signups, 1, 'Seeding a pending signup failed' );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'M5/M7: the main site runs first and leaves the shop\'s orders until the shop loads', async () => {
		await firstLoad( site, '/' );
		const flags = await site.php( SITE_FLAGS );
		assertStepFlags( flags[ 1 ], { woocommerce: true } );
		assert.deepEqual( flags[ 2 ], [] );
		assert.deepEqual( await site.php( ORDERS ), { 1: 0, 2: 2 } );
	} );

	test( 'K6: the main site has no PMPro tables, so its run leaves PMPro user meta in the network\'s usermeta table', async () => {
		assert.equal( await site.php( `return ${ PMPRO_META };` ), 2, 'The main site\'s run removed PMPro user meta' );
	} );

	test( 'K5/K11: the first site\'s run, gateway pass included, applies the network-wide changes once, and then a super admin reverts them', async () => {
		assertNetworkChanged( await site.php( 'return sn_test_network_state();' ), 'The main site\'s first run' );
		assertNetworkUntouched( await site.php( `return sn_test_set_network( array( '${ NETWORK_DENIED.join( "', '" ) }' ), '${ OWNER_EMAIL }' );`, { label: 'reverting the network-wide changes' } ), 'Reverting the network-wide changes' );
		const jar = await site.login();
		const [ network, dashboard ] = await site.getAll( [ '/wp-admin/network/', '/wp-admin/' ].map( ( path ) => ( { path, jar } ) ) );
		for ( const [ label, res ] of [ [ 'network admin', network ], [ 'site dashboard', dashboard ] ] ) {
			assert.match( res.text, /safety-net-active-denylisted"><p><strong>Safety Net:<\/strong> these plugins are deactivated by Safety Net but active again: MC4WP stub \(fixture\), ZZ Checkout \(fixture\)\./, `The ${ label } does not name the network-activated denylisted and gateway plugins` );
		}
	} );

	test( 'M5/M7: each site runs on its own first load', async () => {
		await firstLoad( site, '/shop/' );
		await firstLoad( site, '/empty/' );
		const flags = await site.php( SITE_FLAGS );
		assertStepFlags( flags[ 2 ], { woocommerce: true } );
		assertStepFlags( flags[ 3 ], { woocommerce: true } );
		assert.deepEqual( await site.php( ORDERS ), { 1: 0, 2: 0 } );
		assert.deepEqual( Object.keys( await site.php( 'return sn_test_users();' ) ).sort(), [ 'admin', 'netadmin', 'shopowner', 'superhelper' ] );
	} );

	test( 'K5/K11: the other sites\' first runs, gateway passes included, leave the super admin\'s network-wide changes alone', async () => {
		const network = await site.php( 'return sn_test_network_state();' );
		assertNetworkUntouched( network, 'The first runs of /shop/ and /empty/' );
		assert.deepEqual( network.flags, NETWORK_FLAGS );
	} );

	test( 'K5/K11: wp safety-net scrub-options and deactivate-plugins on a subsite apply the network-wide changes and record them', async () => {
		const reverted = await site.php( `return sn_test_set_network( array( '${ NETWORK_DENIED.join( "', '" ) }' ), '${ OWNER_EMAIL }' );`, { label: 'reverting the network-wide changes' } );
		assertNetworkUntouched( reverted, 'Reverting the network-wide changes' );
		// The flags stay set: they only stop automatic runs, so explicit WP-CLI commands must still apply the changes.
		assert.deepEqual( reverted.flags, NETWORK_FLAGS );
		for ( const [ command, message ] of [ [ 'scrub-options', /Success: All options have been scrubbed\./ ], [ 'deactivate-plugins', /Success: Problematic plugins have been deactivated\./ ] ] ) {
			const res = await site.wp( [ 'safety-net', command ], { url: `${ MULTISITE_URL }/shop/` } );
			assert.equal( res.exitCode, 0, `wp safety-net ${ command } exited with ${ res.exitCode }: ${ plain( res.stderr || res.stdout ).slice( 0, 500 ) }` );
			assert.match( plain( res.stdout ), message );
		}
		assertNetworkChanged( await site.php( 'return sn_test_network_state();' ), 'The WP-CLI commands' );
	} );

	test( 'M13: wp safety-net scrub-options on a subsite deletes that site\'s AI provider key and not the main site\'s', async () => {
		await site.php( "foreach ( array( 1, 2 ) as $blog ) { switch_to_blog( $blog ); update_option( 'connectors_ai_openai_api_key', \"sk-test-cli-$blog\" ); restore_current_blog(); } return true;", { label: 're-seeding an AI key on the main site and the shop' } );
		const res = await site.wp( [ 'safety-net', 'scrub-options' ], { url: `${ MULTISITE_URL }/shop/` } );
		assert.equal( res.exitCode, 0, `wp safety-net scrub-options exited with ${ res.exitCode }: ${ plain( res.stderr || res.stdout ).slice( 0, 500 ) }` );
		const s = await site.php( "return array( 'main' => sn_test_network_site_state( 1 )['ai'], 'shop' => sn_test_network_site_state( 2 )['ai'] );" );
		assert.equal( s.shop.keys.connectors_ai_openai_api_key, null, 'The shop\'s AI provider key was not deleted' );
		assert.deepEqual( s.shop.backups, [], 'The shop has backups of AI provider keys' );
		assert.equal( s.main.keys.connectors_ai_openai_api_key, 'sk-test-cli-1', 'Scrubbing the shop deleted the main site\'s AI provider key' );
	} );

	test( 'M5: the PMPro tables on the shop site are emptied', async () => {
		const counts = await site.php( `global $wpdb; $counts = array(); foreach ( array( '${ PMPRO_TABLES.join( "', '" ) }' ) as $table ) { $counts[ $table ] = sn_test_count( $wpdb->get_blog_prefix( 2 ) . $table ); } return $counts;` );
		assert.deepEqual( Object.values( counts ), PMPRO_TABLES.map( () => 0 ) );
	} );

	test( 'M4: the stores and network admin load', async () => {
		await site.getAll( [ '/', '/shop/', '/shop/wp-json/safety-net/v1/status' ] );
		const jar = await site.login();
		await site.getAll( [ '/wp-admin/network/plugins.php', '/shop/wp-admin/admin.php?page=wc-settings&tab=checkout', '/shop/wp-admin/tools.php?page=safety_net_options' ].map( ( path ) => ( { path, jar } ) ) );
	} );

	test( 'K5: network-activated denylisted and gateway plugins are deactivated network-wide and the rest stay network-active', async () => {
		const network = Object.keys( await site.php( "return get_site_option( 'active_sitewide_plugins' );" ) );
		for ( const plugin of NETWORK_DENIED ) {
			assert.ok( ! network.includes( plugin ), `${ plugin } is still network-active` );
		}
		for ( const plugin of [ 'safety-net/safety-net.php', 'woocommerce/woocommerce.php', ...NETWORK_KEPT ] ) {
			assert.ok( network.includes( plugin ), `${ plugin } is no longer network-active` );
		}
	} );

	test( 'K6: deleting PMPro data on a subsite clears PMPro user meta from the network\'s usermeta table', async () => {
		await assertDataDeleted( site, { blog: 2 } );
		assert.deepEqual( site.logEntriesMatching( /WordPress database error[\s\S]*wp_\d+_usermeta/ ), [] );
		// Only the shop site has PMPro tables, so only its run can have removed this meta.
		assert.equal( await site.php( `return ${ PMPRO_META };` ), 0, 'PMPro user meta is left in the usermeta table' );
	} );

	test( 'K5/K11: a network the main site processed before network flags existed keeps its network-active gateway plugins when a new site loads', async () => {
		const network = await site.php( `foreach ( array( 'safety_net_network_admin_email_scrubbed', 'safety_net_network_plugins_deactivated', 'safety_net_network_gateway_plugins_deactivated' ) as $flag ) { delete_site_option( $flag ); } delete_blog_option( 1, 'safety_net_gateway_plugins_deactivated' ); delete_blog_option( 1, 'safety_net_gateway_plugins_pending' ); return sn_test_set_network( array( '${ NETWORK_DENIED.join( "', '" ) }' ), '${ OWNER_EMAIL }' );`, { label: 'simulating a network processed by an older version' } );
		assertNetworkUntouched( network, 'Simulating an older version' );
		assert.deepEqual( network.flags, {} );
		await site.php( "$blog = wpmu_create_blog( DOMAIN_CURRENT_SITE, PATH_CURRENT_SITE . 'legacy/', 'Legacy', 1 ); if ( is_wp_error( $blog ) ) { throw new RuntimeException( $blog->get_error_message() ); } return $blog;", { label: 'creating a site' } );
		await firstLoad( site, '/legacy/' );
		const after = await site.php( 'return sn_test_network_state();' );
		assertNetworkUntouched( after, 'The new site\'s first run, gateway pass included, on an already processed network' );
		assert.deepEqual( after.flags, NETWORK_FLAGS, 'The network flags were not recorded for the already processed network' );
	} );

	test( 'K12: without BuddyPress tables, no site\'s run empties the network\'s signups table', async () => {
		assert.equal( await site.php( `return ${ SIGNUPS };` ), 1, 'A run without BuddyPress tables deleted a pending signup' );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
