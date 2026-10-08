import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { assertAutomaticPassSince, assertStepFlags } from '../lib/checks.mjs';
import { seedWooCommerceSite } from '../lib/fixtures.mjs';
import { bootSite } from '../lib/site.mjs';

const plain = ( text ) => text.replace( /\x1B\[[0-9;]*m/g, '' );
const GATEWAY_KEYS = [ 'enabled', 'client_secret_production', 'client_id_production', 'client_secret', 'client_id', 'merchant_id', 'merchant_email', 'merchant_id_production', 'merchant_email_production', 'publishable_key', 'secret_key', 'webhook_secret' ];
const PMPRO_OPTIONS = [ 'pmpro_apipassword', 'pmpro_apisignature', 'pmpro_apiusername', 'pmpro_braintree_encryptionkey', 'pmpro_braintree_merchantid', 'pmpro_braintree_privatekey', 'pmpro_braintree_publickey', 'pmpro_cybersource_merchantid', 'pmpro_cybersource_securitykey', 'pmpro_live_stripe_connect_publishablekey', 'pmpro_live_stripe_connect_secretkey', 'pmpro_live_stripe_connect_user_id', 'pmpro_loginname', 'pmpro_payflow_partner', 'pmpro_payflow_pwd', 'pmpro_payflow_user', 'pmpro_payflow_vendor', 'pmpro_paypal_cardinal_apiidentifier', 'pmpro_paypal_cardinal_apikey', 'pmpro_paypal_cardinal_orgunitid', 'pmpro_recaptcha_privatekey', 'pmpro_recaptcha_publickey', 'pmpro_stripe_billingaddress', 'pmpro_stripe_publishablekey', 'pmpro_stripe_secretkey', 'pmpro_transactionkey', 'pmpro_twocheckout_accountnumber', 'pmpro_twocheckout_apipassword', 'pmpro_twocheckout_apiusername', 'pmpromc_options' ];

describe( 'wp-cli: the wp safety-net commands on a WooCommerce store', () => {
	let site;
	let seed;

	async function wpOk( ...args ) {
		const res = await site.wp( args );
		assert.equal( res.exitCode, 0, `wp ${ args.join( ' ' ) } exited with ${ res.exitCode }: ${ plain( res.stderr || res.stdout ).slice( 0, 500 ) }` );
		return { ...res, stdout: plain( res.stdout ), stderr: plain( res.stderr ) };
	}

	before( async () => {
		site = await bootSite( { name: 'wp-cli', env: 'staging', mode: 'plugin', woocommerce: 'active', wpCli: true } );
		seed = await seedWooCommerceSite( site, { hpos: true } );
		await site.enableSafetyNet();
	} );

	after( () => site?.stop() );

	test( 'C2: the first WP-CLI call runs the automatic pass', async () => {
		const before = site.probe().length;
		const res = await wpOk( 'option', 'get', 'blogname' );
		// WP-CLI's bundled libraries print their own PHP deprecations first on new PHP versions.
		assert.equal( res.stdout.trim().split( '\n' ).pop(), 'My WordPress Website' );
		assertAutomaticPassSince( site, before, { label: 'wp option get blogname', match: ( line ) => line.wp_cli && line.uri === '' && ! line.php_run, code: null } );
		const s = await site.php( "return array( 'flags' => sn_test_flags(), 'users' => sn_test_users() );" );
		assertStepFlags( s.flags, { woocommerce: true } );
		assert.deepEqual( s.users, { admin: 1, admin2: seed.base.users.admin2 } );
	} );

	test( 'C5: wp help safety-net lists the commands', async () => {
		const help = ( await wpOk( 'help', 'safety-net' ) ).stdout;
		for ( const command of [ 'scrub-options', 'deactivate-plugins', 'delete', 'delete-transients', 'disable-webhooks' ] ) {
			assert.match( help, new RegExp( `^\\s+${ command }\\s`, 'm' ), `wp help safety-net does not list ${ command }` );
		}
	} );

	test( 'C8: wp safety-net integrations lists each integration and what it declares, or that none is registered', async () => {
		assert.match( ( await wpOk( 'help', 'safety-net' ) ).stdout, /^\s+integrations\s/m, 'wp help safety-net does not list integrations' );
		const table = ( await wpOk( 'safety-net', 'integrations' ) ).stdout;
		assert.match( table, /^\W*slug\W+label\W+plugins\W+options\W+tables\W+post_types\W+usermeta\W+scrub\W+delete\W+hooks\W+late\W*$/m, `The table has other columns: ${ table }` );
		assert.match( table, /^\W*ai-connectors\W+AI providers\W+ai\/ai\.php\b.*?\W20\W+0\W+0\W+0\W+no\W+no\W+no\W+no\W*$/m, `The table does not show the AI integration: ${ table }` );
		assert.match( table, /^\W*mailpoet\W+MailPoet\W+mailpoet\W+0\W+22\W+0\W+0\W+yes\W+yes\W+no\W+no\W*$/m, `The table does not show the MailPoet integration: ${ table }` );
		assert.match( table, /^\W*buddypress\W+BuddyPress\W+0\W+0\W+0\W+0\W+no\W+yes\W+no\W+no\W*$/m, `The table does not show the BuddyPress integration: ${ table }` );
		assert.match( table, /^\W*givewp\W+GiveWP\W+give-, give\/\W+0\W+0\W+0\W+0\W+no\W+yes\W+no\W+no\W*$/m, `The table does not show the GiveWP integration: ${ table }` );
		assert.match( table, /^\W*jetpack-crm\W+Jetpack CRM\W+0\W+20\W+0\W+0\W+no\W+no\W+no\W+no\W*$/m, `The table does not show the Jetpack CRM integration: ${ table }` );
		assert.match( table, /^\W*newsletter\W+Newsletter\W+newsletter\W+0\W+1\W+0\W+0\W+no\W+no\W+no\W+no\W*$/m, `The table does not show the Newsletter integration: ${ table }` );
		assert.match( table, /^\W*pmpro\W+Paid\b.*?\W33\W+6\W+0\W+0\W+yes\W+yes\W+yes\W+no\W*$/m, `The table does not show the PMPro integration: ${ table }` );
		assert.match( table, /^\W*wp-mail-log\w*\W+WP Mail\b.*?\W0\W+1\W+0\W+0\W+no\W+no\W+no\W+no\W*$/m, `The table does not show the WP Mail Logging integration: ${ table }` );
		assert.match( table, /^\W*wpforms\W+WPForms\W+0\W+3\W+0\W+0\W+no\W+no\W+no\W+no\W*$/m, `The table does not show the WPForms integration: ${ table }` );
		assert.match( table, /^\W*apple-news\W+Publish\b.*?\W1\W+0\W+0\W+0\W+no\W+no\W+no\W+no\W*$/m, `The table does not show the Apple News integration: ${ table }` );
		assert.match( table, /^\W*convertkit\W+Kit\W+convert.*?\W1\W+0\W+0\W+0\W+no\W+no\W+no\W+no\W*$/m, `The table does not show the Kit integration: ${ table }` );
		assert.match( table, /^\W*jetpack\W+Jetpack\W+jetpack-.*?\W3\W+0\W+0\W+0\W+no\W+no\W+yes\W+no\W*$/m, `The table does not show the Jetpack integration: ${ table }` );
		assert.match( table, /^\W*paypal\W+PayPal\W+paypal\b.*?\W5\W+0\W+0\W+0\W+no\W+no\W+no\W+no\W*$/m, `The table does not show the PayPal integration: ${ table }` );
		assert.match( table, /^\W*stripe\W+Stripe\W+stripe\b.*?\W3\W+0\W+0\W+0\W+no\W+no\W+no\W+no\W*$/m, `The table does not show the Stripe integration: ${ table }` );
		assert.match( table, /^\W*wprus\W+WP Remote\b.*?\W1\W+0\W+0\W+0\W+no\W+no\W+no\W+no\W*$/m, `The table does not show the WP Remote Users Sync integration: ${ table }` );
		const integrations = JSON.parse( ( await wpOk( 'safety-net', 'integrations', '--format=json' ) ).stdout.trim().split( '\n' ).pop() );
		assert.deepEqual( integrations.map( ( integration ) => integration.slug ), [ 'ai-connectors', 'apple-news', 'buddypress', 'convertkit', 'givewp', 'jetpack-crm', 'jetpack', 'mailpoet', 'newsletter', 'paypal', 'pmpro', 'stripe', 'wp-mail-logging', 'wpforms', 'wprus' ] );
		const { 'ai-connectors': ai, mailpoet, 'apple-news': appleNews, convertkit, jetpack, paypal, stripe, wprus, ...others } = Object.fromEntries( integrations.map( ( integration ) => [ integration.slug, integration ] ) );
		assert.deepEqual(
			{ label: ai.label, plugins: ai.plugins, options: ai.options, delete_options: ai.delete_options.length, delete_partial_options: Object.keys( ai.delete_partial_options ).length, delete_option_prefixes: ai.delete_option_prefixes, phases: [ ai.scrub, ai.delete, ai.hooks, ai.late ] },
			{ label: 'AI providers', plugins: [ 'ai/ai.php', 'ai-engine', 'ai-provider-for-', 'bestony-ai-provider/', 'birbwhale/', 'duetg-ai-connector/', 'duoport-connect-for-opencode/', 'jokiruiz-local-model-connector/', 'koneek-multi-provider-ai-gateway/', 'latentkit-ai-provider/', 'mittwald-ai-provider/', 'modeltrestle-ai-connector-for-nano-gpt/', 'mw-local-ai-connector/', 'mwai', 'onmyodev-connector-for-deepseek/', 'opencode-ai-provider/', 'razhur-connector-for-avalai/', 'sync-to-gpt', 'ultimate-ai-connector-compatible-endpoints/', 'vercel-ai-gateway-provider/', 'zactonz-ai-' ], options: [ 'mwai_options', 'mwai_v2_options' ], delete_options: 8, delete_partial_options: 6, delete_option_prefixes: { connectors_ai_: [ '_api_key', '_application_password' ], '_secret_ai/': [ '_api_key' ], 0: 'koneek_api_key', 1: 'zctz_openrouter_secret_' }, phases: [ false, false, false, false ] },
			'The JSON output does not describe the AI integration'
		);
		assert.deepEqual(
			{ label: mailpoet.label, plugins: mailpoet.plugins, tables: mailpoet.tables.length, action_scheduler_hooks: mailpoet.action_scheduler_hooks, upload_globs: mailpoet.upload_globs.length, phases: [ mailpoet.scrub, mailpoet.delete, mailpoet.hooks, mailpoet.late ] },
			{ label: 'MailPoet', plugins: [ 'mailpoet' ], tables: 22, action_scheduler_hooks: [ 'mailpoet/automation/step' ], upload_globs: 4, phases: [ true, true, false, false ] },
			'The JSON output does not describe the MailPoet integration'
		);
		assert.deepEqual(
			Object.fromEntries( Object.entries( others ).map( ( [ slug, integration ] ) => [ slug, { label: integration.label, plugins: integration.plugins, options: integration.options, option_values: integration.option_values, tables: integration.tables, phases: [ integration.scrub, integration.delete, integration.hooks, integration.late ] } ] ) ),
			{
				buddypress: { label: 'BuddyPress', plugins: [], options: [], option_values: [], tables: [], phases: [ false, true, false, false ] },
				givewp: { label: 'GiveWP', plugins: [ 'give-', 'give/' ], options: [], option_values: [], tables: [], phases: [ false, true, false, false ] },
				'jetpack-crm': { label: 'Jetpack CRM', plugins: [], options: [], option_values: [], tables: [ 'zbs_contacts', 'zbs_contactmeta', 'zbs_companies', 'zbs_companymeta', 'zbs_quotes', 'zbs_quotemeta', 'zbs_invoices', 'zbs_invoicemeta', 'zbs_transactions', 'zbs_transactionmeta', 'zbs_lineitems', 'zbs_events', 'zbs_eventmeta', 'zbs_logs', 'zbs_mail', 'zbs_lists', 'zbs_tags', 'zbs_tagmeta', 'zbs_aliases', 'zbs_objlinks' ], phases: [ false, false, false, false ] },
				newsletter: { label: 'Newsletter', plugins: [ 'newsletter' ], options: [], option_values: [], tables: [ 'newsletter' ], phases: [ false, false, false, false ] },
				pmpro: { label: 'Paid Memberships Pro', plugins: [], options: PMPRO_OPTIONS, option_values: { pmpro_gateway: '', pmpro_gateway_environment: 'sandbox', pmpro_last_known_url: 'https://safetynetscrubbedthis.com' }, tables: [ 'pmpro_membership_orders', 'pmpro_membership_ordermeta', 'pmpro_subscriptions', 'pmpro_subscriptionmeta', 'pmpro_memberships_users', 'pmpro_discount_codes_uses' ], phases: [ true, true, true, false ] },
				'wp-mail-logging': { label: 'WP Mail Logging', plugins: [], options: [], option_values: [], tables: [ 'wpml_mails' ], phases: [ false, false, false, false ] },
				wpforms: { label: 'WPForms', plugins: [], options: [], option_values: [], tables: [ 'wpforms_entries', 'wpforms_entry_meta', 'wpforms_entry_fields' ], phases: [ false, false, false, false ] },
			},
			'The JSON output does not describe the member, donation, CRM and form integrations'
		);
		assert.deepEqual(
			Object.fromEntries( Object.entries( { 'apple-news': appleNews, convertkit, jetpack, paypal, stripe, wprus } ).map( ( [ slug, integration ] ) => [ slug, { label: integration.label, plugins: integration.plugins, options: integration.options, partial_options: integration.partial_options, phases: [ integration.scrub, integration.delete, integration.hooks, integration.late ] } ] ) ),
			{
				'apple-news': { label: 'Publish to Apple News', plugins: [ 'publish-to-apple-news' ], options: [], partial_options: { apple_news_settings: { 0: 'api_key', 1: 'api_secret', 2: 'api_channel', 3: 'apple_news_admin_email', api_autosync: 'no', api_autosync_update: 'no', api_autosync_trash: 'no', api_autosync_delete: 'no', api_autosync_unpublish: 'no', apple_news_enable_debugging: 'no' } }, phases: [ false, false, false, false ] },
				convertkit: { label: 'Kit', plugins: [ 'convertkit' ], options: [], partial_options: { _wp_convertkit_settings: [ 'access_token', 'refresh_token', 'token_expires', 'api_key', 'api_secret' ] }, phases: [ false, false, false, false ] },
				jetpack: { label: 'Jetpack', plugins: [ 'jetpack-social' ], options: [ 'jetpack_private_options', 'jetpack_secrets' ], partial_options: { jetpack_active_modules: true }, phases: [ false, false, true, false ] },
				paypal: { label: 'PayPal', plugins: [ 'paypal' ], options: [ 'woocommerce_braintree_credit_card_settings', 'woocommerce_braintree_paypal_settings', 'woocommerce_paypal_settings' ], partial_options: { 'woocommerce_ppcp-gateway_settings': GATEWAY_KEYS, 'woocommerce-ppcp-settings': GATEWAY_KEYS }, phases: [ false, false, false, false ] },
				stripe: { label: 'Stripe', plugins: [ 'stripe' ], options: [ 'woocommerce_stripe_account_settings', 'woocommerce_stripe_api_settings' ], partial_options: { woocommerce_stripe_settings: GATEWAY_KEYS }, phases: [ false, false, false, false ] },
				wprus: { label: 'WP Remote Users Sync', plugins: [ 'wp-remote-users-sync' ], options: [], partial_options: { wprus: true }, phases: [ false, false, false, false ] },
			},
			'The JSON output does not describe the payment gateway, Jetpack, Kit, Apple News and WP Remote Users Sync integrations'
		);
		const none = "--exec=WP_CLI::add_wp_hook( 'safety_net/integrations', '__return_empty_array', 999 );";
		assert.match( ( await wpOk( 'safety-net', 'integrations', none ) ).stdout, /Success: No integrations registered\./ );
		assert.equal( ( await wpOk( 'safety-net', 'integrations', '--format=json', none ) ).stdout.trim().split( '\n' ).pop(), '[]' );
	} );

	test( 'C1/S11: each command succeeds and does its job', async () => {
		await site.php( "sn_test_create_user( 'cli_customer', 'customer' ); set_transient( 'sn_cli', 'x', DAY_IN_SECONDS ); update_option( 'klaviyo_api_key', 'again' ); update_option( 'connectors_ai_openai_api_key', 'sk-test-cli' ); update_option( '_secret_ai/openai_api_key', base64_encode( 'sn-test-cli' ), false ); sn_test_activate_plugins( array( 'mailchimp-for-wp/mailchimp-for-wp.php', 'zz-checkout/zz-checkout.php' ) ); return true;", { label: 're-seeding after the automatic pass' } );

		assert.match( ( await wpOk( 'safety-net', 'scrub-options' ) ).stdout, /Success: All options have been scrubbed\./ );
		assert.equal( await site.php( "return sn_test_raw_option( 'klaviyo_api_key' );" ), '' );
		const ai = await site.php( 'return sn_test_ai_state();' );
		assert.deepEqual( { openai: ai.keys.connectors_ai_openai_api_key, secret: ai.keys[ '_secret_ai/openai_api_key' ], backups: ai.backups }, { openai: null, secret: null, backups: [] }, 'wp safety-net scrub-options left an AI provider key or a backup of it' );

		assert.match( ( await wpOk( 'safety-net', 'deactivate-plugins' ) ).stdout, /Success: Problematic plugins have been deactivated\./ );
		const active = await site.php( "return get_option( 'active_plugins' );" );
		assert.ok( ! active.includes( 'mailchimp-for-wp/mailchimp-for-wp.php' ) );
		assert.ok( ! active.includes( 'zz-checkout/zz-checkout.php' ), 'With WooCommerce loaded the gateway plugin goes in the same call' );

		assert.match( ( await wpOk( 'safety-net', 'delete' ) ).stdout, /Success: Users and their data have been deleted/ );
		assert.equal( ( await site.php( 'return sn_test_users();' ) ).cli_customer, undefined );

		assert.match( ( await wpOk( 'safety-net', 'delete-transients' ) ).stdout, /Success: Transients have been deleted/ );
		assert.equal( await site.php( "return sn_test_raw_option( '_transient_sn_cli' );" ), null );

		const webhook = await site.php( "$w = new WC_Webhook(); $w->set_name( 'SN cli hook' ); $w->set_topic( 'order.created' ); $w->set_delivery_url( 'https://example.com/cli' ); $w->set_status( 'active' ); $w->set_user_id( 1 ); return $w->save();" );
		assert.match( ( await wpOk( 'safety-net', 'disable-webhooks' ) ).stdout, /Success: All WooCommerce webhooks have been disabled\./ );
		assert.equal( await site.php( `return wc_get_webhook( ${ webhook } )->get_status();` ), 'disabled' );
	} );

	test( 'K5/K11: on a single site neither the automatic run nor the commands record network-wide changes', async () => {
		const flags = await site.php( 'return sn_test_flags();' );
		assert.equal( flags.safety_net_plugins_deactivated, '1', 'Safety Net never deactivated plugins, so this check would pass for the wrong reason' );
		assert.deepEqual( Object.keys( flags ).filter( ( name ) => name.startsWith( 'safety_net_network_' ) ), [] );
	} );

	let refusedExitCode;

	test( 'C4: with the automatic run disabled, wp safety-net delete refuses to run before the plugins step', async () => {
		await site.php( "update_option( 'sn_test_manual_mode', 1 ); sn_test_create_user( 'cli_guarded', 'customer' ); delete_option( 'safety_net_plugins_deactivated' ); return true;" );
		try {
			const res = await site.wp( [ 'safety-net', 'delete' ] );
			refusedExitCode = res.exitCode;
			assert.match( plain( res.stderr ), /Error: Plugins need to be deactivated first\./ );
			assert.ok( ( await site.php( 'return sn_test_users();' ) ).cli_guarded, 'Users were deleted although the plugins step had not run' );
		} finally {
			await site.php( "delete_option( 'sn_test_manual_mode' ); update_option( 'safety_net_plugins_deactivated', true ); return true;" );
		}
	} );

	test( 'K9: a refused delete or deactivate-plugins exits with a non-zero status', async () => {
		assert.equal( typeof refusedExitCode, 'number', 'C4 never ran the refused command' );
		assert.notEqual( refusedExitCode, 0, 'wp safety-net delete exited with 0 although it refused to run' );

		await site.php( "update_option( 'sn_test_manual_mode', 1 ); sn_test_activate_plugins( array( 'mailchimp-for-wp/mailchimp-for-wp.php' ) ); delete_option( 'safety_net_options_scrubbed' ); return true;" );
		try {
			const res = await site.wp( [ 'safety-net', 'deactivate-plugins' ] );
			assert.notEqual( res.exitCode, 0, 'wp safety-net deactivate-plugins exited with 0 although it refused to run' );
			assert.match( plain( res.stderr ), /Error: Options need to be scrubbed first\./ );
			assert.ok( ( await site.php( "return get_option( 'active_plugins' );" ) ).includes( 'mailchimp-for-wp/mailchimp-for-wp.php' ), 'Plugins were deactivated although the scrub step had not run' );
		} finally {
			await site.php( "delete_option( 'sn_test_manual_mode' ); update_option( 'safety_net_options_scrubbed', true ); return true;" );
		}
	} );

	test( 'K10: the documented "wp safety-net delete-transients" works, and so does the old delete_transients name', async () => {
		for ( const command of [ 'delete-transients', 'delete_transients' ] ) {
			await site.php( "set_transient( 'sn_k10', 'x', DAY_IN_SECONDS ); return true;" );
			const res = await site.wp( [ 'safety-net', command ] );
			assert.equal( res.exitCode, 0, `wp safety-net ${ command }: ${ plain( res.stderr ) }` );
			assert.match( plain( res.stdout ), /Success: Transients have been deleted/ );
			assert.equal( await site.php( "return sn_test_raw_option( '_transient_sn_k10' );" ), null, `wp safety-net ${ command } left the transient` );
		}
	} );

	test( 'C6: when the plugins step flag does not stick, the WP-CLI call still completes and the next one deletes the data', async () => {
		await site.php( "sn_test_create_user( 'cli_retry', 'customer' ); sn_test_activate_plugins( array( 'mailchimp-for-wp/mailchimp-for-wp.php' ) ); delete_option( 'safety_net_plugins_deactivated' ); delete_option( 'safety_net_data_deleted' ); update_option( 'sn_test_lost_flag', 'safety_net_plugins_deactivated' ); return true;", { label: 'losing the next write of safety_net_plugins_deactivated' } );
		try {
			// Read from the call's own probe line, since checking the site would load it and run the pass again.
			const getBlogname = async () => {
				const before = site.probe().length;
				const res = await wpOk( 'option', 'get', 'blogname' );
				const lines = site.probe().slice( before ).filter( ( line ) => line.wp_cli );
				assert.equal( lines.length, 1, `wp option get blogname wrote ${ lines.length } probe lines` );
				return { blogname: res.stdout.trim().split( '\n' ).pop(), steps: { deactivate: lines[ 0 ].runs.safety_net_deactivate_plugins, delete: lines[ 0 ].runs.safety_net_delete_data } };
			};

			const mark = site.logMark();
			const lost = await getBlogname();
			assert.equal( lost.blogname, 'My WordPress Website', 'wp option get blogname was cut short after the plugins step flag did not stick' );
			assert.deepEqual( lost.steps, { deactivate: 1, delete: 0 }, 'The delete step was not postponed after the plugins step flag did not stick' );
			assert.match( site.logEntriesSince( mark ).join( '\n' ), /Safety Net: safety_net_plugins_deactivated is not set on site 1, so data deletion is postponed\./ );

			const retry = await getBlogname();
			assert.deepEqual( retry.steps, { deactivate: 1, delete: 1 }, 'The next WP-CLI call did not delete the data' );
			const state = await site.php( "return array( 'flags' => sn_test_flags(), 'users' => sn_test_users(), 'active' => get_option( 'active_plugins' ) );" );
			assertStepFlags( state.flags, { woocommerce: true } );
			assert.equal( state.users.cli_retry, undefined, 'The retried pass did not delete the data' );
			assert.ok( ! state.active.includes( 'mailchimp-for-wp/mailchimp-for-wp.php' ), 'The plugins step left a denylisted plugin active' );
		} finally {
			await site.php( "delete_option( 'sn_test_lost_flag' ); return true;" );
		}
	} );

	test( 'debug.log has no fatal errors and no unexpected Safety Net warnings', () => {
		site.assertCleanLog();
	} );
} );
