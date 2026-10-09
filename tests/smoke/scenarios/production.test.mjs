import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertNoFlags, FILTER_PROBE, GITHUB_RELEASE_URL, githubRelease, releaseHeader, releaseHeaderUrl, robotsLines } from '../lib/checks.mjs';
import { CookieJar } from '../lib/http.mjs';
import { bootSite } from '../lib/site.mjs';

const UPDATE_CHECK = "delete_site_transient( 'update_plugins' ); wp_update_plugins(); $t = get_site_transient( 'update_plugins' ); return array( 'offer' => isset( $t->response['safety-net/safety-net.php'] ) ? (array) $t->response['safety-net/safety-net.php'] : null, 'no_update' => isset( $t->no_update['safety-net/safety-net.php'] ), 'cached' => get_transient( 'safety_net_github_latest_release' ) );";

describe( 'production: regular plugin on a production site stays dormant', () => {
	let site;
	let baseline;

	before( async () => {
		site = await bootSite( { name: 'production', env: 'production', mode: 'plugin', wpCli: true } );
		const seed = await site.php( 'return sn_test_seed_base();', { label: 'seeding the site' } );
		await site.php( `wp_set_password( 'password', ${ seed.users.editor1 } ); return true;`, { label: 'giving the editor a known password' } );
		await site.php( "update_option( 'sn_test_constants', array( 'SAFETY_NET_DELETE_DATA' => false ) ); return true;", { label: 'defining SAFETY_NET_DELETE_DATA' } );
		baseline = await site.php( 'return sn_test_snapshot();', { label: 'baseline snapshot' } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'P1: front end, dashboard and plugins screen load', async () => {
		const home = await site.get( '/' );
		assert.doesNotMatch( home.text, /<meta name=['"]robots['"][^>]*noindex/ );
		const jar = await site.login();
		await site.getAll( [ '/wp-admin/', '/wp-admin/plugins.php' ].map( ( path ) => ( { path, jar } ) ) );
	} );

	test( 'P1: no seeded data changed and no flags or backups were written', async () => {
		const now = await site.php( 'return sn_test_snapshot();' );
		assertNoFlags( now.flags );
		assert.deepEqual( now.backups, [] );
		assert.deepEqual( now.options, { ...baseline.options, active_plugins: [ ...baseline.options.active_plugins, 'safety-net/safety-net.php' ].sort() } );
		assert.deepEqual( now.users, baseline.users );
		assert.deepEqual( now.usermeta_uids, baseline.usermeta_uids );
		assert.deepEqual( now.post_authors, baseline.post_authors );
		assert.equal( now.pingme, baseline.pingme );
	} );

	test( 'P1: only the self-updater is loaded', async () => {
		const s = await site.php( "return array( 'path' => defined( 'SAFETY_NET_PATH' ), 'loaded' => did_action( 'safety_net_loaded' ), 'mail' => has_filter( 'pre_wp_mail', 'SafetyNet\\\\Admin\\\\stop_emails' ), 'admin' => function_exists( 'SafetyNet\\\\Admin\\\\stop_emails' ), 'updater' => has_filter( 'update_plugins_github.com', 'SafetyNet\\\\SelfUpdate\\\\check_for_update' ), 'blog_public' => get_option( 'blog_public' ) );" );
		assert.deepEqual( s, { path: true, loaded: 0, mail: false, admin: false, updater: 10, blog_public: '1' } );
	} );

	test( 'P1: robots.txt keeps the WordPress defaults', async () => {
		const lines = robotsLines( ( await site.get( '/robots.txt' ) ).text );
		assert.ok( lines.includes( 'Disallow: /wp-admin/' ), lines.join( '\n' ) );
		assert.ok( ! lines.includes( 'Disallow: /' ), lines.join( '\n' ) );
	} );

	test( 'P1: the REST route, Tools page and AJAX tools do not exist', async () => {
		const [ rest, tools ] = await site.getAll( [ { path: '/wp-json/safety-net/v1/status', expect: 404 }, { path: '/wp-admin/tools.php?page=safety_net_options', jar: site.adminJar, expect: 403 } ] );
		assert.equal( JSON.parse( rest.text ).code, 'rest_no_route' );
		assert.match( tools.text, /Sorry, you are not allowed to access this page\./ );
		const ajax = await site.post( '/wp-admin/admin-ajax.php', { action: 'safety_net_scrub_options', nonce: 'x' }, { jar: site.adminJar, expect: 400 } );
		assert.equal( ajax.text, '0' );
	} );

	test( 'P2: admins see the production notice, unless it is filtered away', async () => {
		const shown = await site.get( '/wp-admin/', { jar: site.adminJar } );
		assert.match( shown.text, /Safety Net is active on a production site[^<]*remove the plugin or switch the site/ );
		assert.match( shown.text, /non-production copies that inherit it keep users, orders and subscriptions/ );
		assert.doesNotMatch( shown.text, /Safety Net Activated/ );
		assert.doesNotMatch( shown.text, /safety-net-active-denylisted/, 'Production warns about denylisted plugins, which it keeps active on purpose' );
		assert.doesNotMatch( shown.text, /safety-net-keep-data/, 'Production shows the notice about kept data, which only non-production copies keep' );
		await site.php( "update_option( 'sn_test_hide_production_notice', 1 ); return true;" );
		try {
			const hidden = await site.get( '/wp-admin/', { jar: site.adminJar } );
			assert.doesNotMatch( hidden.text, /Safety Net is active on a production site/ );
		} finally {
			await site.php( "delete_option( 'sn_test_hide_production_notice' ); return true;" );
		}
		await site.php( "update_option( 'sn_test_constants', array( 'SAFETY_NET_DELETE_DATA' => 'false' ) ); return true;", { label: 'defining SAFETY_NET_DELETE_DATA as a string' } );
		try {
			const invalid = await site.get( '/wp-admin/', { jar: site.adminJar } );
			assert.match( invalid.text, /Safety Net is active on a production site/ );
			assert.doesNotMatch( invalid.text, /non-production copies that inherit it keep/, 'The production notice says copies keep data although SAFETY_NET_DELETE_DATA is the string \'false\'' );
		} finally {
			await site.php( "update_option( 'sn_test_constants', array( 'SAFETY_NET_DELETE_DATA' => false ) ); return true;", { label: 'defining SAFETY_NET_DELETE_DATA again' } );
		}
	} );

	test( 'P2: users who cannot manage options do not see the production notice', async () => {
		const editor = await site.login( { user: 'editor1', jar: new CookieJar() } );
		const dashboard = await site.get( '/wp-admin/', { jar: editor } );
		assert.match( dashboard.text, /id="wpadminbar"/, 'The editor did not get the dashboard' );
		assert.doesNotMatch( dashboard.text, /Safety Net is active on a production site/ );
		assert.match( ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text, /Safety Net is active on a production site/, 'The administrator lost the notice' );
	} );

	test( 'P6: the Jetpack and PMPro filters are not added on production', async () => {
		const s = await site.php( FILTER_PROBE );
		assert.deepEqual( s.jetpack, [] );
		assert.deepEqual( s.pmpro, s.pmpro_input );
	} );

	test( 'U1: a newer GitHub release is offered on the plugins screen', async () => {
		site.setHttpMocks( { [ GITHUB_RELEASE_URL ]: githubRelease( 'v99.0.0' ), [ releaseHeaderUrl( 'v99.0.0' ) ]: releaseHeader( '8.1' ) } );
		await site.php( "delete_transient( 'safety_net_github_latest_release' ); delete_site_transient( 'update_plugins' ); return true;" );
		const page = await site.get( '/wp-admin/plugins.php', { jar: site.adminJar } );
		assert.match( page.text, /There is a new version of Safety Net available/ );
		const offer = await site.php( "$t = get_site_transient( 'update_plugins' ); return (array) $t->response['safety-net/safety-net.php'];" );
		assert.equal( offer.new_version, '99.0.0' );
		assert.equal( offer.slug, 'safety-net' );
		assert.equal( offer.package, 'https://github.com/a8cteam51/safety-net/releases/download/v99.0.0/safety-net.zip' );
		assert.equal( offer.url, 'https://github.com/a8cteam51/safety-net/releases/tag/v99.0.0' );
		assert.equal( offer.id, 'https://github.com/a8cteam51/safety-net' );
		assert.equal( offer.requires_php, '8.1', 'The offer must carry the release\'s Requires PHP, which core does not read from the package' );
	} );

	test( 'U9: a release that needs a newer PHP is offered as incompatible and never auto-updated', async () => {
		site.setHttpMocks( { [ GITHUB_RELEASE_URL ]: githubRelease( 'v99.0.0' ), [ releaseHeaderUrl( 'v99.0.0' ) ]: releaseHeader( '99.0' ) } );
		const s = await site.php( `delete_transient( 'safety_net_github_latest_release' ); ${ UPDATE_CHECK }` );
		assert.equal( s.offer?.requires_php, '99.0' );
		assert.equal( await site.php( "return is_php_version_compatible( '99.0' );" ), false );
		const page = await site.get( '/wp-admin/plugins.php', { jar: site.adminJar } );
		assert.match( page.text, /available, but it does not work with your version of PHP/ );
		// The control item proves auto-updates would otherwise run here, so only the PHP requirement can block it.
		const auto = await site.php( "require_once ABSPATH . 'wp-admin/includes/admin.php'; require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; add_filter( 'auto_update_plugin', '__return_true' ); $item = (object) get_site_transient( 'update_plugins' )->response['safety-net/safety-net.php']; $control = clone $item; $control->requires_php = ''; $updater = new WP_Automatic_Updater(); return array( 'incompatible' => $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR ), 'control' => $updater->should_update( 'plugin', $control, WP_PLUGIN_DIR ) );" );
		assert.deepEqual( auto, { incompatible: false, control: true }, 'The automatic updater must skip only the release that needs a newer PHP' );
	} );

	test( 'U10: when the release header cannot be read, the offer falls back to the installed Requires PHP', async () => {
		site.setHttpMocks( { [ GITHUB_RELEASE_URL ]: githubRelease( 'v99.0.0' ), [ releaseHeaderUrl( 'v99.0.0' ) ]: { code: 404, body: '404: Not Found' } } );
		const s = await site.php( `delete_transient( 'safety_net_github_latest_release' ); ${ UPDATE_CHECK }` );
		assert.equal( s.offer?.new_version, '99.0.0' );
		assert.equal( s.offer?.requires_php, '8.1' );
	} );

	test( 'U2: the current release is listed as up to date', async () => {
		const version = await site.php( "require_once ABSPATH . 'wp-admin/includes/plugin.php'; return get_plugin_data( WP_PLUGIN_DIR . '/safety-net/safety-net.php', false, false )['Version'];" );
		site.setHttpMocks( { [ GITHUB_RELEASE_URL ]: githubRelease( `v${ version }` ) } );
		const s = await site.php( `delete_transient( 'safety_net_github_latest_release' ); ${ UPDATE_CHECK }` );
		assert.equal( s.offer, null );
		assert.equal( s.no_update, true );
	} );

	test( 'U3: a release without the zip, or a GitHub error, offers nothing', async () => {
		for ( const mock of [ githubRelease( 'v99.0.0', { asset: false } ), { code: 404, body: { message: 'Not Found' } }, { code: 500, body: '' } ] ) {
			site.setHttpMocks( { [ GITHUB_RELEASE_URL ]: mock } );
			const s = await site.php( `delete_transient( 'safety_net_github_latest_release' ); ${ UPDATE_CHECK }` );
			assert.equal( s.offer, null, JSON.stringify( mock ) );
			assert.deepEqual( s.cached, [], JSON.stringify( mock ) );
		}
	} );

	test( 'U5: the cached release is used until force-check', async () => {
		site.setHttpMocks( { [ GITHUB_RELEASE_URL ]: githubRelease( 'v99.0.0' ) } );
		const cache = "set_transient( 'safety_net_github_latest_release', array( 'version' => '5.0.0', 'url' => 'https://example.com/r', 'package' => 'https://example.com/r.zip' ), HOUR_IN_SECONDS );";
		const cached = await site.php( `${ cache } ${ UPDATE_CHECK }` );
		assert.equal( cached.offer?.new_version, '5.0.0' );
		const forced = await site.php( `${ cache } $_GET['force-check'] = '1'; ${ UPDATE_CHECK }` );
		assert.equal( forced.offer?.new_version, '99.0.0' );
		site.setHttpMocks( {} );
	} );

	test( 'P5: the WP-CLI commands are not registered', async () => {
		const res = await site.wp( [ 'safety-net', 'scrub-options' ] );
		assert.notEqual( res.exitCode, 0 );
		assert.match( res.stderr, /'safety-net' is not a registered wp command/ );
		const now = await site.php( 'return sn_test_snapshot();' );
		assertNoFlags( now.flags );
		assert.equal( now.options.klaviyo_api_key, 'pk_live_123' );
	} );

	test( 'debug.log has no fatal errors and no Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
