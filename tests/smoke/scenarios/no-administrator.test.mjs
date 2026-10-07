import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { firstLoad } from '../lib/checks.mjs';
import { bootSite } from '../lib/site.mjs';

describe( 'no-administrator: a site without any administrator keeps its users', () => {
	let site;
	let seed;

	before( async () => {
		site = await bootSite( { name: 'no-administrator', env: 'staging', mode: 'plugin' } );
		seed = await site.php(
			"$customer = sn_test_create_user( 'customer1', 'subscriber' ); $post = wp_insert_post( array( 'post_title' => 'SN seed by customer', 'post_status' => 'publish', 'post_author' => $customer ) ); ( new WP_User( 1 ) )->set_role( 'editor' ); return array( 'users' => sn_test_users(), 'post' => $post, 'customer' => $customer );",
			{ label: 'demoting the only administrator' }
		);
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'A28: the run completes without deleting anyone', async () => {
		const mark = site.logMark();
		await firstLoad( site );
		const s = await site.php( "return array( 'users' => sn_test_users(), 'flag' => get_option( 'safety_net_data_deleted' ), 'author' => (int) get_post_field( 'post_author', " + seed.post + " ) );" );
		assert.deepEqual( s.users, seed.users );
		assert.equal( s.flag, '1' );
		assert.equal( s.author, seed.customer );
		assert.ok( site.logEntriesSince( mark ).some( ( entry ) => entry.includes( 'Safety Net: no administrators found, so users were not deleted.' ) ) );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
