import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertStepFlags } from '../lib/checks.mjs';
import { bootSite } from '../lib/site.mjs';

describe( 'install-flow: WordPress installs with Safety Net already in mu-plugins (#187)', () => {
	let site;

	before( async () => {
		site = await bootSite( { name: 'install-flow', env: 'local', mode: 'mu', beforeInstall: true } );
	} );

	after( () => site?.stop() );

	test( 'I1: the install request loaded Safety Net, which stepped aside', () => {
		const install = site.probe().filter( ( line ) => line.at_load?.installing && line.at_load?.installed === false );
		assert.ok( install.length > 0, `No request ran during the core install, so this scenario proved nothing. Probe: ${ JSON.stringify( site.probe() ) }` );
		for ( const line of install ) {
			assert.equal( line.env, 'local', 'WP_ENVIRONMENT_TYPE was not defined during the install' );
			assert.equal( line.sn_path, null, 'Safety Net did not bail during the install' );
			assert.equal( line.fatal, null );
			assert.equal( line.code, 200 );
		}
	} );

	test( 'I1: the installed site works and Safety Net ran afterwards', async () => {
		await site.get( '/' );
		const ran = site.probe().filter( ( line ) => line.runs?.safety_net_scrub_options > 0 );
		assert.equal( ran.length, 1, `The automatic pass ran ${ ran.length } times: ${ JSON.stringify( ran ) }` );
		assert.equal( ran[ 0 ].at_load?.installing, false, 'The automatic pass ran during the install' );
		assert.equal( ran[ 0 ].fatal, null );
		const s = await site.php( "return array( 'installed' => is_blog_installed(), 'flags' => sn_test_flags(), 'admin' => user_can( 1, 'administrator' ), 'users' => sn_test_users() );" );
		assert.equal( s.installed, true );
		assertStepFlags( s.flags, { woocommerce: false } );
		assert.equal( s.admin, true );
		assert.deepEqual( s.users, { admin: 1 } );
	} );

	test( 'I2: the database upgrade screen loads', async () => {
		await site.login();
		await site.get( '/wp-admin/upgrade.php', { jar: site.adminJar } );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
