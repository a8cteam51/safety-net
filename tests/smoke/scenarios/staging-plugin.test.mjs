import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { AJAX_ACTIONS, assertAiKeysScrubbed, assertAjaxSentinelsUntouched, assertDataDeleted, assertMailBlocked, assertNoIndex, assertStepFlags, assertToolsAssets, BAD_NONCE, captureMail, FILTER_PROBE, firstLoad, getToolsPage, GITHUB_RELEASE_URL, githubRelease, httpProbeSince, noncesForSession, NO_PERMISSION, postAjax, runAjaxTools, saveToolsForm, seedAjaxSentinels, TOOL_BUTTONS } from '../lib/checks.mjs';
import { CookieJar } from '../lib/http.mjs';
import { bootSite, phpAtLeast, wpAtLeast } from '../lib/site.mjs';

describe( 'staging-plugin: regular plugin on a staging site without WooCommerce', () => {
	let site;
	let seed;
	let thirdParty;
	let ai;

	before( async () => {
		site = await bootSite( { name: 'staging-plugin', env: 'staging', mode: 'plugin' } );
		seed = await site.php( 'return sn_test_seed_base();', { label: 'seeding the site' } );
		thirdParty = await site.php( 'return sn_test_seed_third_party();', { label: 'seeding third-party plugin tables' } );
		ai = await site.php( "return array( 'plugins' => sn_test_seed_ai_provider_plugins(), 'backups' => sn_test_seed_ai_backups(), 'rest' => sn_test_write_ai_keys_through_rest(), 'core' => sn_test_ai_core_view() );", { label: 'seeding AI provider plugins, stale AI backups and AI keys through the settings endpoint' } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'S1: the first page load runs the automatic pass and returns 200', async () => {
		await firstLoad( site );
	} );

	test( 'A1: every step flag is set and the gateway pass waits for WooCommerce', async () => {
		assertStepFlags( await site.php( 'return sn_test_flags();' ), { woocommerce: false } );
	} );

	test( 'A2-A13: denylisted options are scrubbed, backed up, or partially cleared', async () => {
		const s = await site.php(
			`return array(
				'snapshot' => sn_test_snapshot(),
				'backups'  => array(
					'admin_email'     => sn_test_raw_option( 'admin_email_sn_backup' ),
					'klaviyo'         => sn_test_raw_option( 'klaviyo_api_key_sn_backup' ),
					'mc4wp'           => sn_test_raw_option( 'mc4wp_sn_backup' ),
					'stripe'          => sn_test_raw_option( 'woocommerce_stripe_settings_sn_backup' ),
					'pingback'        => sn_test_raw_option( 'default_pingback_flag_sn_backup' ),
					'klaviyo_settings' => sn_test_raw_option( 'klaviyo_settings_sn_backup' ),
					'pmpro_secret'    => sn_test_raw_option( 'pmpro_stripe_secretkey_sn_backup' ),
				),
				'klaviyo_settings' => sn_test_raw_option( 'klaviyo_settings' ),
				'pmpro_secret'     => sn_test_raw_option( 'pmpro_stripe_secretkey' ),
			);`
		);
		const o = s.snapshot.options;
		assert.equal( o.admin_email, 'safetynet@scrubbedthis.option', 'A2 admin_email' );
		assert.equal( s.backups.admin_email, null, 'A2 admin_email is never backed up' );
		assert.equal( o.klaviyo_api_key, '', 'A3' );
		assert.equal( s.backups.klaviyo, 'pk_live_123', 'A3 backup' );
		assert.deepEqual( o.mc4wp, [], 'A4' );
		assert.deepEqual( s.backups.mc4wp, { api_key: 'k' }, 'A4 backup' );
		assert.deepEqual( o.woocommerce_stripe_settings, { enabled: '', publishable_key: '', secret_key: '', webhook_secret: '', title: 'Card', testmode: 'no' }, 'A5' );
		assert.equal( s.backups.stripe.secret_key, 'sk_x', 'A5 backup' );
		assert.deepEqual( Object.values( o.jetpack_active_modules ), [ 'stats', 'sso' ], 'A6' );
		assert.deepEqual( o[ 'woocommerce-ppcp-settings' ], { client_id: '', client_secret: '', merchant_id: '', merchant_email: '', sandbox_on: '1' }, 'A7' );
		assert.equal( o.pmpro_gateway, '', 'A8' );
		assert.equal( o.pmpro_gateway_environment, 'sandbox', 'A8' );
		assert.equal( o.pmpro_last_known_url, 'https://safetynetscrubbedthis.com', 'A8' );
		assert.equal( s.pmpro_secret, '', 'A8 an option an integration declares is blanked' );
		assert.equal( s.backups.pmpro_secret, 'sk_live_pmpro', 'A8 backup' );
		assert.equal( o.default_pingback_flag, '', 'A9' );
		assert.equal( s.backups.pingback, '1', 'A9 backup' );
		assert.equal( s.snapshot.pingme, 0, 'A9 _pingme meta' );
		assert.equal( s.klaviyo_settings, null, 'A10 an unset option stays unset' );
		assert.equal( s.backups.klaviyo_settings, null, 'A10 no backup for an unset option' );
		assert.deepEqual( o.jetpack_secrets, [], 'A11 jetpack_secrets is cleared off Atomic' );
		assert.equal( o.sn_custom_secret, '', 'A12 safety_net_options_to_clear filter' );
		assert.deepEqual( o.wprus, { encryption: { aes_key: '', hmac_key: '', other: 'keep' }, keep: 'yes' }, 'A13 wprus' );
		assert.deepEqual( o._wp_convertkit_settings, { api_key: '', api_secret: '', access_token: '', refresh_token: '', token_expires: '', other: 'keep' }, 'A13 convertkit' );
		assert.deepEqual( o.apple_news_settings, { api_key: '', api_secret: '', api_channel: '', apple_news_admin_email: '', api_autosync: 'no', apple_news_enable_debugging: 'no', other: 'keep', api_autosync_update: 'no', api_autosync_trash: 'no', api_autosync_delete: 'no', api_autosync_unpublish: 'no' }, 'A13 apple news' );
	} );

	test( 'A14/A16: denylisted plugins are deactivated and the rest stay active', async () => {
		const active = await site.php( "return get_option( 'active_plugins' );" );
		for ( const plugin of [ 'mailchimp-for-wp/mailchimp-for-wp.php', 'wp-mail-smtp/wp_mail_smtp.php', 'my-stripe-addon/my-stripe-addon.php', 'zz-extra-denied/zz-extra-denied.php' ] ) {
			assert.ok( ! active.includes( plugin ), `${ plugin } is still active` );
		}
		// Without WooCommerce no gateway is registered, so gateway plugins stay until the pass can run.
		for ( const plugin of [ 'barcode-label-printer/barcode-label-printer.php', 'zz-single-file.php', 'safety-net/safety-net.php', 'zz-checkout/zz-checkout.php', 'zz-offline-cod/zz-offline-cod.php' ] ) {
			assert.ok( active.includes( plugin ), `${ plugin } was deactivated` );
		}
	} );

	test( 'A33: AI provider credentials are deleted without a backup, stale AI backups go too, and look-alike options and other backups stay', async () => {
		assert.deepEqual( ai.backups, [ '_secret_ai/anthropic_api_key_sn_backup', 'aipcf_settings_sn_backup', 'connectors_ai_mistral_api_key_sn_backup', 'connectors_ai_openai_api_key_sn_backup', 'koneek_api_key_gemini_sn_backup', 'wp_ai_client_provider_credentials_sn_backup' ], 'Seeding the stale AI backups failed' );
		const s = await site.php( `return array( 'ai' => sn_test_ai_state(), 'backups' => sn_test_snapshot()['backups'], 'rest' => array_map( 'sn_test_raw_option', json_decode( '${ JSON.stringify( ai.rest ?? {} ) }', true ) ) );` );
		assertAiKeysScrubbed( s.ai, seed.ai, 'The first load' );
		assert.deepEqual( Object.entries( s.rest ).filter( ( [ , value ] ) => value !== null ), [], 'AI credentials written through the settings endpoint were not deleted' );
		for ( const backup of [ 'klaviyo_api_key_sn_backup', 'mc4wp_sn_backup', 'woocommerce_stripe_settings_sn_backup' ] ) {
			assert.ok( s.backups.includes( backup ), `${ backup } is missing, so other scrubbed options are no longer backed up` );
		}
	} );

	test( 'A34: core reads its AI provider credentials from the seeded options, and none at all after the first load', async ( t ) => {
		if ( ! wpAtLeast( site, '7.0' ) ) {
			t.skip( 'WordPress before 7.0 has no connectors' );
			return;
		}
		assert.ok( ai.core, `WordPress ${ site.versions.wp } lacks wp_get_connectors() or _wp_connectors_get_api_key_source(), so core's view of the AI keys cannot be checked` );
		const settings = {
			anthropic: 'connectors_ai_anthropic_api_key',
			google: 'connectors_ai_google_api_key',
			openai: 'connectors_ai_openai_api_key',
			'sn-fixture': 'connectors_ai_provider_sn_fixture_api_key',
			'sn-fixture-custom': 'mwlai_actual_computer_api_key',
			...( wpAtLeast( site, '7.1' ) ? { 'sn-fixture-app': 'connectors_ai_provider_sn_fixture_app_application_password' } : {} ),
		};
		const after = await site.php( 'return sn_test_ai_core_view();' );
		for ( const [ id, setting ] of Object.entries( settings ) ) {
			assert.deepEqual( ai.core[ id ], { setting, source: 'database' }, `Core does not read the ${ id } connector's credentials from the seeded option` );
			assert.deepEqual( after[ id ], { setting, source: 'none' }, `Core still finds the ${ id } connector's credentials after the first load` );
		}
		assert.deepEqual( Object.entries( after ).filter( ( [ , connector ] ) => connector.source !== 'none' ), [], 'Core still finds these AI provider credentials after the first load' );
	} );

	test( 'A35: the AI plugin and AI provider plugins are deactivated without the AI plugin\'s deactivation hook, and a look-alike plugin stays active', async () => {
		const s = await site.php( `
$state = array( 'active' => get_option( 'active_plugins' ), 'key' => sn_test_raw_option( 'connectors_ai_openai_api_key' ) );
include_once WP_PLUGIN_DIR . '/ai/ai.php';
do_action( 'deactivate_ai/ai.php', false );
$state['hook_writes'] = sn_test_raw_option( 'connectors_ai_openai_api_key' );
null === $state['key'] ? delete_option( 'connectors_ai_openai_api_key' ) : update_option( 'connectors_ai_openai_api_key', $state['key'] );
return $state;` );
		for ( const plugin of [ 'ai/ai.php', 'ai-provider-for-anthropic/plugin.php', 'aslams-ai-provider-for-grok/ai-provider-for-grok.php' ] ) {
			assert.ok( ! s.active.includes( plugin ), `${ plugin } is still active` );
		}
		assert.ok( s.active.includes( 'ai-services/ai-services.php' ), 'ai-services/ai-services.php was deactivated' );
		assert.equal( s.hook_writes, 'sk-test-decrypted-on-deactivation', 'The AI stub\'s deactivation hook does not write the key, so this check proves nothing' );
		assert.notEqual( s.key, s.hook_writes, 'The AI plugin\'s deactivation hook ran and wrote a decrypted key back after the scrub' );
	} );

	test( 'A36: AI connector application passwords and a connector key stored under a plugin\'s own option name are deleted without a backup', async () => {
		const names = [ 'connectors_ai_provider_acme_application_password', 'mwlai_actual_computer_api_key' ];
		const s = await site.php( `return array( 'ai' => sn_test_ai_state(), 'backups' => sn_test_snapshot()['backups'] );` );
		for ( const name of names ) {
			assert.ok( seed.ai.keys[ name ], `Seeding ${ name } failed, so this proves nothing` );
			assert.equal( s.ai.keys[ name ], null, `${ name } was not deleted` );
			assert.ok( ! s.backups.includes( `${ name }_sn_backup` ), `${ name } was backed up` );
		}
	} );

	test( 'A37: an application password written through WordPress 7.1\'s settings endpoint is deleted, and core no longer finds it', async ( t ) => {
		if ( ! wpAtLeast( site, '7.1' ) ) {
			t.skip( 'WordPress before 7.1 has no application-password connectors' );
			return;
		}
		const setting = 'connectors_ai_provider_sn_fixture_app_application_password';
		assert.equal( ai.rest?.[ 'sn-fixture-app' ], setting, 'The application-password connector was not registered and written, so this proves nothing' );
		const s = await site.php( `return array( 'value' => sn_test_raw_option( '${ setting }' ), 'backup' => sn_test_raw_option( '${ setting }_sn_backup' ), 'credentials' => wp_connectors_get_application_password_credentials( wp_get_connector( 'sn-fixture-app' )['authentication'] ) );` );
		assert.equal( s.value, null, `${ setting } was not deleted` );
		assert.equal( s.backup, null, `${ setting } was backed up` );
		assert.deepEqual( s.credentials, { username: '', password: '', source: 'none' }, 'Core still finds the application password' );
	} );

	test( 'A38: AI provider plugins that \'ai-provider-for-\' misses are deactivated, and plugins named like them stay active', async () => {
		assert.deepEqual( ai.plugins.active, [ ...ai.plugins.providers, ...ai.plugins.lookalikes ], 'Activating the AI provider stubs failed' );
		const active = await site.php( "return get_option( 'active_plugins' );" );
		assert.deepEqual( ai.plugins.providers.filter( ( plugin ) => active.includes( plugin ) ), [], 'These AI provider plugins are still active' );
		assert.deepEqual( ai.plugins.lookalikes.filter( ( plugin ) => ! active.includes( plugin ) ), [], 'These plugins, which are not AI providers, were deactivated' );
	} );

	test( 'A39: keys AI provider plugins keep in their own options are deleted without a backup, except exo\'s local cluster token, and their settings lose only the secrets', async () => {
		const own = [ 'halawa_chatgpt_tokens', 'jokiruiz_local_model_connector_api_key', 'koneek_api_key', 'koneek_api_key_openai', 'mwlai_api_key', 'ultimate_ai_connector_api_key', 'zctz_ollama_ai_connector_cloud_api_key', 'zctz_ollama_ai_connector_self_hosted_api_key', 'zctz_openrouter_secret_api_key' ];
		const s = await site.php( `return array( 'ai' => sn_test_ai_state(), 'backups' => sn_test_snapshot()['backups'] );` );
		for ( const name of own ) {
			assert.ok( seed.ai.keys[ name ], `Seeding ${ name } failed, so this proves nothing` );
			assert.equal( s.ai.keys[ name ], null, `${ name } was not deleted` );
		}
		assert.ok( seed.ai.controls.aiprfoex_api_key, 'Seeding aiprfoex_api_key failed, so this proves nothing' );
		assert.equal( s.ai.controls.aiprfoex_api_key, seed.ai.controls.aiprfoex_api_key, 'aiprfoex_api_key, exo\'s optional token for a model cluster the site owner runs locally, was not kept' );
		assert.ok( ! s.backups.includes( 'aiprfoex_api_key_sn_backup' ), 'aiprfoex_api_key was backed up' );
		const seeded = seed.ai.settings;
		const blank = ( value, keys ) => ( { ...value, ...Object.fromEntries( keys.map( ( key ) => [ key, '' ] ) ) } );
		assert.deepEqual( s.ai.settings, {
			ai_provider_for_cursor_settings: blank( seeded.ai_provider_for_cursor_settings, [ 'api_key' ] ),
			aipcf_settings: blank( seeded.aipcf_settings, [ 'api_key', 'gateway_token', 'qdrant_api_key', 'pg_password' ] ),
			obenweb_openwebui_provider_settings: blank( seeded.obenweb_openwebui_provider_settings, [ 'api_key' ] ),
			ultimate_ai_connector_providers: seeded.ultimate_ai_connector_providers.map( ( provider ) => blank( provider, [ 'api_key' ] ) ),
			vercel_ai_gateway_provider_settings: blank( seeded.vercel_ai_gateway_provider_settings, [ 'api_key' ] ),
			wp_ai_client_credentials: Object.fromEntries( Object.entries( seeded.wp_ai_client_credentials ).map( ( [ provider, credentials ] ) => [ provider, blank( credentials, [ 'api_key' ] ) ] ) ),
		}, 'AI provider plugins\' settings lost more or less than their secrets' );
		const names = [ ...own, ...Object.keys( seeded ) ];
		assert.deepEqual( s.backups.filter( ( backup ) => names.includes( backup.replace( /_sn_backup$/, '' ) ) ), [], 'Keys of AI provider plugins were backed up' );
	} );

	test( 'A17: only administrators remain and their posts were reassigned', async () => {
		const s = await site.php( 'return sn_test_snapshot();' );
		assert.deepEqual( s.users, { admin: 1, admin2: seed.users.admin2 } );
		assert.deepEqual( s.usermeta_uids, [ 1, seed.users.admin2 ] );
		assert.deepEqual( Object.values( s.post_authors ), [ '1', '1' ] );
	} );

	test( 'A18: transients are deleted and other options are left alone', async () => {
		const s = await site.php( "return array( 'snapshot' => sn_test_snapshot(), 'timeout' => sn_test_raw_option( '_transient_timeout_sn_seed' ) );" );
		assert.equal( s.snapshot.options._transient_sn_seed, null );
		assert.equal( s.timeout, null );
		assert.equal( s.snapshot.options._transient_nelio_content_news, null );
		assert.equal( s.snapshot.options.blogname, 'My WordPress Website' );
	} );

	test( 'T1: GiveWP, PMPro, BuddyPress, Jetpack CRM, WPForms, Newsletter and WP Mail Logging data is deleted', async () => {
		const counts = await site.php( `return sn_test_third_party_counts( json_decode( '${ JSON.stringify( thirdParty.tables ) }', true ) );` );
		const left = Object.entries( counts ).filter( ( [ , count ] ) => count !== 0 );
		assert.deepEqual( left, [], 'Rows left behind' );
	} );

	test( 'K12: the BuddyPress cleanup empties every message table and only touches tables BuddyPress has', async () => {
		const counts = await site.php( "return sn_test_third_party_counts( array( 'bp_messages_messages', 'bp_messages_recipients', 'bp_messages_notices', 'bp_messages_meta' ) );" );
		assert.deepEqual( site.logEntriesMatching( /WordPress database error[\s\S]*bp_messages_threads/ ), [] );
		// null would mean the table was never seeded.
		assert.deepEqual( { messages: counts.bp_messages_messages, recipients: counts.bp_messages_recipients, notices: counts.bp_messages_notices, meta: counts.bp_messages_meta }, { messages: 0, recipients: 0, notices: 0, meta: 0 } );
	} );

	test( 'A19: emails are blocked except password resets', async () => {
		const mark = site.logMark();
		const mail = await captureMail( site );
		assertMailBlocked( mail );
		const log = site.logEntriesSince( mark ).join( '\n' );
		assert.match( log, /Email blocked: Order receipt/ );
		assert.ok( log.includes( `Email sent: ${ mail.reset_subject }` ), 'The password reset email was not logged as sent' );
	} );

	test( 'A30: emails sent before init are blocked too, except password resets', async () => {
		await site.php( "update_option( 'sn_test_early_mail', 1 ); return true;" );
		try {
			const mark = site.logMark();
			const early = await site.php( "return $GLOBALS['sn_test_early_mail'] ?? null;", { label: 'sending emails on plugins_loaded' } );
			assert.deepEqual( early, { 'Early order receipt': false, 'Early Password Reset': null }, 'Safety Net did not block a regular email sent on plugins_loaded (false is blocked, null is let through)' );
			assert.match( site.logEntriesSince( mark ).join( '\n' ), /Email blocked: Early order receipt/ );
		} finally {
			await site.php( "delete_option( 'sn_test_early_mail' ); return true;" );
		}
	} );

	test( 'A25: Jetpack subscriptions only go to a category that does not exist and PMPro registers no crons', async () => {
		const s = await site.php( FILTER_PROBE );
		assert.deepEqual( s.jetpack, [ 'non-existing' ] );
		assert.deepEqual( s.pmpro, [], 'pmpro_registered_crons still returns crons after wp_get_ready_cron_jobs()' );
	} );

	test( 'A20: search engines are discouraged', async () => {
		const s = await site.php( "return array( 'filtered' => get_option( 'blog_public' ), 'raw' => sn_test_raw_option( 'blog_public' ) );" );
		assert.equal( s.filtered, 0 );
		assert.equal( s.raw, '1', 'blog_public is filtered, not rewritten' );
		assertNoIndex( ( await site.get( '/' ) ).text );
	} );

	test( 'S2: a post permalink loads', async () => {
		const link = await site.php( `return get_permalink( ${ seed.posts.author } );` );
		const res = await site.get( link );
		assert.match( res.text, /SN seed by author/ );
	} );

	test( 'S3: robots.txt disallows everything', async () => {
		const res = await site.get( '/robots.txt' );
		assert.match( res.headers.get( 'content-type' ), /^text\/plain/ );
		assert.equal( res.text, 'User-agent: *\nDisallow: /\n' );
	} );

	test( 'S4: the login page loads', async () => {
		assert.match( ( await site.get( '/wp-login.php' ) ).text, /name="log"/ );
	} );

	test( 'S9: the REST status route reports every step and is never cached', async () => {
		for ( const res of await site.getAll( [ '/wp-json/safety-net/v1/status', '/?rest_route=/safety-net/v1/status' ] ) ) {
			assert.deepEqual( JSON.parse( res.text ), {
				active: true,
				environment: 'staging',
				options_scrubbed: true,
				plugins_deactivated: true,
				gateway_plugins_deactivated: false,
				data_deleted: true,
				transients_deleted: true,
				webhooks_disabled: true,
			} );
			assert.equal( res.headers.get( 'cache-control' ), 'no-store, no-cache, must-revalidate, max-age=0' );
			assert.equal( res.headers.get( 'pragma' ), 'no-cache' );
			assert.equal( res.headers.get( 'expires' ), 'Wed, 11 Jan 1984 05:00:00 GMT' );
		}
	} );

	test( 'S14: logged-out visitors cannot reach the admin or the AJAX tools', async () => {
		const admin = await site.get( '/wp-admin/', { follow: false, expect: 302 } );
		assert.match( admin.location, /wp-login\.php/ );
		const ajax = await site.post( '/wp-admin/admin-ajax.php', { action: 'safety_net_scrub_options', nonce: 'x' }, { expect: 400 } );
		assert.equal( ajax.text, '0' );
	} );

	test( 'S5: the dashboard shows the Safety Net notice', async () => {
		await site.login();
		const res = await site.get( '/wp-admin/', { jar: site.adminJar } );
		assert.match( res.text, /Safety Net Activated/ );
		assert.match( res.text, /environment type is set to "staging"/ );
		assert.match( res.text, /WooCommerce Subscriptions scheduled actions are currently paused\./ );
	} );

	test( 'S18: a logged-in subscriber is refused by every AJAX tool and nothing changes', async () => {
		await site.php( "wp_set_password( 'password', sn_test_create_user( 'ajax_subscriber', 'subscriber' ) ); return true;", { label: 'creating a subscriber with a known password' } );
		const jar = await site.login( { user: 'ajax_subscriber', jar: new CookieJar() } );
		// Valid nonces for the subscriber's own session, so only the permission check stands between them and the tools.
		const nonces = await noncesForSession( site, jar, 'ajax_subscriber' );
		const sentinels = await seedAjaxSentinels( site, 'ajax_victim_subscriber' );
		for ( const [ action, button ] of AJAX_ACTIONS ) {
			assert.deepEqual( await postAjax( site, action, nonces[ button ], { jar } ), NO_PERMISSION, `${ action } did not refuse a subscriber` );
		}
		await assertAjaxSentinelsUntouched( site, sentinels );
	} );

	test( 'S19: an administrator\'s AJAX request with a bad nonce, or another tool\'s nonce, is refused and nothing changes', async () => {
		const { nonces } = await getToolsPage( site );
		const sentinels = await seedAjaxSentinels( site, 'ajax_victim_nonce' );
		for ( const [ index, [ action ] ] of AJAX_ACTIONS.entries() ) {
			const other = AJAX_ACTIONS[ ( index + 1 ) % AJAX_ACTIONS.length ][ 1 ];
			assert.deepEqual( await postAjax( site, action, 'bad' ), BAD_NONCE, `${ action } accepted the nonce "bad"` );
			assert.deepEqual( await postAjax( site, action, nonces[ other ] ), BAD_NONCE, `${ action } accepted the nonce of ${ other }` );
		}
		await assertAjaxSentinelsUntouched( site, sentinels );
	} );

	test( 'S6: the plugins screen loads and links to the tools', async () => {
		const res = await site.get( '/wp-admin/plugins.php', { jar: site.adminJar } );
		assert.match( res.text, /tools\.php\?page=safety_net_options">Tools<\/a>/ );
	} );

	test( 'S7: the Tools page renders every tool, the pause toggle and its assets', async () => {
		const { res } = await getToolsPage( site );
		assert.match( res.text, /<input id="safety_net_pause_renewal_actions_toggle"[^>]*checked='checked'/ );
		assert.match( res.text, /Installed Plugins/ );
		await assertToolsAssets( site, res.text, '/wp-content/plugins/safety-net/' );
	} );

	test( 'S15: core admin screens load', async () => {
		await site.getAll( [ '/wp-admin/options-reading.php', '/wp-admin/users.php', '/wp-admin/update-core.php' ].map( ( path ) => ( { path, jar: site.adminJar } ) ) );
	} );

	test( 'U6: the self-updater offers a newer GitHub release on staging too', async () => {
		site.setHttpMocks( { [ GITHUB_RELEASE_URL ]: githubRelease( 'v99.0.0' ) } );
		const offer = await site.php( "delete_transient( 'safety_net_github_latest_release' ); delete_site_transient( 'update_plugins' ); wp_update_plugins(); $t = get_site_transient( 'update_plugins' ); return isset( $t->response['safety-net/safety-net.php'] ) ? (array) $t->response['safety-net/safety-net.php'] : null;" );
		site.setHttpMocks( {} );
		assert.equal( offer?.new_version, '99.0.0' );
	} );

	test( 'S10: every AJAX tool succeeds and removes what was added after the automatic pass', async () => {
		const { nonces } = await getToolsPage( site );
		await runAjaxTools( site, nonces );
		assertStepFlags( await site.php( 'return sn_test_flags();' ), { woocommerce: false } );
		assert.deepEqual( await site.php( 'return sn_test_users();' ), { admin: 1, admin2: seed.users.admin2 } );
	} );

	test( 'A22: each step refuses to run before the previous one', async () => {
		const deactivate = await site.php(
			"delete_option( 'safety_net_options_scrubbed' ); register_shutdown_function( function () { update_option( 'safety_net_options_scrubbed', true ); } ); SafetyNet\\DeactivatePlugins\\deactivate_plugins(); return 'not stopped';",
			{ raw: true, label: 'deactivate before scrub' }
		);
		assert.match( deactivate, /\{"success":false,"message":"Safety Net Error: options need to be scrubbed first\."\}/ );

		const del = await site.php(
			"sn_test_create_user( 'guarded', 'subscriber' ); delete_option( 'safety_net_plugins_deactivated' ); register_shutdown_function( function () { update_option( 'safety_net_plugins_deactivated', true ); } ); SafetyNet\\Delete\\delete_users_and_orders(); return 'not stopped';",
			{ raw: true, label: 'delete before deactivate' }
		);
		assert.match( del, /\{"success":false,"message":"Safety Net Error: plugins need to be deactivated first\."\}/ );
		const users = await site.php( 'return sn_test_users();' );
		assert.ok( users.guarded, 'Users were deleted although the plugin step had not run' );
		await site.php( "require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( get_user_by( 'login', 'guarded' )->ID ); return true;" );
	} );

	test( 'A23: the automatic pass runs once; data added afterwards survives later loads', async () => {
		await site.php( "sn_test_create_user( 'late_customer', 'subscriber' ); set_transient( 'sn_late', 'x', DAY_IN_SECONDS ); update_option( 'klaviyo_api_key', 'again' ); update_option( 'connectors_ai_openai_api_key', 'sk-test-late' ); sn_test_activate_plugins( array( 'mailchimp-for-wp/mailchimp-for-wp.php' ) ); return true;" );
		await site.get( '/' );
		await site.get( '/' );
		const s = await site.php( "return array( 'users' => sn_test_users(), 'transient' => get_transient( 'sn_late' ), 'klaviyo' => get_option( 'klaviyo_api_key' ), 'ai' => sn_test_raw_option( 'connectors_ai_openai_api_key' ), 'active' => get_option( 'active_plugins' ) );" );
		assert.ok( s.users.late_customer );
		assert.equal( s.transient, 'x' );
		assert.equal( s.klaviyo, 'again' );
		assert.equal( s.ai, 'sk-test-late' );
		assert.ok( s.active.includes( 'mailchimp-for-wp/mailchimp-for-wp.php' ) );
	} );

	test( 'A40: in the scrub\'s own request, a deleted autoloaded AI key is already out of the options cache when the run-once flag is saved', async () => {
		const s = await site.php( `
delete_option( 'safety_net_options_scrubbed' );
update_option( 'connectors_ai_openai_api_key', 'sk-test-same-request' );
$seen = array( 'cached' => wp_load_alloptions()['connectors_ai_openai_api_key'] ?? null );
add_action(
	'add_option_safety_net_options_scrubbed',
	static function () use ( &$seen ) {
		$seen['flag_saved'] = get_option( 'connectors_ai_openai_api_key' );
	}
);
SafetyNet\\ScrubOptions\\scrub_options();
$seen['after'] = get_option( 'connectors_ai_openai_api_key' );
return $seen;` );
		assert.equal( s.cached, 'sk-test-same-request', 'The seeded key is not in the autoloaded options cache, so this proves nothing' );
		assert.ok( 'flag_saved' in s, 'Scrubbing did not add the run-once flag in this request, so this proves nothing' );
		assert.equal( s.flag_saved, false, 'The deleted AI key was still in the options cache when the run-once flag saved the autoloaded options' );
		assert.equal( s.after, false, 'get_option() still returns the deleted AI key in the same request' );
	} );

	test( 'R1: every integration is valid, is declared by its own file ahead of a site\'s declarations, and shares no option or plugin pattern with another integration or the data files', async () => {
		const r = await site.php( 'return sn_test_integrations_report();', { label: 'reading the integrations registry' } );
		assert.ok( r.scrublist.length > 0 && r.denylist.length > 0, 'The data files read as empty, so the overlap checks prove nothing' );
		const files = r.files.filter( ( file ) => ! file.startsWith( '_' ) );
		assert.deepEqual( r.integrations.map( ( integration ) => integration.slug ), files, 'The registered integrations are not exactly the files in includes/integrations/, in file order' );
		assert.deepEqual( r.declared, Object.fromEntries( files.map( ( file ) => [ file, [ file ] ] ) ), 'Each integration file must declare exactly one integration, whose slug is the file name' );
		assert.deepEqual( r.early, Object.fromEntries( files.map( ( file ) => [ file, true ] ) ), 'Each integration file must add its declaration at BUILT_IN_PRIORITY, so a site\'s declaration that overlaps it is the one skipped' );
		const isNameList = ( list ) => Array.isArray( list ) && list.every( ( value ) => typeof value === 'string' && value !== '' );
		const owners = { option: new Map(), plugin: new Map() };
		for ( const integration of r.integrations ) {
			const { slug } = integration;
			assert.match( slug, /^[a-z0-9-]+$/ );
			for ( const field of r.list_fields ) {
				assert.ok( isNameList( integration[ field ] ), `${ slug }: ${ field } may only hold non-empty strings` );
			}
			for ( const table of [ ...integration.tables, ...integration.network_tables ] ) {
				assert.ok( ! table.startsWith( r.prefix ) && ! table.startsWith( r.base_prefix ), `${ slug }: table ${ table } must leave out the table prefix` );
			}
			for ( const field of [ 'partial_options', 'option_values', 'delete_partial_options' ] ) {
				assert.deepEqual( Object.keys( integration[ field ] ).filter( ( key ) => /^\d*$/.test( key ) ), [], `${ slug }: ${ field } must be keyed by option name` );
			}
			for ( const [ option, keys ] of Object.entries( integration.delete_partial_options ) ) {
				assert.ok( isNameList( keys ) && keys.length > 0, `${ slug }: delete_partial_options must list the keys to blank inside ${ option }` );
			}
			for ( const phase of r.phases ) {
				assert.equal( typeof integration[ phase ], 'boolean', `${ slug }: ${ phase } must be a closure or null` );
			}
			for ( const option of [ ...integration.options, ...Object.keys( integration.partial_options ), ...Object.keys( integration.option_values ), ...integration.delete_options, ...Object.keys( integration.delete_partial_options ) ] ) {
				assert.ok( ! owners.option.has( option ), `Option ${ option } is declared by both ${ owners.option.get( option ) } and ${ slug }` );
				assert.ok( ! r.scrublist.includes( option ), `Option ${ option } is declared by ${ slug } and listed in option_scrublist.txt` );
				owners.option.set( option, slug );
			}
			for ( const pattern of integration.plugins.map( ( plugin ) => plugin.toLowerCase() ) ) {
				assert.ok( ! owners.plugin.has( pattern ), `Plugin pattern ${ pattern } is declared by both ${ owners.plugin.get( pattern ) } and ${ slug }` );
				assert.ok( ! r.denylist.some( ( entry ) => entry.toLowerCase() === pattern ), `Plugin pattern ${ pattern } is declared by ${ slug } and listed in plugin_denylist.txt` );
				owners.plugin.set( pattern, slug );
			}
		}
		const deletesByPrefix = ( integration, option ) => Object.entries( integration.delete_option_prefixes )
			.map( ( [ key, value ] ) => ( typeof value === 'string' ? [ value, [] ] : [ key, value ] ) )
			.some( ( [ prefix, suffixes ] ) => option.startsWith( prefix ) && ( suffixes.length === 0 || suffixes.some( ( suffix ) => option.endsWith( suffix ) ) ) );
		for ( const integration of r.integrations ) {
			for ( const [ option, owner ] of owners.option ) {
				assert.ok( owner === integration.slug || ! deletesByPrefix( integration, option ), `Option ${ option } is declared by ${ owner } and falls under the delete_option_prefixes of ${ integration.slug }` );
			}
			for ( const option of r.scrublist ) {
				assert.ok( ! deletesByPrefix( integration, option ), `Option ${ option } is listed in option_scrublist.txt and falls under the delete_option_prefixes of ${ integration.slug }` );
			}
		}
	} );

	test( 'R2: invalid integration declarations, and ones that overlap the AI integration, are logged and skipped, and a valid one from an mu-plugin gets everything it declares scrubbed, deactivated and deleted', async () => {
		let seeded;
		try {
			seeded = await site.php( "$seeded = sn_test_seed_extra_integration(); foreach ( array( 'safety_net_options_scrubbed', 'safety_net_plugins_deactivated', 'safety_net_data_deleted' ) as $flag ) { delete_option( $flag ); } return $seeded;", { label: 'seeding a test integration and clearing the step flags' } );
			assert.deepEqual( seeded.state, {
				options: { sn_test_extra_secret: 'extra-secret', sn_test_extra_settings: { api_key: 'k', mode: 'live', keep: 'yes' }, sn_test_extra_env: 'live', sn_test_extra_token: 'tok', sn_test_extra_key_a_secret: 'x', sn_test_extra_key_a_url: 'https://example.com', sn_test_bad_secret: 'bad' },
				backups: { sn_test_extra_key_b_secret_sn_backup: 'old', sn_test_extra_token_sn_backup: 'old' },
				tables: { extra: 1, network: 1, bad: 1 },
				posts: 1,
				postmeta: 1,
				comments: { extra: 1, kept: 1 },
				usermeta: [ 'sn_test_extra_meta_1', 'sn_test_extra_meta_2', 'sn_test_keep_meta' ],
				uploads: [ 'export-1.csv', 'export-2.csv', 'keep.txt' ],
				active: true,
				ai: { connectors_ai_openai_api_key: 'sk-test-overlap', connectors_ai_openai_api_key_sn_backup: null, koneek_api_key_openai: 'sk-test-overlap', koneek_api_key_openai_sn_backup: null },
			}, 'Seeding the test integration\'s data failed, so this proves nothing' );

			const before = site.probe().length;
			const mark = site.logMark();
			await site.get( '/' );
			const log = site.logEntriesSince( mark );
			const runs = httpProbeSince( site, before ).map( ( line ) => [ line.runs.safety_net_scrub_options, line.runs.safety_net_deactivate_plugins, line.runs.safety_net_delete_data ] );
			assert.deepEqual( runs, [ [ 1, 1, 1 ] ], 'The page load did not run the scrub, plugins and delete steps again' );
			// A declaration that is not an Integration has no slug, so the log names its position in the filtered list.
			assert.deepEqual( log.map( ( entry ) => entry.match( /Safety Net: ignoring integration (.*)/ )?.[ 1 ].replace( /^#\d+:/, '#N:' ) ).filter( Boolean ), [
				'"SN Test Bad": its slug may only contain a-z, 0-9 and hyphens.',
				'"sn-test-extra": another integration already uses its slug.',
				'"sn-test-claimed": option sn_test_extra_secret is already declared by integration sn-test-extra.',
				'"sn-test-bad-partial": delete_partial_options must map each option to the keys to blank inside it.',
				'"sn-test-ai-engine": plugin pattern ai-engine is already declared by integration ai-connectors.',
				'"sn-test-ai-key": option koneek_api_key_openai falls under the delete_option_prefixes of integration ai-connectors.',
				'#N: expected a SafetyNet\\Integrations\\Integration, got string.',
			], 'Each invalid declaration must be logged once and skipped' );
			assert.deepEqual( log.map( ( entry ) => entry.match( /SN_TEST integration phase (.*)/ )?.[ 1 ] ).filter( Boolean ), [ 'hooks', 'scrub, option ""', 'delete, rows 0', 'late' ], 'The integration\'s closures did not run once each, in phase order, after the declared scrub and deletes' );

			const { res } = await getToolsPage( site );
			const denyListColumn = ( name ) => res.text.match( new RegExp( `<td>${ name.replace( /[()]/g, '\\$&' ) }</td>\\s*<td>[^<]*</td>\\s*<td><span class="dashicons dashicons-([a-z-]+)"` ) )?.[ 1 ];
			assert.equal( denyListColumn( 'Barcode Label Printer (fixture)' ), 'dismiss', 'The Tools page does not list a plugin that is not on the deny list as such, so this check proves nothing' );
			assert.equal( denyListColumn( 'ZZ Single File (fixture)' ), 'yes-alt', 'The Tools page does not show the plugin the integration declares as on the deny list' );

			assert.deepEqual( await site.php( 'return sn_test_extra_integration_state();' ), {
				options: { sn_test_extra_secret: '', sn_test_extra_settings: { api_key: '', mode: 'test', keep: 'yes' }, sn_test_extra_env: 'sandbox', sn_test_extra_token: null, sn_test_extra_key_a_secret: null, sn_test_extra_key_a_url: 'https://example.com', sn_test_bad_secret: 'bad' },
				backups: { sn_test_extra_env_sn_backup: 'live', sn_test_extra_secret_sn_backup: 'extra-secret', sn_test_extra_settings_sn_backup: { api_key: 'k', mode: 'live', keep: 'yes' } },
				tables: { extra: 0, network: 0, bad: 1 },
				posts: 0,
				postmeta: 0,
				comments: { extra: 0, kept: 1 },
				usermeta: [ 'sn_test_keep_meta' ],
				uploads: [ 'keep.txt' ],
				active: false,
				ai: { connectors_ai_openai_api_key: null, connectors_ai_openai_api_key_sn_backup: null, koneek_api_key_openai: null, koneek_api_key_openai_sn_backup: null },
			}, 'The valid integration\'s data was not handled as declared, an invalid or overlapping declaration\'s data was touched, or an overlapping declaration kept an AI key' );
		} finally {
			await site.php( `return sn_test_remove_extra_integration( ${ seeded?.kept_post ?? 0 } );`, { label: 'removing the test integration' } );
		}
	} );

	test( 'A11: Jetpack secrets are kept on Atomic sites', async () => {
		await site.php( "update_option( 'sn_test_atomic', 1 ); update_option( 'jetpack_secrets', array( 'k' => 'atomic' ) ); return true;" );
		const s = await site.php( "SafetyNet\\ScrubOptions\\scrub_options(); delete_option( 'sn_test_atomic' ); return array( 'atomic' => jetpack_is_atomic_site(), 'secrets' => sn_test_raw_option( 'jetpack_secrets' ) );" );
		assert.equal( s.atomic, true );
		assert.deepEqual( s.secrets, { k: 'atomic' } );
	} );

	test( 'A41: with PMPro loaded, scrubbing its last known URL clears its crons, and a URL left unscrubbed or unset does not', async () => {
		const s = await site.php( `
function pmpro_clear_crons() {
	$GLOBALS['sn_test_pmpro_cleared']++;
}
$keep = static fn( $options ) => array_values( array_diff( $options, array( 'pmpro_last_known_url' ) ) );
$runs = array();
foreach ( array( 'filtered' => 'https://example.com', 'unset' => null, 'scrubbed' => 'https://example.com' ) as $case => $url ) {
	null === $url ? delete_option( 'pmpro_last_known_url' ) : update_option( 'pmpro_last_known_url', $url );
	'filtered' === $case ? add_filter( 'safety_net_options_to_clear', $keep ) : remove_filter( 'safety_net_options_to_clear', $keep );
	$GLOBALS['sn_test_pmpro_cleared'] = 0;
	SafetyNet\\ScrubOptions\\scrub_options();
	$runs[ $case ] = array( 'cleared' => $GLOBALS['sn_test_pmpro_cleared'], 'url' => sn_test_raw_option( 'pmpro_last_known_url' ) );
}
return $runs;` );
		assert.deepEqual( s, {
			filtered: { cleared: 0, url: 'https://example.com' },
			unset: { cleared: 0, url: null },
			scrubbed: { cleared: 1, url: 'https://safetynetscrubbedthis.com' },
		} );
	} );

	test( 'A42: options that integrations scrub with a closure stay in the safety_net_options_to_clear list, so a filter that drops them keeps them', async () => {
		const s = await site.php( `
$values = array( 'jetpack_active_modules' => array( 'publicize', 'stats' ), 'wprus' => array( 'encryption' => array( 'aes_key' => 'a' ) ) );
$before = array();
foreach ( $values as $name => $value ) {
	$before[ $name ] = get_option( $name );
	update_option( $name, $value );
}
$listed = array();
$keep   = static function ( $options ) use ( $values, &$listed ) {
	$listed = array_values( array_intersect( array_keys( $values ), $options ) );
	return array_values( array_diff( $options, array_keys( $values ) ) );
};
add_filter( 'safety_net_options_to_clear', $keep );
SafetyNet\\ScrubOptions\\scrub_options();
remove_filter( 'safety_net_options_to_clear', $keep );
$after = array();
foreach ( $values as $name => $value ) {
	$after[ $name ] = sn_test_raw_option( $name );
	false === $before[ $name ] ? delete_option( $name ) : update_option( $name, $before[ $name ] );
}
return array( 'listed' => $listed, 'after' => $after );` );
		assert.deepEqual( s, { listed: [ 'jetpack_active_modules', 'wprus' ], after: { jetpack_active_modules: [ 'publicize', 'stats' ], wprus: { encryption: { aes_key: 'a' } } } } );
	} );

	test( 'A24: safety_net_hide_admin hides the tools but keeps the protection', async () => {
		const { nonces } = await getToolsPage( site );
		await site.php( "update_option( 'sn_test_hide_admin', 1 ); return true;" );
		try {
			const page = await site.get( '/wp-admin/tools.php?page=safety_net_options', { jar: site.adminJar, expect: 403 } );
			assert.match( page.text, /Sorry, you are not allowed to access this page\./ );
			const ajax = await site.post( '/wp-admin/admin-ajax.php', { action: 'safety_net_scrub_options', nonce: nonces[ TOOL_BUTTONS[ 0 ] ] }, { jar: site.adminJar, expect: 400 } );
			assert.equal( ajax.text, '0' );
			assert.match( ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text, /Safety Net Activated/ );
			assertMailBlocked( await captureMail( site ) );
		} finally {
			await site.php( "delete_option( 'sn_test_hide_admin' ); return true;" );
		}
	} );

	test( 'S17: saving the Tools form turns the renewal pause off', async () => {
		await saveToolsForm( site );
		const toggle = await site.php( "return array( 'value' => get_option( 'safety_net_pause_renewal_actions_toggle' ), 'raw' => sn_test_raw_option( 'safety_net_pause_renewal_actions_toggle' ) );" );
		assert.notEqual( toggle.value, 'on' );
		assert.notEqual( toggle.raw, null, 'Saving the form deleted the toggle, which the next load turns back on' );
		assert.doesNotMatch( ( await site.get( '/wp-admin/', { jar: site.adminJar } ) ).text, /scheduled actions are currently paused/ );
	} );

	test( 'K4: an AJAX request without a nonce gets a JSON error, not a fatal, from every tool and nothing changes', async () => {
		const sentinels = await seedAjaxSentinels( site, 'ajax_victim_no_nonce' );
		for ( const [ action ] of AJAX_ACTIONS ) {
			const res = await site.post( '/wp-admin/admin-ajax.php', { action }, { jar: site.adminJar, expect: null } );
			assert.equal( res.status, 200, `${ action } without a nonce returned HTTP ${ res.status }` );
			assert.deepEqual( JSON.parse( res.text ), BAD_NONCE, `${ action } without a nonce` );
		}
		await assertAjaxSentinelsUntouched( site, sentinels );
	} );

	test( 'K8: scrubbing tolerates non-array values in the specially handled options and scrubs them like any other option', async () => {
		const mark = site.logMark();
		const s = await site.php( `
$special = array( 'woocommerce_ppcp-gateway_settings', 'woocommerce-ppcp-settings', 'woocommerce_stripe_settings', 'jetpack_active_modules', '_wp_convertkit_settings', 'apple_news_settings' );
$before  = array();
foreach ( array_merge( $special, array( 'wprus' ) ) as $name ) {
	$before[ $name ] = get_option( $name );
}
foreach ( $special as $name ) {
	update_option( $name, 'not-an-array' );
}
update_option( 'wprus', array( 'encryption' => 'not-an-array', 'keep' => 'yes' ) );
update_option( 'sn_custom_secret', 'k8' );
try {
	SafetyNet\\ScrubOptions\\scrub_options();
	$error = null;
} catch ( Throwable $e ) {
	$error = get_class( $e ) . ': ' . $e->getMessage();
}
$after = array();
foreach ( array_merge( $special, array( 'wprus', 'sn_custom_secret' ) ) as $name ) {
	$after[ $name ] = sn_test_raw_option( $name );
}
update_option( 'wprus', 'not-an-array' );
try {
	SafetyNet\\ScrubOptions\\scrub_options();
	$string_error = null;
} catch ( Throwable $e ) {
	$string_error = get_class( $e ) . ': ' . $e->getMessage();
}
$after['wprus_string'] = sn_test_raw_option( 'wprus' );
foreach ( $before as $name => $value ) {
	false === $value ? delete_option( $name ) : update_option( $name, $value );
}
return array( 'error' => $error, 'string_error' => $string_error, 'after' => $after );` );
		assert.equal( s.error, null );
		assert.equal( s.string_error, null, 'wprus holding a string made scrub_options() throw' );
		assert.equal( s.after.wprus_string, '', 'wprus holding a string was not scrubbed' );
		assert.equal( s.after.sn_custom_secret, '', 'scrub_options() did not get as far as the options added by the safety_net_options_to_clear filter' );
		for ( const name of [ 'woocommerce_ppcp-gateway_settings', 'woocommerce-ppcp-settings', 'woocommerce_stripe_settings', 'jetpack_active_modules', '_wp_convertkit_settings', 'apple_news_settings' ] ) {
			assert.equal( s.after[ name ], '', `${ name } holding a string was not scrubbed` );
		}
		assert.deepEqual( s.after.wprus, { encryption: 'not-an-array', keep: 'yes' }, 'wprus with a non-array encryption entry was changed' );
		assert.deepEqual( site.logEntriesSince( mark ).filter( ( entry ) => /scrub-options\.php|includes\/integrations\//.test( entry ) ), [] );
	} );

	test( 'K1: no database error without WooCommerce', async () => {
		await assertDataDeleted( site );
		assert.deepEqual( site.logEntriesMatching( /WordPress database error[\s\S]*wc_webhooks/ ), [] );
	} );

	test( 'K2: no deprecation notices from the denylist parser', async ( t ) => {
		if ( ! phpAtLeast( site, '8.1' ) ) {
			t.skip( 'K2 only affects PHP 8.1 and later' );
			return;
		}
		await assertDataDeleted( site );
		assert.deepEqual( site.logEntriesMatching( /PHP Deprecated:[^\n]*safety-net\/includes\/utilities\.php/ ), [] );
	} );

	test( 'K3: no "called incorrectly" notice from the early user query', async () => {
		await assertDataDeleted( site );
		assert.deepEqual( site.logEntriesMatching( /SN_TEST attributed: doing_it_wrong_run WP_User_Query::query/ ), [] );
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
