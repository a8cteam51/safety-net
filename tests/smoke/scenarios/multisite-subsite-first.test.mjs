import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertAiKeysScrubbed, assertStepFlags, firstLoad } from '../lib/checks.mjs';
import { bootSite } from '../lib/site.mjs';

const NETWORK_STATE = "return array( 'users' => sn_test_users(), 'main' => sn_test_network_site_state( 1 ), 'shop' => sn_test_network_site_state( 2 ), 'empty' => sn_test_network_site_state( 3 ) );";

describe( 'multisite-subsite-first: a subsite is the first site loaded after enabling Safety Net', () => {
	let site;
	let seed;
	let network;
	let mailpoet;

	before( async () => {
		site = await bootSite( { name: 'multisite-subsite-first', env: 'staging', mode: 'mu', multisite: true } );
		seed = await site.php( 'return sn_test_seed_network();', { label: 'seeding the network' } );
		const buddypress = await site.php( 'return sn_test_seed_network_buddypress();', { label: 'creating BuddyPress tables at the network prefix' } );
		assert.deepEqual( Object.entries( buddypress ).filter( ( [ , count ] ) => count !== 1 ), [], 'Seeding the BuddyPress tables failed' );
		network = await site.php( 'return sn_test_network_state();', { label: 'reading the network state' } );
		assert.ok( network.admin_email && network.admin_email !== 'safetynet@scrubbedthis.option', `The network admin email was not seeded: ${ network.admin_email }` );
		mailpoet = await site.php( `return sn_test_mailpoet_tables_on_sites( array( 'main' => 1, 'shop' => ${ seed.sites.shop } ), true );`, { label: 'creating MailPoet tables on the main site and /shop/' } );
		const seededTables = ( key ) => ( { subscribers: 1, statistics_opens: 1, forms: 1, mta: { method: 'MailPoet', mailpoet_api_key: `sn-key-${ key }` } } );
		assert.deepEqual( mailpoet, { main: seededTables( 'main' ), shop: seededTables( 'shop' ) }, 'Creating the MailPoet tables failed' );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'M2: a subsite run keeps the main site\'s administrators and leaves the main site alone', async () => {
		await firstLoad( site, '/shop/' );
		const s = await site.php( NETWORK_STATE, { path: '/shop/' } );
		assert.deepEqual( Object.keys( s.users ).sort(), [ 'admin', 'netadmin', 'shopowner', 'superhelper' ] );
		assertStepFlags( s.shop.flags, { woocommerce: false } );
		assert.deepEqual( s.shop.post_authors, [ seed.users.shopowner ] );
		assert.deepEqual( s.main.flags, [] );
		assert.equal( s.main.admin_email, seed.admin_email );
		assert.equal( s.main.klaviyo, 'pk_live_main' );
	} );

	test( 'M13: AI provider keys live in each site\'s options table, so the shop\'s first run deletes the shop\'s and no other site\'s', async () => {
		const s = await site.php( NETWORK_STATE, { path: '/shop/' } );
		assertAiKeysScrubbed( s.shop.ai, seed.ai.shop, 'The shop\'s first run' );
		assert.deepEqual( s.main.ai, seed.ai.main, 'The shop\'s first run changed the main site\'s AI keys or their backups' );
		assert.deepEqual( s.empty.ai, seed.ai.empty, 'The shop\'s first run changed /empty/\'s AI keys or their backups' );
	} );

	test( 'K5/K11: the first site\'s run, here a subsite\'s, scrubs the network admin email and records the network-wide changes', async () => {
		const now = await site.php( 'return sn_test_network_state();', { path: '/shop/' } );
		assert.equal( now.admin_email, 'safetynet@scrubbedthis.option' );
		assert.deepEqual( now.flags, { safety_net_network_admin_email_scrubbed: '1', safety_net_network_plugins_deactivated: '1' } );
		assert.deepEqual( now.plugins, network.plugins );
	} );

	test( 'K12: a subsite\'s first run empties the BuddyPress tables at the network prefix', async () => {
		const counts = await site.php( 'return sn_test_network_buddypress_counts();', { path: '/shop/' } );
		assert.deepEqual( Object.entries( counts ).filter( ( [ , count ] ) => count !== 0 ), [] );
	} );

	test( 'K13: MailPoet\'s tables are per site, so a subsite\'s run clears its own and leaves the main site\'s alone', async () => {
		const s = await site.php( `return sn_test_mailpoet_tables_on_sites( array( 'main' => 1, 'shop' => ${ seed.sites.shop } ) );`, { path: '/shop/' } );
		assert.deepEqual( s.shop, { subscribers: 0, statistics_opens: 0, forms: 1, mta: { method: 'MailPoet', mailpoet_api_key: '' } } );
		assert.deepEqual( s.main, mailpoet.main );
	} );

	test( 'M3: without the main admin among super admins, posts on an admin-less site go to a remaining super admin', async () => {
		await site.php( "update_site_option( 'site_admins', array( 'superhelper' ) ); return get_super_admins();", { path: '/shop/' } );
		await firstLoad( site, '/empty/' );
		const s = await site.php( NETWORK_STATE, { path: '/shop/' } );
		assert.deepEqual( s.empty.post_authors, [ seed.users.superhelper ] );
		assert.equal( s.users.admin, 1, 'The main site administrator was deleted' );
	} );

	test( 'M13: /empty/\'s own first run deletes its AI provider keys, and the main site, not loaded yet, keeps its own', async () => {
		const s = await site.php( NETWORK_STATE, { path: '/shop/' } );
		assertAiKeysScrubbed( s.empty.ai, seed.ai.empty, '/empty/\'s first run' );
		assert.deepEqual( s.main.flags, [], 'The main site ran, so it can no longer show its keys surviving until its own first load' );
		assert.deepEqual( s.main.ai, seed.ai.main, 'Another site\'s run changed the main site\'s AI keys or their backups' );
	} );

	test( 'K3: the post author is matched on the administrator role exactly, so a client_administrator with a lower ID does not get the posts', async () => {
		const created = await site.php(
			`$client = sn_test_create_user( 'clientadmin', '' );
$owner = sn_test_create_user( 'clientsowner', '' );
foreach ( array( $client, $owner ) as $user ) {
	remove_user_from_blog( $user, get_current_blog_id() );
}
$blog = wpmu_create_blog( DOMAIN_CURRENT_SITE, PATH_CURRENT_SITE . 'clients/', 'Clients', $owner );
if ( is_wp_error( $blog ) ) {
	throw new RuntimeException( $blog->get_error_message() );
}
switch_to_blog( $blog );
add_role( 'client_administrator', 'Client Administrator', array( 'read' => true, 'edit_posts' => true ) );
add_user_to_blog( $blog, $client, 'client_administrator' );
$post = wp_insert_post( array( 'post_title' => 'SN seed clients by the client administrator', 'post_status' => 'publish', 'post_author' => $client ) );
$roles = array( 'client' => get_userdata( $client )->roles, 'owner' => get_userdata( $owner )->roles );
restore_current_blog();
return array( 'blog' => $blog, 'client' => $client, 'owner' => $owner, 'post' => $post, 'roles' => $roles );`,
			{ path: '/shop/', label: 'creating a site with a client_administrator' }
		);
		assert.deepEqual( created.roles, { client: [ 'client_administrator' ], owner: [ 'administrator' ] } );
		assert.ok( created.client < created.owner, 'The client_administrator must have the lower ID' );
		assert.ok( created.post > 0, 'Creating the post failed' );
		await firstLoad( site, '/clients/' );
		const s = await site.php( `return sn_test_network_site_state( ${ created.blog } );`, { path: '/shop/' } );
		assertStepFlags( s.flags, { woocommerce: false } );
		assert.deepEqual( s.post_authors, [ created.owner ] );
	} );

	test( 'M4: both sites still load', async () => {
		await site.getAll( [ '/shop/', '/empty/' ] );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
