<?php

function sn_test_create_user( string $login, string $role ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password(),
			'user_email' => "$login@example.com",
			'first_name' => ucfirst( $login ),
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( "Could not create $login: " . $id->get_error_message() );
	}
	return $id;
}

function sn_test_seed_base(): array {
	$customer_role = get_role( 'customer' ) ? 'customer' : 'subscriber';
	$users         = array();
	foreach ( array( 'admin2' => 'administrator', 'editor1' => 'editor', 'author1' => 'author', 'customer1' => $customer_role, 'subscriber1' => 'subscriber' ) as $login => $role ) {
		$users[ $login ] = sn_test_create_user( $login, $role );
	}
	update_user_meta( $users['customer1'], 'billing_phone', '555-0100' );

	$posts = array(
		'editor' => wp_insert_post( array( 'post_title' => 'SN seed by editor', 'post_status' => 'publish', 'post_author' => $users['editor1'] ) ),
		'author' => wp_insert_post( array( 'post_title' => 'SN seed by author', 'post_status' => 'publish', 'post_author' => $users['author1'] ) ),
	);
	add_post_meta( $posts['author'], '_pingme', '1' );

	update_option( 'klaviyo_api_key', 'pk_live_123' );
	update_option( 'mc4wp', array( 'api_key' => 'k' ) );
	update_option(
		'woocommerce_stripe_settings',
		array(
			'enabled'         => 'yes',
			'publishable_key' => 'pk_x',
			'secret_key'      => 'sk_x',
			'webhook_secret'  => 'wh_x',
			'title'           => 'Card',
			'testmode'        => 'no',
		)
	);
	update_option(
		'woocommerce-ppcp-settings',
		array(
			'client_id'      => 'c',
			'client_secret'  => 's',
			'merchant_id'    => 'm',
			'merchant_email' => 'm@example.com',
			'sandbox_on'     => '1',
		)
	);
	update_option(
		'woocommerce_afterpay_settings',
		array(
			'enabled'         => 'yes',
			'prod-id'         => 'afterpay_merchant',
			'prod-secret-key' => 'afterpay_secret',
		)
	);
	update_option(
		'woocommerce_woocommerce_payments_settings',
		array(
			'enabled'   => 'yes',
			'test_mode' => 'no',
		)
	);
	update_option( 'jetpack_active_modules', array( 'publicize', 'stats', 'subscriptions', 'enhanced-distribution', 'sso' ) );
	update_option( 'jetpack_secrets', array( 'k' => 'v' ) );
	update_option( 'pmpro_gateway', 'stripe' );
	update_option( 'pmpro_gateway_environment', 'live' );
	update_option( 'pmpro_last_known_url', 'https://example.com' );
	update_option( 'sn_custom_secret', 'x' );
	update_option(
		'wprus',
		array(
			'encryption' => array(
				'aes_key'  => 'a',
				'hmac_key' => 'h',
				'other'    => 'keep',
			),
			'keep'       => 'yes',
		)
	);
	update_option(
		'_wp_convertkit_settings',
		array(
			'api_key'       => 'k',
			'api_secret'    => 's',
			'access_token'  => 't',
			'refresh_token' => 'r',
			'token_expires' => 1893456000,
			'other'         => 'keep',
		)
	);
	update_option(
		'apple_news_settings',
		array(
			'api_key'                     => 'k',
			'api_secret'                  => 's',
			'api_channel'                 => 'c',
			'apple_news_admin_email'      => 'news@example.com',
			'api_autosync'                => 'yes',
			'apple_news_enable_debugging' => 'yes',
			'other'                       => 'keep',
		)
	);
	update_option( 'blog_public', '1' );
	update_option( 'default_pingback_flag', '1' );
	set_transient( 'sn_seed', 'x', DAY_IN_SECONDS );
	add_option( '_transient_nelio_content_news', 'x' );
	add_option( '_transient_timeout_nelio_content_news', time() + DAY_IN_SECONDS, '', false );
	foreach ( SN_TEST_BLANKED_OPTIONS as $name => $value ) {
		update_option( $name, $value );
	}
	if ( array_filter( sn_test_blanked_options(), static fn( $option ) => $option['value'] !== $option['seeded'] || null !== $option['backup'] ) ) {
		throw new RuntimeException( 'Seeding the options integrations blank failed: ' . wp_json_encode( sn_test_blanked_options() ) );
	}
	update_option( 'xero_oauth_options', SN_TEST_XERO_TOKENS, false );
	foreach ( SN_TEST_XERO_SETTINGS as $name => $value ) {
		update_option( $name, $value );
	}
	$xero = sn_test_xero_state();
	if ( array( 'tokens' => SN_TEST_XERO_TOKENS, 'backup' => null, 'settings' => SN_TEST_XERO_SETTINGS ) !== $xero ) {
		throw new RuntimeException( 'Seeding WooCommerce Xero failed: ' . wp_json_encode( $xero ) );
	}
	$ai = sn_test_seed_ai_keys( 'site' );

	sn_test_install_fixture_plugins();
	sn_test_activate_plugins(
		array(
			'ai/ai.php',
			'ai-provider-for-anthropic/plugin.php',
			'ai-services/ai-services.php',
			'aslams-ai-provider-for-grok/ai-provider-for-grok.php',
			'barcode-label-printer/barcode-label-printer.php',
			'mailchimp-for-wp/mailchimp-for-wp.php',
			'my-stripe-addon/my-stripe-addon.php',
			'woocommerce-xero/woocommerce-xero.php',
			'wp-mail-smtp/wp_mail_smtp.php',
			'xero-addons/xero-addons.php',
			'zz-checkout/zz-checkout.php',
			'zz-extra-denied/zz-extra-denied.php',
			'zz-keep-gateway/zz-keep-gateway.php',
			'zz-offline-cod/zz-offline-cod.php',
			'zz-single-file.php',
		)
	);

	return array(
		'users'       => $users,
		'posts'       => $posts,
		'admin_email' => get_option( 'admin_email' ),
		'ai'          => $ai,
		'xero'        => $xero,
	);
}

// Shaped and autoloaded as each owner stores them.
function sn_test_seed_ai_keys( string $tag ): array {
	update_option( 'connectors_ai_anthropic_api_key', "sk-test-anthropic-$tag" );
	update_option( 'connectors_ai_google_api_key', "test-google-$tag" );
	update_option( 'connectors_ai_openai_api_key', "sk-test-openai-$tag" );
	update_option( 'connectors_ai_openai_compatible_servers_api_key', "sk-test-local-$tag" );
	update_option(
		'connectors_ai_provider_acme_application_password',
		array(
			'username' => 'sn-app-user',
			'password' => "sn-test-app-password-$tag",
		)
	);
	update_option( '_secret_ai/openai_api_key', base64_encode( "sn-test-nonce-and-ciphertext-openai-$tag" ), false );
	update_option( '_secret_ai/openai-compatible-servers_api_key', base64_encode( "sn-test-nonce-and-ciphertext-local-$tag" ), false );
	update_option(
		'wp_ai_client_provider_credentials',
		array(
			'openai'    => "sk-test-legacy-openai-$tag",
			'anthropic' => "sk-test-legacy-anthropic-$tag",
		)
	);
	sn_test_seed_ai_provider_keys( $tag );
	update_option( 'connectors_ai_openai_compatible_servers_base_url', 'http://localhost:1234/v1' );
	update_option( 'mwlai_endpoint_url', 'https://proxy.example.com' );
	update_option( 'zctz_ollama_ai_connector_settings', array( 'connection_type' => 'cloud', 'host' => 'https://ollama.com' ), false );
	update_option( 'zctz_openrouter_settings', array( 'base_url' => 'https://openrouter.ai/api/v1' ) );
	update_option( '_secret_ai/openai_base_url', base64_encode( "sn-test-not-a-key-$tag" ), false );
	update_option( '_secret_otherplugin/openai_api_key', base64_encode( "sn-test-other-plugin-$tag" ), false );
	update_option( '_secrets_master_key', base64_encode( "sn-test-master-key-$tag" ), false );
	update_option( 'wordpress_api_key', "akismet-test-$tag" );
	$state = sn_test_ai_state();
	if ( in_array( null, $state['keys'], true ) || in_array( null, $state['settings'], true ) || in_array( null, $state['controls'], true ) ) {
		throw new RuntimeException( 'Seeding the AI keys failed: ' . wp_json_encode( $state ) );
	}
	return $state;
}

// Shaped as each provider plugin stores them; the secrets inside a plugin's settings are the sn-secret- values.
function sn_test_seed_ai_provider_keys( string $tag ) {
	update_option( 'aiprfoex_api_key', "sn-test-exo-$tag" );
	update_option( 'halawa_chatgpt_tokens', base64_encode( "sn-test-nonce-and-oauth-tokens-$tag" ), false );
	update_option( 'jokiruiz_local_model_connector_api_key', "sn-test-bridge-$tag" );
	update_option( 'koneek_api_key', base64_encode( "sn-test-koneek-legacy-$tag" ) );
	update_option( 'koneek_api_key_openai', base64_encode( "sn-test-koneek-$tag" ) );
	update_option( 'mwlai_actual_computer_api_key', "sk-test-actual-$tag" );
	update_option( 'mwlai_api_key', "sn-test-tunnel-$tag" );
	update_option( 'ultimate_ai_connector_api_key', "sk-test-ultimate-$tag" );
	update_option( 'zctz_ollama_ai_connector_cloud_api_key', "sn-test-ollama-cloud-$tag", false );
	update_option( 'zctz_ollama_ai_connector_self_hosted_api_key', "sn-test-ollama-$tag", false );
	update_option( 'zctz_openrouter_secret_api_key', "sk-or-test-$tag", false );
	update_option(
		'ai_provider_for_cursor_settings',
		array(
			'api_key'       => "sn-secret-cursor-$tag",
			'default_model' => 'auto',
			'poll_timeout'  => 120,
		),
		false
	);
	update_option(
		'aipcf_settings',
		array(
			'api_key'         => "sn-secret-cloudflare-$tag",
			'account_id'      => 'sn-account',
			'preferred_model' => '@cf/meta/llama-3.1-8b-instruct',
			'gateway_id'      => 'sn-gateway',
			'gateway_token'   => "sn-secret-gateway-$tag",
			'qdrant_url'      => 'https://qdrant.example.com',
			'qdrant_api_key'  => "sn-secret-qdrant-$tag",
			'pg_password'     => "sn-secret-postgres-$tag",
			'backfill_tasks'  => array( 'meta_description' ),
		)
	);
	update_option(
		'obenweb_openwebui_provider_settings',
		array(
			'host'    => 'http://localhost:3000',
			'api_key' => "sn-secret-openwebui-$tag",
			'model'   => 'llama3',
		)
	);
	update_option(
		'ultimate_ai_connector_providers',
		array(
			array(
				'id'           => 'local',
				'endpoint_url' => 'http://localhost:11434/v1',
				'api_key'      => '',
				'enabled'      => true,
			),
			array(
				'id'           => 'remote',
				'endpoint_url' => 'https://api.example.com/v1',
				'api_key'      => "sn-secret-ultimate-$tag",
				'enabled'      => true,
			),
		)
	);
	update_option(
		'vercel_ai_gateway_provider_settings',
		array(
			'api_key'       => "sn-secret-vercel-$tag",
			'default_model' => 'openai/gpt-5',
		)
	);
	update_option(
		'wp_ai_client_credentials',
		array(
			'minimax'      => array( 'api_key' => "sn-secret-minimax-$tag" ),
			'opencode_zen' => array( 'api_key' => "sn-secret-zen-$tag" ),
		)
	);
}

// Backups an older run could have left for AI options a site's safety_net_options_to_clear filter added.
function sn_test_seed_ai_backups(): array {
	update_option( 'connectors_ai_openai_api_key_sn_backup', 'sk-test-openai-old' );
	update_option( 'wp_ai_client_provider_credentials_sn_backup', array( 'openai' => 'sk-test-legacy-openai-old' ) );
	update_option( 'aipcf_settings_sn_backup', array( 'api_key' => 'sn-secret-cloudflare-old' ) );
	update_option( 'connectors_ai_mistral_api_key_sn_backup', 'sk-test-mistral-old' );
	update_option( '_secret_ai/anthropic_api_key_sn_backup', base64_encode( 'sn-test-nonce-and-ciphertext-anthropic-old' ), false );
	update_option( 'koneek_api_key_gemini_sn_backup', base64_encode( 'sn-test-koneek-old' ) );
	foreach ( array( 'connectors_ai_mistral_api_key', '_secret_ai/anthropic_api_key', 'koneek_api_key_gemini' ) as $orphan ) {
		if ( null !== sn_test_raw_option( $orphan ) ) {
			throw new RuntimeException( "The orphaned backup of $orphan has its option beside it." );
		}
	}
	return sn_test_ai_state()['backups'];
}

// The AI provider plugins on wordpress.org that 'ai-provider-for-' misses, by their real basenames.
const SN_TEST_AI_PROVIDER_PLUGINS = array(
	'bestony-ai-provider/bestony-ai-provider.php',
	'birbwhale/birbwhale.php',
	'duetg-ai-connector/duetg-ai-connector.php',
	'duoport-connect-for-opencode/duoport-connect-for-opencode.php',
	'jokiruiz-local-model-connector/jokiruiz-local-model-connector.php',
	'koneek-multi-provider-ai-gateway/koneek-plugin.php',
	'latentkit-ai-provider/latentkit-ai-connector.php',
	'mittwald-ai-provider/mittwald-ai-provider.php',
	'modeltrestle-ai-connector-for-nano-gpt/modeltrestle-ai-connector-for-nano-gpt.php',
	'mw-local-ai-connector/mw-local-ai-connector.php',
	'onmyodev-connector-for-deepseek/onmyodev-connector-for-deepseek.php',
	'opencode-ai-provider/opencode-ai-provider.php',
	'razhur-connector-for-avalai/razhur-connector-for-avalai.php',
	'ultimate-ai-connector-compatible-endpoints/ultimate-ai-connector-compatible-endpoints.php',
	'vercel-ai-gateway-provider/plugin.php',
	'zactonz-ai-connector-for-openrouter/igniter.php',
	'zactonz-ai-provider-ollama/igniter.php',
);

// wordpress.org plugins named like those that are not AI providers.
const SN_TEST_AI_LOOKALIKE_PLUGINS = array(
	'axtolab-ai-connector/axtolab-ai-connector.php',
	'bestonys-ai-settings/bestonys-ai-settings.php',
	'jazzs3quence-priority-manager-for-ai-connectors/jazzs3quence-priority-manager-for-ai-connectors.php',
	'mw-llm-index/mw-llm-index.php',
);

function sn_test_seed_ai_provider_plugins(): array {
	$plugins = array_merge( SN_TEST_AI_PROVIDER_PLUGINS, SN_TEST_AI_LOOKALIKE_PLUGINS );
	foreach ( $plugins as $plugin ) {
		$file = WP_PLUGIN_DIR . "/$plugin";
		if ( ! wp_mkdir_p( dirname( $file ) ) || false === file_put_contents( $file, "<?php\n/* Plugin Name: $plugin stub (fixture) */\n" ) ) { // phpcs:ignore
			throw new RuntimeException( "Could not write the stub plugin $plugin." );
		}
	}
	sn_test_activate_plugins( $plugins );
	return array(
		'providers'  => SN_TEST_AI_PROVIDER_PLUGINS,
		'lookalikes' => SN_TEST_AI_LOOKALIKE_PLUGINS,
		'active'     => array_values( array_intersect( $plugins, (array) get_option( 'active_plugins' ) ) ),
	);
}

// rest_do_request() skips rest_post_dispatch, whose key check needs a provider registered with the AI client.
function sn_test_write_ai_keys_through_rest(): ?array {
	if ( ! function_exists( 'wp_get_connector' ) ) {
		return null;
	}
	$values   = array(
		'sn-fixture'        => 'sk-test-rest',
		'sn-fixture-custom' => 'sk-test-rest-custom',
		'sn-fixture-app'    => array(
			'username' => 'sn-app-user',
			'password' => 'sn-test-rest-app-password',
		),
	);
	$settings = array();
	$body     = array();
	foreach ( $values as $id => $value ) {
		$connector = wp_get_connector( $id );
		if ( null !== $connector ) {
			$settings[ $id ] = $connector['authentication']['setting_name'];
			$body[ $settings[ $id ] ] = $value;
		}
	}
	$user = get_current_user_id();
	wp_set_current_user( 1 );
	$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
	$request->set_body_params( $body );
	$response = rest_do_request( $request );
	wp_set_current_user( $user );
	foreach ( $settings as $id => $setting ) {
		if ( $response->is_error() || $values[ $id ] !== sn_test_raw_option( $setting ) ) {
			throw new RuntimeException( "Writing $setting through the settings endpoint failed: " . wp_json_encode( $response->get_data() ) );
		}
	}
	return $settings;
}

// What core itself reads for each AI provider connector, so a renamed option in core fails the test instead of leaving a seed nobody uses.
function sn_test_ai_core_view(): ?array {
	if ( ! function_exists( 'wp_get_connectors' ) || ! function_exists( '_wp_connectors_get_api_key_source' ) ) {
		return null;
	}
	$view = array();
	foreach ( wp_get_connectors() as $id => $connector ) {
		$auth = $connector['authentication'];
		if ( 'ai_provider' !== $connector['type'] ) {
			continue;
		}
		if ( 'api_key' === $auth['method'] ) {
			$source = _wp_connectors_get_api_key_source( $auth['setting_name'], $auth['env_var_name'] ?? '', $auth['constant_name'] ?? '' );
		} elseif ( 'application_password' === $auth['method'] && function_exists( 'wp_connectors_get_application_password_credentials' ) ) {
			$source = wp_connectors_get_application_password_credentials( $auth )['source'];
		} else {
			continue;
		}
		$view[ $id ] = array(
			'setting' => $auth['setting_name'],
			'source'  => $source,
		);
	}
	ksort( $view );
	return $view;
}

// WooCommerce picks the order data store while booting, so orders must be seeded in a later request.
function sn_test_configure_woocommerce( bool $hpos ): array {
	$sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
	if ( ! $sync->check_orders_table_exists() ) {
		$sync->create_database_tables();
	}
	update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
	update_option( 'woocommerce_coming_soon', 'no' );
	if ( wc_get_page_id( 'shop' ) < 1 || wc_get_page_id( 'checkout' ) < 1 ) {
		WC_Install::create_pages();
	}
	delete_transient( '_wc_activation_redirect' );
	return array( 'orders_table_exists' => $sync->check_orders_table_exists() );
}

function sn_test_seed_woocommerce( int $customer_id ): array {
	global $wpdb;

	sn_test_disable_wc_emails();

	if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() !== ( 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) ) ) {
		throw new RuntimeException( 'Order storage does not match woocommerce_custom_orders_table_enabled.' );
	}

	$product = new WC_Product_Simple();
	$product->set_name( 'SN seed product' );
	$product->set_regular_price( '10' );
	$product->set_status( 'publish' );
	$product_id = $product->save();

	$address = array(
		'first_name' => 'Cus',
		'last_name'  => 'Tomer',
		'email'      => 'customer1@example.com',
		'phone'      => '555-0100',
		'address_1'  => '1 Main St',
		'city'       => 'Springfield',
		'state'      => 'CA',
		'postcode'   => '12345',
		'country'    => 'US',
	);
	$orders  = array();
	for ( $i = 0; $i < 2; $i++ ) {
		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_address( $address, 'billing' );
		$order->set_address( $address, 'shipping' );
		$order->set_payment_method( 'acme_pay' );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();
		$order->add_order_note( 'SN seed note' );
		$orders[] = $order->get_id();
	}

	// Not wc_create_refund(): the hooks it fires crash PHP 7.4 under WebAssembly.
	$refund = new WC_Order_Refund();
	$refund->set_parent_id( $orders[0] );
	$refund->set_amount( '1' );
	$refund->set_reason( 'SN seed refund' );
	$refund->set_refunded_by( 1 );
	$refund->save();

	foreach ( $orders as $order_id ) {
		if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore' ) ) {
			\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( $order_id );
		}
		if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Products\DataStore' ) ) {
			\Automattic\WooCommerce\Admin\API\Reports\Products\DataStore::sync_order_products( $order_id );
		}
	}
	if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore' ) ) {
		\Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore::update_registered_customer( $customer_id );
	}

	$wpdb->insert(
		"{$wpdb->prefix}woocommerce_api_keys",
		array(
			'user_id'         => 1,
			'description'     => 'SN seed key',
			'permissions'     => 'read_write',
			'consumer_key'    => wc_api_hash( 'ck_sn_seed' ),
			'consumer_secret' => 'cs_sn_seed',
			'truncated_key'   => 'snseed1',
		)
	);

	$token = new WC_Payment_Token_CC();
	$token->set_token( 'tok_sn_seed' );
	$token->set_gateway_id( 'acme_pay' );
	$token->set_card_type( 'visa' );
	$token->set_last4( '4242' );
	$token->set_expiry_month( '12' );
	$token->set_expiry_year( '2030' );
	$token->set_user_id( $customer_id );
	$token->save();

	$webhooks = array();
	foreach ( array( 'order.created', 'order.updated' ) as $topic ) {
		$webhook = new WC_Webhook();
		$webhook->set_name( "SN seed $topic" );
		$webhook->set_topic( $topic );
		$webhook->set_delivery_url( 'https://example.com/hook' );
		$webhook->set_status( 'active' );
		$webhook->set_user_id( 1 );
		$webhooks[] = $webhook->save();
	}

	$subscription = wp_insert_post(
		array(
			'post_type'   => 'shop_subscription',
			'post_status' => 'wc-active',
			'post_title'  => 'SN seed subscription',
			'post_author' => 1,
		)
	);
	add_post_meta( $subscription, '_sn_seed_subscription', 'customer1@example.com' );

	$membership = wp_insert_post(
		array(
			'post_type'   => 'wc_user_membership',
			'post_status' => 'wcm-active',
			'post_title'  => 'SN seed membership',
			'post_author' => $customer_id,
		)
	);
	add_post_meta( $membership, '_sn_seed_membership', 'customer1@example.com' );

	$renewal_actions = array();
	foreach ( array( 'woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry', 'woocommerce_scheduled_subscription_end_of_prepaid_term' ) as $hook ) {
		$renewal_actions[] = as_schedule_single_action( time() - HOUR_IN_SECONDS, $hook, array( 'subscription_id' => $subscription ) );
	}
	$keep_action = as_schedule_single_action( time() - HOUR_IN_SECONDS, 'sn_keep_hook', array( 'seed' => 1 ) );

	update_user_meta( 1, '_wcs_subscription_ids_cache', array( 101 ) );
	update_user_meta( 1, 'awcs_subscription_ids_cache', 'control' );

	$wpdb->insert(
		"{$wpdb->prefix}woocommerce_log",
		array(
			'timestamp' => current_time( 'mysql' ),
			'level'     => 200,
			'source'    => 'sn-seed',
			'message'   => 'Payment failed for customer1@example.com',
		)
	);
	$wpdb->insert(
		"{$wpdb->prefix}woocommerce_sessions",
		array(
			'session_key'    => (string) $customer_id,
			'session_value'  => maybe_serialize( array( 'customer' => maybe_serialize( array( 'email' => 'customer1@example.com' ) ) ) ),
			'session_expiry' => time() + DAY_IN_SECONDS,
		)
	);
	$wpdb->insert(
		"{$wpdb->prefix}woocommerce_downloadable_product_permissions",
		array(
			'download_id'         => 'sn-seed',
			'product_id'          => $product_id,
			'order_id'            => $orders[0],
			'order_key'           => 'wc_order_snseed',
			'user_email'          => 'customer1@example.com',
			'user_id'             => $customer_id,
			'downloads_remaining' => '',
			'access_granted'      => current_time( 'mysql' ),
			'download_count'      => 0,
		)
	);
	$wpdb->insert(
		"{$wpdb->prefix}wc_download_log",
		array(
			'timestamp'       => current_time( 'mysql' ),
			'permission_id'   => $wpdb->insert_id,
			'user_id'         => $customer_id,
			'user_ip_address' => '203.0.113.7',
		)
	);

	return array(
		'product'         => $product_id,
		'orders'          => $orders,
		'webhooks'        => $webhooks,
		'subscription'    => $subscription,
		'renewal_actions' => $renewal_actions,
		'keep_action'     => $keep_action,
		'hpos'            => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
		'counts'          => sn_test_wc_counts(),
	);
}

// /shop/ has its own administrator, /empty/ has none, and netadmin is a super admin of a second network only.
function sn_test_seed_network(): array {
	global $wpdb;
	$users = array();
	foreach ( array( 'shopowner', 'superhelper', 'customer2', 'netadmin' ) as $login ) {
		$users[ $login ] = sn_test_create_user( $login, '' );
		remove_user_from_blog( $users[ $login ], 1 );
	}
	$users['editor1'] = sn_test_create_user( 'editor1', 'editor' );
	grant_super_admin( $users['superhelper'] );

	$shop = wpmu_create_blog( DOMAIN_CURRENT_SITE, PATH_CURRENT_SITE . 'shop/', 'Shop', $users['shopowner'] );
	if ( is_wp_error( $shop ) ) {
		throw new RuntimeException( $shop->get_error_message() );
	}
	add_user_to_blog( $shop, $users['customer2'], 'subscriber' );

	$empty = wpmu_create_blog( DOMAIN_CURRENT_SITE, PATH_CURRENT_SITE . 'empty/', 'Empty', $users['shopowner'] );
	if ( is_wp_error( $empty ) ) {
		throw new RuntimeException( $empty->get_error_message() );
	}
	remove_user_from_blog( $users['shopowner'], $empty );

	$wpdb->insert(
		$wpdb->sitemeta,
		array(
			'site_id'    => 2,
			'meta_key'   => 'site_admins', // phpcs:ignore
			'meta_value' => serialize( array( 'netadmin' ) ), // phpcs:ignore
		)
	);

	$posts = array( 'main' => wp_insert_post( array( 'post_title' => 'SN seed main by editor', 'post_status' => 'publish', 'post_author' => $users['editor1'] ) ) );
	$ai    = array();
	foreach ( array( 'shop' => $shop, 'empty' => $empty ) as $key => $blog_id ) {
		switch_to_blog( $blog_id );
		$posts[ $key ] = wp_insert_post( array( 'post_title' => "SN seed $key by customer", 'post_status' => 'publish', 'post_author' => $users['customer2'] ) );
		update_option( 'klaviyo_api_key', "pk_live_$key" );
		update_option( 'blog_public', '1' );
		set_transient( 'sn_seed', 'x', DAY_IN_SECONDS );
		sn_test_seed_ai_backups();
		$ai[ $key ] = sn_test_seed_ai_keys( $key );
		restore_current_blog();
	}
	update_option( 'klaviyo_api_key', 'pk_live_main' );
	set_transient( 'sn_seed', 'x', DAY_IN_SECONDS );
	sn_test_seed_ai_backups();
	$ai['main'] = sn_test_seed_ai_keys( 'main' );

	return array(
		'users'       => $users,
		'sites'       => array(
			'main'  => 1,
			'shop'  => $shop,
			'empty' => $empty,
		),
		'posts'       => $posts,
		'admin_email' => get_option( 'admin_email' ),
		'ai'          => $ai,
	);
}

// switch_to_blog() reads a site without loading it, so this never triggers that site's first run.
function sn_test_network_site_state( int $blog_id ): array {
	global $wpdb;
	switch_to_blog( $blog_id );
	$state = array(
		'flags'        => sn_test_flags(),
		'admin_email'  => sn_test_raw_option( 'admin_email' ),
		'klaviyo'      => sn_test_raw_option( 'klaviyo_api_key' ),
		'blog_public'  => sn_test_raw_option( 'blog_public' ),
		'transient'    => sn_test_raw_option( '_transient_sn_seed' ),
		'post_authors' => array_map( 'intval', $wpdb->get_col( "SELECT post_author FROM $wpdb->posts WHERE post_title LIKE 'SN seed%' ORDER BY ID" ) ),
		'ai'           => sn_test_ai_state(),
	);
	restore_current_blog();
	return $state;
}

const SN_TEST_BUDDYPRESS_TABLES = array( 'bp_xprofile_data', 'bp_friends', 'bp_messages_messages', 'bp_messages_recipients', 'bp_messages_notices', 'bp_messages_meta', 'bp_notifications', 'bp_notifications_meta' );

// BuddyPress keeps its tables at the network's prefix, so on multisite every site shares them.
function sn_test_seed_network_buddypress(): array {
	global $wpdb;
	foreach ( SN_TEST_BUDDYPRESS_TABLES as $table ) {
		$wpdb->query( "CREATE TABLE {$wpdb->base_prefix}$table ( id INTEGER PRIMARY KEY, data TEXT )" ); // phpcs:ignore
		$wpdb->insert( $wpdb->base_prefix . $table, array( 'data' => 'customer2@example.com' ) );
	}
	$wpdb->insert(
		$wpdb->signups,
		array(
			'domain'         => '',
			'path'           => '',
			'title'          => '',
			'user_login'     => 'bpsignup',
			'user_email'     => 'bpsignup@example.com',
			'registered'     => current_time( 'mysql', true ),
			'activation_key' => 'sn-test',
			'meta'           => '',
		)
	);
	update_user_meta( 1, 'total_friend_count', 3 );
	return sn_test_network_buddypress_counts();
}

function sn_test_network_buddypress_counts(): array {
	global $wpdb;
	$counts = array();
	foreach ( SN_TEST_BUDDYPRESS_TABLES as $table ) {
		$counts[ $table ] = sn_test_count( $wpdb->base_prefix . $table );
	}
	$counts['signups']            = sn_test_count( $wpdb->signups );
	$counts['admin_friend_count'] = sn_test_count( $wpdb->usermeta, "user_id = 1 AND meta_key = 'total_friend_count'" );
	return $counts;
}

// One row in each table these plugins keep personal data in. BuddyPress has no bp_messages_threads table, so none is made.
function sn_test_seed_third_party(): array {
	global $wpdb;
	$tables = array( 'wpml_mails', 'newsletter', 'give_donors', 'give_donormeta', 'give_donationmeta', 'give_comments', 'give_commentmeta', 'give_sessions', 'give_subscriptions', 'give_subscriptionmeta', 'pmpro_membership_orders', 'pmpro_membership_ordermeta', 'pmpro_subscriptions', 'pmpro_subscriptionmeta', 'pmpro_memberships_users', 'pmpro_discount_codes_uses', 'bp_xprofile_data', 'signups', 'bp_friends', 'bp_messages_messages', 'bp_messages_recipients', 'bp_messages_notices', 'bp_messages_meta', 'bp_notifications', 'bp_notifications_meta', 'zbs_contacts', 'zbs_contactmeta', 'zbs_companies', 'zbs_companymeta', 'zbs_quotes', 'zbs_quotemeta', 'zbs_invoices', 'zbs_invoicemeta', 'zbs_transactions', 'zbs_transactionmeta', 'zbs_lineitems', 'zbs_events', 'zbs_eventmeta', 'zbs_logs', 'zbs_mail', 'zbs_lists', 'zbs_tags', 'zbs_tagmeta', 'zbs_aliases', 'zbs_objlinks', 'wpforms_entries', 'wpforms_entry_meta', 'wpforms_entry_fields' );
	foreach ( $tables as $table ) {
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}$table ( id INTEGER PRIMARY KEY, data TEXT )" ); // phpcs:ignore
		$wpdb->insert( $wpdb->prefix . $table, array( 'data' => 'customer1@example.com' ) );
	}
	$donation = wp_insert_post( array( 'post_type' => 'give_payment', 'post_status' => 'publish', 'post_title' => 'SN seed donation' ) );
	add_post_meta( $donation, '_give_payment_donor_email', 'customer1@example.com' );
	update_user_meta( 1, 'pmpro_stripe_customerid', 'cus_x' );
	update_user_meta( 1, 'pmpro_bfirstname', 'Admin' );
	update_user_meta( 1, 'total_friend_count', 3 );
	update_option( 'pmpro_stripe_secretkey', 'sk_live_pmpro' );
	return array(
		'tables'   => $tables,
		'donation' => $donation,
	);
}

function sn_test_third_party_counts( array $tables ): array {
	global $wpdb;
	$counts = array();
	foreach ( $tables as $table ) {
		$counts[ $table ] = sn_test_count( $wpdb->prefix . $table );
	}
	$counts['give_payment_posts'] = sn_test_count( $wpdb->posts, "post_type = 'give_payment'" );
	$counts['admin_pmpro_meta']   = sn_test_count( $wpdb->usermeta, "user_id = 1 AND ( meta_key = 'pmpro_stripe_customerid' OR meta_key LIKE 'pmpro\\_b%' )" );
	$counts['admin_friend_count'] = sn_test_count( $wpdb->usermeta, "user_id = 1 AND meta_key = 'total_friend_count'" );
	return $counts;
}

// PHP 7.4 under WebAssembly crashes while rendering WooCommerce email templates, and test orders need no email.
function sn_test_disable_wc_emails() {
	foreach ( WC()->mailer()->get_emails() as $email ) {
		add_filter( 'woocommerce_email_enabled_' . $email->id, '__return_false' );
	}
}

function sn_test_create_order( int $customer_id ): int {
	sn_test_disable_wc_emails();
	$order = wc_create_order( array( 'customer_id' => $customer_id ) );
	$order->set_billing_email( 'late-customer@example.com' );
	$order->save();
	return $order->get_id();
}

function sn_test_seed_tool_targets( bool $order ): array {
	$user     = sn_test_create_user( uniqid( 'tool_target_' ), 'subscriber' );
	$order_id = $order ? sn_test_create_order( $user ) : null;
	set_transient( 'sn_tool_target', 'x', DAY_IN_SECONDS );
	update_option( 'connectors_ai_openai_api_key', 'sk-test-tool' );
	$admins = array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
	sort( $admins );
	return array(
		'user'   => $user,
		'order'  => $order_id,
		'admins' => $admins,
		'state'  => sn_test_tool_targets( $user, $order_id, $admins ),
	);
}

function sn_test_seed_ajax_sentinels( string $login ): array {
	sn_test_create_user( $login, 'subscriber' );
	set_transient( 'sn_sentinel', 'x', DAY_IN_SECONDS );
	update_option( 'sn_custom_secret', 'sentinel' );
	update_option( 'connectors_ai_openai_api_key', 'sentinel' );
	sn_test_activate_plugins( array( 'mailchimp-for-wp/mailchimp-for-wp.php' ) );
	$state = sn_test_ajax_sentinels( $login );
	if ( 1 !== $state['user'] || 'x' !== $state['transient'] || 'sentinel' !== $state['secret'] || 'sentinel' !== $state['ai'] || ! $state['mailchimp'] ) {
		throw new RuntimeException( 'Seeding the AJAX sentinels failed: ' . wp_json_encode( $state ) );
	}
	return array(
		'login' => $login,
		'state' => $state,
	);
}

// One of everything scrub_options() disables for AutomateWoo, plus a pending action it must leave alone.
function sn_test_seed_automatewoo(): array {
	global $wpdb;
	$workflow = wp_insert_post(
		array(
			'post_type'   => 'aw_workflow',
			'post_status' => 'publish',
			'post_title'  => 'SN seed workflow',
		)
	);
	foreach ( array( 'automatewoo_queue', 'automatewoo_queue_meta' ) as $table ) {
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}$table ( id INTEGER PRIMARY KEY, data TEXT )" ); // phpcs:ignore
		$wpdb->insert( $wpdb->prefix . $table, array( 'data' => 'customer1@example.com' ) );
	}
	return array(
		'workflow' => $workflow,
		'action'   => as_schedule_single_action( time() - HOUR_IN_SECONDS, 'automatewoo/sn_seed_event', array( 'seed' => 1 ) ),
		'other'    => as_schedule_single_action( time() - HOUR_IN_SECONDS, 'sn_other_pending_hook', array( 'seed' => 1 ) ),
	);
}

// Due in an hour, so no queue run in the scenario claims the control action, which has no callback.
function sn_test_seed_xero_actions(): array {
	$due = time() + HOUR_IN_SECONDS;
	return array(
		'invoice' => as_schedule_single_action( $due, 'woocommerce_xero_schedule_invoice', array( 1, false ), 'wc_xero' ),
		'payment' => as_schedule_single_action( $due, 'woocommerce_xero_schedule_send_payment', array( 1 ), 'wc_xero' ),
		'void'    => as_schedule_single_action( $due, 'woocommerce_xero_schedule_void_invoice', array( 1 ), 'wc_xero' ),
		'control' => as_schedule_single_action( $due, 'woocommerce-xero-sn-control', array( 1 ) ),
	);
}

// Sends each email through wp_mail() but stops it once Safety Net has decided; returns those decisions (false blocked, null let through) and the log entries added.
// An email's 'charset' is returned by a wp_mail_charset filter that exists only while it is sent.
function sn_test_send_emails( array $emails ): array {
	$after    = sn_test_email_log_last_id();
	$verdicts = array();
	$capture  = static function ( $verdict ) use ( &$verdicts ) {
		$verdicts[] = $verdict;
		return false;
	};
	add_filter( 'pre_wp_mail', $capture, 11 );
	foreach ( $emails as $email ) {
		$send    = static fn() => wp_mail( $email['to'], $email['subject'], $email['message'] ?? '', $email['headers'] ?? '', $email['attachments'] ?? array() );
		$charset = isset( $email['charset'] ) ? static fn() => $email['charset'] : null;
		if ( $charset ) {
			add_filter( 'wp_mail_charset', $charset );
		}
		isset( $email['woocommerce'] ) ? sn_test_send_like_woocommerce( $email['woocommerce'], $send ) : $send();
		if ( $charset ) {
			remove_filter( 'wp_mail_charset', $charset );
		}
	}
	remove_filter( 'pre_wp_mail', $capture, 11 );
	return array(
		'verdicts' => $verdicts,
		'entries'  => sn_test_email_log( $after ),
	);
}

// As WC_Email::send() does, the sender and content type filters exist only around the wp_mail() call.
function sn_test_send_like_woocommerce( array $sender, callable $send ) {
	$email = new class( $sender ) {
		public function __construct( private array $sender ) {}

		public function get_from_address() {
			return $this->sender['from'];
		}

		public function get_from_name() {
			return $this->sender['name'];
		}

		public function get_content_type() {
			return $this->sender['type'];
		}
	};
	add_filter( 'wp_mail_from', array( $email, 'get_from_address' ) );
	add_filter( 'wp_mail_from_name', array( $email, 'get_from_name' ) );
	add_filter( 'wp_mail_content_type', array( $email, 'get_content_type' ) );
	$send();
	remove_filter( 'wp_mail_from', array( $email, 'get_from_address' ) );
	remove_filter( 'wp_mail_from_name', array( $email, 'get_from_name' ) );
	remove_filter( 'wp_mail_content_type', array( $email, 'get_content_type' ) );
}

// Sends core's password reset to $reset_login, a WooCommerce-style HTML new-account email with a reset key for $shop_login and core's
// privacy request confirmation to $requester, each stopped once Safety Net has decided; returns each message as wp_mail() got it.
function sn_test_send_key_emails( string $reset_login, string $shop_login, string $requester ): array {
	$after     = sn_test_email_log_last_id();
	$originals = array();
	$verdicts  = array();
	$record    = static function ( $verdict, $atts ) use ( &$originals ) {
		$originals[] = $atts['message'];
		return $verdict;
	};
	$stop      = static function ( $verdict ) use ( &$verdicts ) {
		$verdicts[] = $verdict;
		return false;
	};
	add_filter( 'pre_wp_mail', $record, 9, 2 );
	add_filter( 'pre_wp_mail', $stop, 11 );
	// retrieve_password() names the requester's IP address when nobody is logged in.
	$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '192.0.2.1';
	try {
		$reset   = retrieve_password( $reset_login );
		$shopper = get_user_by( 'login', $shop_login );
		$key     = get_password_reset_key( $shopper );
		if ( is_wp_error( $key ) ) {
			throw new RuntimeException( "Could not create a password reset key for $shop_login: " . $key->get_error_message() );
		}
		$url = 'https://shop.example/my-account/lost-password/?action=newaccount&#038;key=' . $key . '&#038;login=' . rawurlencode( $shop_login );
		sn_test_send_like_woocommerce(
			array(
				'from' => 'shop@example.com',
				'name' => 'Example Shop',
				'type' => 'text/html',
			),
			static fn() => wp_mail( $shopper->user_email, "Your Example Shop account for $shop_login has been created!", '<p>Welcome to Example Shop.</p><p><a href="' . $url . '">Set your new password</a></p>' )
		);
		$request = wp_create_user_request( $requester, 'export_personal_data' );
		if ( is_wp_error( $request ) ) {
			throw new RuntimeException( 'Could not create the privacy request: ' . $request->get_error_message() );
		}
		$confirm = wp_send_user_request( $request );
	} finally {
		remove_filter( 'pre_wp_mail', $record, 9 );
		remove_filter( 'pre_wp_mail', $stop, 11 );
	}
	return array(
		'reset'     => is_wp_error( $reset ) ? $reset->get_error_code() : $reset,
		'confirm'   => is_wp_error( $confirm ) ? $confirm->get_error_code() : $confirm,
		'request'   => $request,
		'verdicts'  => $verdicts,
		'originals' => $originals,
		'entries'   => sn_test_email_log( $after ),
	);
}

// Replaces the email log with the table Safety Net 1.11.0 created, which has no secrets column, holding these entries; null deletes the version option.
function sn_test_email_log_v1( array $entries, ?string $version ): ?array {
	global $wpdb;
	$table = $wpdb->prefix . 'safety_net_email_log';
	$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore
	// Exactly as Safety Net 1.11.0 created it.
	$created = $wpdb->query( // phpcs:ignore
		"CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			logged_at datetime NOT NULL,
			status varchar(20) NOT NULL default '',
			outcome varchar(20) NOT NULL default '',
			error text NOT NULL,
			recipients text NOT NULL,
			subject text NOT NULL,
			message longtext NOT NULL,
			headers text NOT NULL,
			from_address varchar(255) NOT NULL default '',
			content_type varchar(100) NOT NULL default '',
			attachments text NOT NULL,
			PRIMARY KEY  (id),
			KEY logged_at (logged_at),
			KEY outcome (outcome)
		) {$wpdb->get_charset_collate()}"
	);
	if ( false === $created ) {
		throw new RuntimeException( 'Could not create the 1.11.0 email log table: ' . $wpdb->last_error );
	}
	foreach ( $entries as $entry ) {
		unset( $entry['secrets'] );
		if ( false === $wpdb->insert( $table, $entry ) ) {
			throw new RuntimeException( 'Could not add an entry to the 1.11.0 email log table: ' . $wpdb->last_error );
		}
	}
	null === $version ? delete_option( 'safety_net_email_log_db_version' ) : update_option( 'safety_net_email_log_db_version', $version );
	return sn_test_email_log();
}

// Sends Safety Net's copy of a log entry to the first administrator through a PHPMailer that sends nothing. A phpmailer_init
// callback can add a Bcc to it, and a later pre_wp_mail callback can send another email to an address while the copy is sent.
function sn_test_send_copy( int $id, array $options = array() ): array {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	$GLOBALS['phpmailer'] = new class( true ) extends PHPMailer\PHPMailer\PHPMailer {
		public $delivered = 0;

		public function postSend() {
			++$this->delivered;
			return true;
		}
	};

	$after   = sn_test_email_log_last_id();
	$errors  = array();
	$nested  = array();
	$started = false;
	$hooks   = array();
	$hooks[] = array(
		'wp_mail_failed',
		static function ( $error ) use ( &$errors ) {
			$errors[] = $error->get_error_message();
		},
		10,
	);
	if ( ! empty( $options['bcc'] ) ) {
		$hooks[] = array(
			'phpmailer_init',
			static function ( $phpmailer ) use ( $options ) {
				$phpmailer->addBCC( $options['bcc'] );
			},
			10,
		);
	}
	if ( ! empty( $options['nested'] ) ) {
		$hooks[] = array(
			'pre_wp_mail',
			static function ( $verdict ) use ( $options, &$nested, &$started ) {
				if ( ! $started ) {
					$started  = true;
					$nested[] = wp_mail( $options['nested'], 'Nested email while a copy is sent', 'x' );
				}
				return $verdict;
			},
			12,
		);
	}
	foreach ( $hooks as list( $hook, $callback, $priority ) ) {
		add_filter( $hook, $callback, $priority );
	}
	try {
		$sent = SafetyNet\EmailLog\send_copy( SafetyNet\EmailLog\get_entry( $id ), get_userdata( 1 )->user_email );
	} finally {
		foreach ( $hooks as list( $hook, $callback, $priority ) ) {
			remove_filter( $hook, $callback, $priority );
		}
	}

	$mailer = $GLOBALS['phpmailer'];
	return array(
		'sent'         => $sent,
		'delivered'    => $mailer->delivered,
		'recipients'   => array_map( 'current', array_merge( $mailer->getToAddresses(), $mailer->getCcAddresses(), $mailer->getBccAddresses() ) ),
		'content_type' => $mailer->ContentType,
		'errors'       => $errors,
		'nested'       => $nested,
		'flag'         => SafetyNet\EmailLog\sending_copy(),
		'entries'      => sn_test_email_log( $after ),
	);
}

// Sends emails through wp_mail() to its result hooks with a PHPMailer that sends nothing; sn_test_send_each_for_result() lists the options.
function sn_test_send_for_results( array $emails ): array {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	$after   = sn_test_email_log_last_id();
	$counted = array( 'wp_mail_succeeded', 'wp_mail_failed', 'phpmailer_init' );
	$before  = array_map( 'did_action', $counted );
	$stub    = new class( true ) extends PHPMailer\PHPMailer\PHPMailer {
		public $plans     = array();
		public $delivered = array();

		// An email sent while another is sent finishes first, so the last plan for a subject is the one being sent.
		public function postSend() {
			$plan = (array) array_pop( $this->plans[ $this->Subject ] );
			if ( isset( $plan['during'] ) ) {
				sn_test_while_sending( $plan['during'] );
			}
			if ( isset( $plan['refuse'] ) ) {
				throw new PHPMailer\PHPMailer\Exception( $plan['refuse'] );
			}
			$this->delivered[] = $this->Subject;
			return true;
		}
	};
	// wp_mail() sets this validator on the mailer it creates; PHPMailer's own refuses senders such as wordpress@127.0.0.1.
	PHPMailer\PHPMailer\PHPMailer::$validator = static fn( $email ) => (bool) is_email( $email );
	try {
		$results = sn_test_send_each_for_result( $emails, $stub );
	} finally {
		$GLOBALS['phpmailer'] = null;
	}
	return array(
		'results'   => $results,
		'delivered' => $stub->delivered,
		'hooks'     => array_combine( $counted, array_map( static fn( $hook, $count ) => did_action( $hook ) - $count, $counted, $before ) ),
		'entries'   => sn_test_email_log( $after ),
	);
}

// Per email: 'refuse' (PHPMailer's error), 'deliver' => 'mail' (core's mailer and mail()), 'from', 'stop' (a later pre_wp_mail), 'nested', 'during'.
function sn_test_send_each_for_result( array $emails, $stub ): array {
	$results = array();
	foreach ( $emails as $email ) {
		$mailer  = 'mail' === ( $email['deliver'] ?? '' ) ? null : $stub;
		$nested  = null;
		$started = false;
		$hooks   = array();
		if ( isset( $email['from'] ) ) {
			$hooks[] = array( 'wp_mail_from', static fn() => $email['from'], 10, 1 );
		}
		$hooks[] = array(
			'pre_wp_mail',
			static function ( $verdict, $atts ) use ( $email, $stub, $mailer, &$nested, &$started ) {
				if ( $started || $atts['subject'] !== $email['subject'] ) {
					return $verdict;
				}
				$started = true;
				if ( ! empty( $email['nested'] ) ) {
					$nested               = sn_test_send_each_for_result( $email['nested'], $stub );
					$GLOBALS['phpmailer'] = $mailer;
				}
				return empty( $email['stop'] ) ? $verdict : false;
			},
			11,
			2,
		);
		if ( $mailer ) {
			$stub->plans[ $email['subject'] ][] = array_intersect_key( $email, array_flip( array( 'refuse', 'during' ) ) );
		}
		foreach ( $hooks as list( $hook, $callback, $priority, $accepted ) ) {
			add_filter( $hook, $callback, $priority, $accepted );
		}
		$GLOBALS['phpmailer'] = $mailer;
		try {
			$sent = wp_mail( $email['to'], $email['subject'], $email['message'] ?? 'x', $email['headers'] ?? '', $email['attachments'] ?? array() );
		} finally {
			foreach ( $hooks as list( $hook, $callback, $priority ) ) {
				remove_filter( $hook, $callback, $priority );
			}
			sn_test_while_sending( null );
		}
		$results[] = array_filter(
			array(
				'subject' => $email['subject'],
				'sent'    => $sent,
				'nested'  => $nested,
			),
			static fn( $value ) => null !== $value
		);
	}
	return $results;
}

// Changes the site while PHPMailer sends an email; null undoes every change.
function sn_test_while_sending( ?string $change ): void {
	static $undo = array();
	if ( null === $change ) {
		while ( $undo ) {
			( array_pop( $undo ) )();
		}
		return;
	}
	switch ( $change ) {
		case 'logging_off':
			$saved = sn_test_raw_option( 'safety_net_email_log_toggle' );
			update_option( 'safety_net_email_log_toggle', 'off' );
			$undo[] = static fn() => null === $saved ? delete_option( 'safety_net_email_log_toggle' ) : update_option( 'safety_net_email_log_toggle', $saved );
			break;
		case 'forget_table':
			$version = sn_test_raw_option( 'safety_net_email_log_db_version' );
			delete_option( 'safety_net_email_log_db_version' );
			$undo[] = static fn() => update_option( 'safety_net_email_log_db_version', $version );
			break;
		case 'refuse_update':
			$refuse = static fn( $query ) => preg_match( '/^\s*UPDATE\s+`?\w*safety_net_email_log`?\s/i', $query ) ? 'UPDATE sn_test_refused_update SET refused = 1' : $query;
			add_filter( 'query', $refuse );
			$undo[] = static fn() => remove_filter( 'query', $refuse );
			break;
		default:
			throw new RuntimeException( "Unknown change while sending: $change" );
	}
}

// Replaces the not yet loaded WP_PHPMailer with a mailer that records its calls and sends nothing, so every email must be one Safety Net blocks.
// wp_mail()'s own mailer is an earlier email's, already set up, which the dry run and the Raw view must neither use nor change.
function sn_test_record_mailers( array $emails, int $raw_entry ): array {
	if ( class_exists( 'WP_PHPMailer', false ) ) {
		throw new RuntimeException( 'WP_PHPMailer is already loaded, so the recording mailer cannot replace it' );
	}
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	$recorder = new class() extends PHPMailer\PHPMailer\PHPMailer {
		public static $calls = array();

		public function preSend() {
			self::$calls[] = 'preSend';
			return parent::preSend();
		}

		public function send() {
			self::$calls[] = 'send';
			return false;
		}

		public function postSend() {
			self::$calls[] = 'postSend';
			return false;
		}

		protected function mailSend( $header, $body ) {
			self::$calls[] = 'mailSend';
			return false;
		}

		protected function sendmailSend( $header, $body ) {
			self::$calls[] = 'sendmailSend';
			return false;
		}

		protected function smtpSend( $header, $body ) {
			self::$calls[] = 'smtpSend';
			return false;
		}

		public function smtpConnect( $options = null ) {
			self::$calls[] = 'smtpConnect';
			return false;
		}

		public function addAttachment( $path, $name = '', $encoding = self::ENCODING_BASE64, $type = '', $disposition = 'attachment' ) {
			self::$calls[] = 'addAttachment';
			return false;
		}
	};
	class_alias( get_class( $recorder ), 'WP_PHPMailer' );
	$validator                                = static fn( $email ) => (bool) is_email( $email );
	PHPMailer\PHPMailer\PHPMailer::$validator = $validator;
	$outer                                    = new PHPMailer\PHPMailer\PHPMailer( true );
	$outer->addAddress( 'outer@example.com' );
	$outer->Subject       = 'Outer';
	$GLOBALS['phpmailer'] = $outer;

	$after            = sn_test_email_log_last_id();
	$counted          = array( 'phpmailer_init', 'wp_mail_succeeded', 'wp_mail_failed' );
	$before           = array_map( 'did_action', $counted );
	$sent             = array();
	$recorder::$calls = array();
	foreach ( $emails as $email ) {
		$sent[] = wp_mail( $email['to'], $email['subject'], $email['message'] ?? 'x', $email['headers'] ?? '', $email['attachments'] ?? array() );
	}
	$dry_run          = $recorder::$calls;
	$recorder::$calls = array();
	$raw              = SafetyNet\EmailLog\get_raw_message( SafetyNet\EmailLog\get_entry( $raw_entry ) );

	return array(
		'sent'      => $sent,
		'dry_run'   => $dry_run,
		'raw'       => $recorder::$calls,
		'raw_built' => is_string( $raw ) && str_contains( $raw, "\r\n\r\n" ),
		'hooks'     => array_combine( $counted, array_map( static fn( $hook, $count ) => did_action( $hook ) - $count, $counted, $before ) ),
		'outer'     => array(
			'same'    => $GLOBALS['phpmailer'] === $outer,
			'to'      => array_map( 'current', $outer->getToAddresses() ),
			'subject' => $outer->Subject,
		),
		'validator' => PHPMailer\PHPMailer\PHPMailer::$validator === $validator,
		'entries'   => sn_test_email_log( $after ),
	);
}
