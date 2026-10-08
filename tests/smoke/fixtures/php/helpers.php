<?php

function sn_test_copy_tree( string $src, string $dst, array $skip_top_level = array() ) {
	if ( ! is_dir( $dst ) && ! mkdir( $dst, 0777, true ) ) {
		throw new RuntimeException( "Could not create $dst" );
	}
	foreach ( scandir( $src ) as $entry ) {
		if ( '.' === $entry || '..' === $entry || in_array( $entry, $skip_top_level, true ) ) {
			continue;
		}
		$from = "$src/$entry";
		$to   = "$dst/$entry";
		if ( is_dir( $from ) ) {
			sn_test_copy_tree( $from, $to );
		} elseif ( ! copy( $from, $to ) ) {
			throw new RuntimeException( "Could not copy $from" );
		}
	}
}

// Leaves out what never ships, mainly so node_modules is not copied into the site.
function sn_test_install_safety_net( string $dst ) {
	$skip = array( 'node_modules', 'tests', 'package.json', 'package-lock.json' );
	foreach ( scandir( '/sn-src' ) as $entry ) {
		if ( '.' === $entry[0] ) {
			$skip[] = $entry;
		}
	}
	sn_test_copy_tree( '/sn-src', $dst, $skip );
	if ( ! file_exists( "$dst/safety-net.php" ) ) {
		throw new RuntimeException( "safety-net.php was not copied to $dst" );
	}
}

function sn_test_install_fixture_plugins() {
	sn_test_copy_tree( '/fixtures/plugins', WP_PLUGIN_DIR );
}

function sn_test_install_helper_mu_plugin() {
	sn_test_copy_tree( '/fixtures/mu-plugins', WPMU_PLUGIN_DIR );
}

function sn_test_activate_plugins( array $plugins ) {
	foreach ( $plugins as $plugin ) {
		if ( ! file_exists( WP_PLUGIN_DIR . "/$plugin" ) ) {
			throw new RuntimeException( "Cannot activate $plugin: its file is missing from the plugins directory." );
		}
	}
	$active = array_values( array_unique( array_merge( (array) get_option( 'active_plugins', array() ), $plugins ) ) );
	sort( $active );
	update_option( 'active_plugins', $active );
}

function sn_test_write_mu_loader( string $relative_main_file = 'safety-net/safety-net.php' ) {
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	$code = "<?php\nrequire_once WPMU_PLUGIN_DIR . '/" . $relative_main_file . "';\n";
	if ( false === file_put_contents( WPMU_PLUGIN_DIR . '/safety-net-loader.php', $code ) ) { // phpcs:ignore
		throw new RuntimeException( 'Could not write the mu-plugin loader.' );
	}
}

// Bypasses option filters and the object cache, since Safety Net filters some options and writes others directly.
function sn_test_raw_option( string $name ) {
	global $wpdb;
	// Not get_var(): it turns an empty string into null, which would hide the difference between scrubbed and missing.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $name ) );
	return null === $row ? null : maybe_unserialize( $row->option_value );
}

function sn_test_table_exists( string $table ): bool {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

function sn_test_count( string $table, string $where = '1=1' ) {
	global $wpdb;
	if ( ! sn_test_table_exists( $table ) ) {
		return null;
	}
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" ); // phpcs:ignore
}

function sn_test_flags(): array {
	global $wpdb;
	$flags = array();
	foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE 'safety\\_net\\_%' ORDER BY option_name" ) as $row ) {
		$flags[ $row->option_name ] = $row->option_value;
	}
	return $flags;
}

function sn_test_users(): array {
	global $wpdb;
	$users = array();
	foreach ( $wpdb->get_results( "SELECT ID, user_login FROM $wpdb->users ORDER BY ID" ) as $user ) {
		$users[ $user->user_login ] = (int) $user->ID;
	}
	return $users;
}

// Core turns a hyphenated provider ID into underscores; the AI plugin's encrypted keys keep the hyphens.
const SN_TEST_AI_KEYS = array( 'connectors_ai_anthropic_api_key', 'connectors_ai_google_api_key', 'connectors_ai_openai_api_key', 'connectors_ai_openai_compatible_servers_api_key', 'connectors_ai_provider_acme_application_password', '_secret_ai/openai_api_key', '_secret_ai/openai-compatible-servers_api_key', 'wp_ai_client_provider_credentials', 'halawa_chatgpt_tokens', 'jokiruiz_local_model_connector_api_key', 'koneek_api_key', 'koneek_api_key_openai', 'mwlai_actual_computer_api_key', 'mwlai_api_key', 'ultimate_ai_connector_api_key', 'zctz_ollama_ai_connector_cloud_api_key', 'zctz_ollama_ai_connector_self_hosted_api_key', 'zctz_openrouter_secret_api_key' );

// Provider plugins' settings that keep their configuration and lose only the secrets inside.
const SN_TEST_AI_SETTINGS = array( 'ai_provider_for_cursor_settings', 'aipcf_settings', 'obenweb_openwebui_provider_settings', 'ultimate_ai_connector_providers', 'vercel_ai_gateway_provider_settings', 'wp_ai_client_credentials' );

// Kept although named like AI credentials: provider endpoints and settings, a non-key row among the AI plugin's secrets, another plugin's secret, the Secrets SDK master key other plugins share, core's Akismet connector key, and exo's optional token for a model cluster the site owner runs locally.
const SN_TEST_AI_CONTROLS = array( 'connectors_ai_openai_compatible_servers_base_url', 'mwlai_endpoint_url', 'zctz_ollama_ai_connector_settings', 'zctz_openrouter_settings', '_secret_ai/openai_base_url', '_secret_otherplugin/openai_api_key', '_secrets_master_key', 'wordpress_api_key', 'aiprfoex_api_key' );

function sn_test_ai_state(): array {
	global $wpdb;
	$state = array(
		'keys'     => array(),
		'settings' => array(),
		'controls' => array(),
		'backups'  => array(),
	);
	foreach ( SN_TEST_AI_KEYS as $name ) {
		$state['keys'][ $name ] = sn_test_raw_option( $name );
	}
	foreach ( SN_TEST_AI_SETTINGS as $name ) {
		$state['settings'][ $name ] = sn_test_raw_option( $name );
	}
	foreach ( SN_TEST_AI_CONTROLS as $name ) {
		$state['controls'][ $name ] = sn_test_raw_option( $name );
	}
	foreach ( $wpdb->get_col( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%\\_sn\\_backup' ORDER BY option_name" ) as $backup ) {
		$name = substr( $backup, 0, -strlen( '_sn_backup' ) );
		$ai   = in_array( $name, SN_TEST_AI_KEYS, true ) || in_array( $name, SN_TEST_AI_SETTINGS, true ) || preg_match( '#^(connectors_ai_|_secret_ai/|wp_ai_client_|koneek_api_key)#', $name );
		if ( $ai && ! in_array( $name, SN_TEST_AI_CONTROLS, true ) ) {
			$state['backups'][] = $backup;
		}
	}
	return $state;
}

function sn_test_snapshot(): array {
	global $wpdb;
	$options = array();
	foreach ( array_merge( array( 'admin_email', 'blogname', 'blog_public', 'klaviyo_api_key', 'mc4wp', 'woocommerce_stripe_settings', 'woocommerce-ppcp-settings', 'jetpack_active_modules', 'jetpack_secrets', 'pmpro_gateway', 'pmpro_gateway_environment', 'pmpro_last_known_url', 'default_pingback_flag', 'sn_custom_secret', 'wprus', '_wp_convertkit_settings', 'apple_news_settings', 'active_plugins', '_transient_sn_seed', '_transient_nelio_content_news' ), SN_TEST_AI_KEYS, SN_TEST_AI_SETTINGS, SN_TEST_AI_CONTROLS ) as $name ) {
		$options[ $name ] = sn_test_raw_option( $name );
	}
	$backups = $wpdb->get_col( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%\\_sn\\_backup' ORDER BY option_name" );
	$posts   = $wpdb->get_results( "SELECT ID, post_author FROM $wpdb->posts WHERE post_title LIKE 'SN seed%' ORDER BY ID", ARRAY_A );
	return array(
		'options'       => $options,
		'backups'       => $backups,
		'flags'         => sn_test_flags(),
		'users'         => sn_test_users(),
		'usermeta_uids' => array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT user_id FROM $wpdb->usermeta ORDER BY user_id" ) ),
		'post_authors'  => array_column( $posts, 'post_author', 'ID' ),
		'pingme'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_pingme'" ),
	);
}

function sn_test_wc_counts(): array {
	global $wpdb;
	$counts = array();
	foreach ( array( 'wc_orders', 'wc_order_addresses', 'wc_orders_meta', 'wc_order_operational_data', 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_customer_lookup', 'wc_order_stats', 'wc_order_product_lookup', 'woocommerce_api_keys', 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta', 'wc_webhooks', 'woocommerce_sessions', 'woocommerce_downloadable_product_permissions', 'wc_download_log' ) as $table ) {
		$counts[ $table ] = sn_test_count( $wpdb->prefix . $table );
	}
	$counts['order_notes']          = sn_test_count( $wpdb->comments, "comment_type = 'order_note'" );
	$counts['shop_order_posts']     = sn_test_count( $wpdb->posts, "post_type = 'shop_order'" );
	$counts['shop_order_postmeta']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->postmeta pm INNER JOIN $wpdb->posts p ON p.ID = pm.post_id WHERE p.post_type = 'shop_order'" );
	$counts['placeholder_posts']    = sn_test_count( $wpdb->posts, "post_type = 'shop_order_placehold'" );
	$counts['refund_posts']         = sn_test_count( $wpdb->posts, "post_type = 'shop_order_refund'" );
	$counts['refund_postmeta']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->postmeta pm INNER JOIN $wpdb->posts p ON p.ID = pm.post_id WHERE p.post_type = 'shop_order_refund'" );
	$counts['subscription_posts']   = sn_test_count( $wpdb->posts, "post_type = 'shop_subscription'" );
	$counts['subscription_meta']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_sn_seed_subscription'" );
	$counts['renewal_actions']      = sn_test_count( $wpdb->prefix . 'actionscheduler_actions', "hook IN ( 'woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry', 'woocommerce_scheduled_subscription_end_of_prepaid_term' )" );
	$counts['keep_actions']         = sn_test_count( $wpdb->prefix . 'actionscheduler_actions', "hook = 'sn_keep_hook'" );
	$counts['renewal_action_logs']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs lg INNER JOIN {$wpdb->prefix}actionscheduler_actions aa ON aa.action_id = lg.action_id WHERE aa.hook LIKE 'woocommerce_scheduled_subscription%'" );
	$counts['orphaned_action_logs'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs lg LEFT JOIN {$wpdb->prefix}actionscheduler_actions aa ON aa.action_id = lg.action_id WHERE aa.action_id IS NULL" );
	$counts['webhooks_active']      = sn_test_count( $wpdb->prefix . 'wc_webhooks', "status = 'active'" );
	return $counts;
}

function sn_test_user_exists( int $id ): bool {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->users WHERE ID = %d", $id ) );
}

function sn_test_order_exists( int $id ): bool {
	global $wpdb;
	if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $id ) );
	}
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE ID = %d AND post_type = 'shop_order'", $id ) );
}

function sn_test_tool_targets( int $user, ?int $order, array $admins ): array {
	return array(
		'user'              => sn_test_user_exists( $user ),
		'transient'         => sn_test_raw_option( '_transient_sn_tool_target' ),
		'transient_timeout' => null !== sn_test_raw_option( '_transient_timeout_sn_tool_target' ),
		'order'             => null === $order ? null : sn_test_order_exists( $order ),
		'admins'            => array_values( array_filter( array_map( 'intval', $admins ), 'sn_test_user_exists' ) ),
		'ai'                => sn_test_raw_option( 'connectors_ai_openai_api_key' ),
	);
}

function sn_test_ajax_sentinels( string $login ): array {
	global $wpdb;
	$state = array(
		'user'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->users WHERE user_login = %s", $login ) ),
		'transient' => sn_test_raw_option( '_transient_sn_sentinel' ),
		'secret'    => sn_test_raw_option( 'sn_custom_secret' ),
		'ai'        => sn_test_raw_option( 'connectors_ai_openai_api_key' ),
		'mailchimp' => in_array( 'mailchimp-for-wp/mailchimp-for-wp.php', (array) get_option( 'active_plugins' ), true ),
	);
	if ( sn_test_table_exists( $wpdb->prefix . 'wc_webhooks' ) ) {
		$state['active_webhooks'] = (int) sn_test_count( $wpdb->prefix . 'wc_webhooks', "status = 'active'" );
	}
	return $state;
}

function sn_test_automatewoo_state( array $seed ): array {
	global $wpdb;
	$status = static function ( $id ) use ( $wpdb ) {
		return $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", $id ) );
	};
	return array(
		'workflow' => get_post_status( (int) $seed['workflow'] ),
		'queue'    => sn_test_count( $wpdb->prefix . 'automatewoo_queue' ),
		'meta'     => sn_test_count( $wpdb->prefix . 'automatewoo_queue_meta' ),
		'action'   => $status( (int) $seed['action'] ),
		'other'    => $status( (int) $seed['other'] ),
	);
}

// Straight from sitemeta, since Safety Net writes the network admin email there directly.
function sn_test_network_state(): array {
	global $wpdb;
	$meta = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM $wpdb->sitemeta WHERE site_id = %d AND ( meta_key IN ( 'admin_email', 'active_sitewide_plugins' ) OR meta_key LIKE 'safety\\_net\\_%' ) ORDER BY meta_key", get_current_network_id() ) ) as $row ) {
		$meta[ $row->meta_key ] = maybe_unserialize( $row->meta_value );
	}
	return array(
		'admin_email' => $meta['admin_email'] ?? null,
		'plugins'     => array_keys( (array) ( $meta['active_sitewide_plugins'] ?? array() ) ),
		'flags'       => (object) array_diff_key( $meta, array_flip( array( 'admin_email', 'active_sitewide_plugins' ) ) ),
	);
}

function sn_test_set_network( array $plugins, string $admin_email ): array {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	foreach ( $plugins as $plugin ) {
		$result = activate_plugin( $plugin, '', true );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( "Could not network-activate $plugin: " . $result->get_error_message() );
		}
	}
	update_site_option( 'admin_email', $admin_email );
	return sn_test_network_state();
}

// The registry as plain data (closures as booleans), which integration files declared which slugs and whether at BUILT_IN_PRIORITY, and the data files the census compares them with.
function sn_test_integrations_report(): array {
	global $wp_filter, $wpdb;
	$dir   = wp_normalize_path( SAFETY_NET_PATH . 'includes/integrations' );
	$files = array_map( static fn( $file ) => basename( $file, '.php' ), glob( "$dir/*.php" ) ?: array() );
	sort( $files, SORT_STRING );

	// Each callback runs on its own, so a declaration is credited to the file that holds the callback adding it.
	$declared = array();
	$early    = array();
	$hook     = $wp_filter['safety_net/integrations'] ?? null;
	foreach ( $hook ? $hook->callbacks : array() as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'];
			if ( is_string( $function ) && str_contains( $function, '::' ) ) {
				$function = explode( '::', $function, 2 );
			} elseif ( is_object( $function ) && ! $function instanceof Closure ) {
				$function = array( $function, '__invoke' );
			}
			$reflection = is_array( $function ) ? new ReflectionMethod( $function[0], $function[1] ) : new ReflectionFunction( $function );
			$file       = wp_normalize_path( (string) $reflection->getFileName() );
			if ( dirname( $file ) !== $dir ) {
				continue;
			}
			$name           = basename( $file, '.php' );
			$early[ $name ] = ( $early[ $name ] ?? true ) && SafetyNet\Integrations\BUILT_IN_PRIORITY === $priority;
			foreach ( (array) call_user_func( $function, array() ) as $integration ) {
				$declared[ $name ][] = $integration instanceof SafetyNet\Integrations\Integration ? $integration->slug : get_debug_type( $integration );
			}
		}
	}

	return array(
		'integrations' => array_values( array_map( static fn( $integration ) => $integration->to_array(), SafetyNet\Integrations\get_integrations() ) ),
		'files'        => $files,
		'declared'     => (object) $declared,
		'early'        => (object) $early,
		'list_fields'  => SafetyNet\Integrations\Integration::LIST_FIELDS,
		'phases'       => SafetyNet\Integrations\Integration::PHASES,
		'scrublist'    => array_values( SafetyNet\Utilities\get_denylist_array( 'options' ) ),
		'denylist'     => array_values( SafetyNet\Utilities\get_denylist_array( 'plugins' ) ),
		'prefix'       => $wpdb->prefix,
		'base_prefix'  => $wpdb->base_prefix,
	);
}

// AI keys, and their backups, that declarations overlapping the AI integration must not keep.
const SN_TEST_OVERLAPPED_AI_KEYS = array( 'connectors_ai_openai_api_key', 'connectors_ai_openai_api_key_sn_backup', 'koneek_api_key_openai', 'koneek_api_key_openai_sn_backup' );

// What the sn-test-extra integration in the helper mu-plugin covers, and what it and the invalid declarations must leave alone.
function sn_test_extra_integration_state(): array {
	global $wpdb;
	$options = array();
	foreach ( array( 'sn_test_extra_secret', 'sn_test_extra_settings', 'sn_test_extra_env', 'sn_test_extra_token', 'sn_test_extra_key_a_secret', 'sn_test_extra_key_a_url', 'sn_test_bad_secret' ) as $name ) {
		$options[ $name ] = sn_test_raw_option( $name );
	}
	$backups = array();
	foreach ( $wpdb->get_col( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'sn\\_test\\_%\\_sn\\_backup' ORDER BY option_name" ) as $backup ) {
		$backups[ $backup ] = sn_test_raw_option( $backup );
	}
	$uploads = glob( wp_upload_dir( null, false )['basedir'] . '/sn-test-extra/*' ) ?: array();
	return array(
		'options'  => $options,
		'backups'  => (object) $backups,
		'tables'   => array(
			'extra'   => sn_test_count( $wpdb->prefix . 'sn_test_extra' ),
			'network' => sn_test_count( $wpdb->base_prefix . 'sn_test_extra_network' ),
			'bad'     => sn_test_count( $wpdb->prefix . 'sn_test_bad' ),
		),
		'posts'    => sn_test_count( $wpdb->posts, "post_type = 'sn_test_extra'" ),
		'postmeta' => sn_test_count( $wpdb->postmeta, "meta_key = '_sn_test_extra'" ),
		'comments' => array(
			'extra' => sn_test_count( $wpdb->comments, "comment_type = 'sn_test_extra'" ),
			'kept'  => sn_test_count( $wpdb->comments, "comment_content = 'SN integration comment to keep'" ),
		),
		'usermeta' => $wpdb->get_col( "SELECT meta_key FROM $wpdb->usermeta WHERE user_id = 1 AND meta_key LIKE 'sn\\_test\\_%' ORDER BY meta_key" ),
		'uploads'  => array_map( 'basename', $uploads ),
		'active'   => in_array( 'zz-single-file.php', (array) get_option( 'active_plugins' ), true ),
		'ai'       => array_combine( SN_TEST_OVERLAPPED_AI_KEYS, array_map( 'sn_test_raw_option', SN_TEST_OVERLAPPED_AI_KEYS ) ),
	);
}

// Seeds one of everything sn-test-extra covers, plus look-alikes it must keep, then registers it and the invalid declarations for the next load.
function sn_test_seed_extra_integration(): array {
	global $wpdb;
	foreach ( array( $wpdb->prefix . 'sn_test_extra', $wpdb->base_prefix . 'sn_test_extra_network', $wpdb->prefix . 'sn_test_bad' ) as $table ) {
		$wpdb->query( "CREATE TABLE $table ( id INTEGER PRIMARY KEY, data TEXT )" ); // phpcs:ignore
		$wpdb->insert( $table, array( 'data' => 'customer1@example.com' ) );
	}
	update_option( 'sn_test_extra_secret', 'extra-secret' );
	update_option( 'sn_test_extra_settings', array( 'api_key' => 'k', 'mode' => 'live', 'keep' => 'yes' ) );
	update_option( 'sn_test_extra_env', 'live' );
	update_option( 'sn_test_extra_token', 'tok' );
	update_option( 'sn_test_extra_token_sn_backup', 'old' );
	update_option( 'sn_test_extra_key_a_secret', 'x' );
	update_option( 'sn_test_extra_key_a_url', 'https://example.com' );
	update_option( 'sn_test_extra_key_b_secret_sn_backup', 'old' );
	update_option( 'sn_test_bad_secret', 'bad' );
	update_option( 'connectors_ai_openai_api_key', 'sk-test-overlap' );
	update_option( 'koneek_api_key_openai', 'sk-test-overlap' );

	$post = wp_insert_post( array( 'post_type' => 'sn_test_extra', 'post_status' => 'publish', 'post_title' => 'SN integration record' ) );
	add_post_meta( $post, '_sn_test_extra', 'customer1@example.com' );
	$kept = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'SN integration post' ) );
	wp_insert_comment( array( 'comment_post_ID' => $kept, 'comment_type' => 'sn_test_extra', 'comment_content' => 'customer1@example.com' ) );
	wp_insert_comment( array( 'comment_post_ID' => $kept, 'comment_content' => 'SN integration comment to keep' ) );

	update_user_meta( 1, 'sn_test_extra_meta_1', 'x' );
	update_user_meta( 1, 'sn_test_extra_meta_2', 'x' );
	update_user_meta( 1, 'sn_test_keep_meta', 'x' );

	$dir = wp_upload_dir( null, false )['basedir'] . '/sn-test-extra';
	wp_mkdir_p( $dir );
	foreach ( array( 'export-1.csv', 'export-2.csv', 'keep.txt' ) as $file ) {
		file_put_contents( "$dir/$file", 'customer1@example.com' ); // phpcs:ignore
	}
	sn_test_activate_plugins( array( 'zz-single-file.php' ) );

	update_option( 'sn_test_extra_integration', 1 );
	update_option( 'sn_test_bad_integration', 1 );
	return array(
		'kept_post' => $kept,
		'state'     => sn_test_extra_integration_state(),
	);
}

function sn_test_remove_extra_integration( int $kept_post ) {
	global $wpdb;
	delete_option( 'sn_test_extra_integration' );
	delete_option( 'sn_test_bad_integration' );
	foreach ( $wpdb->get_col( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'sn\\_test\\_extra\\_%' OR option_name LIKE 'sn\\_test\\_bad\\_%'" ) as $name ) {
		delete_option( $name );
	}
	foreach ( SN_TEST_OVERLAPPED_AI_KEYS as $name ) {
		delete_option( $name );
	}
	foreach ( array( $wpdb->prefix . 'sn_test_extra', $wpdb->base_prefix . 'sn_test_extra_network', $wpdb->prefix . 'sn_test_bad' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore
	}
	wp_delete_post( $kept_post, true );
	delete_user_meta( 1, 'sn_test_keep_meta' );
	$dir = wp_upload_dir( null, false )['basedir'] . '/sn-test-extra';
	foreach ( glob( "$dir/*" ) ?: array() as $file ) {
		unlink( $file ); // phpcs:ignore
	}
	@rmdir( $dir ); // phpcs:ignore
	sn_test_activate_plugins( array( 'zz-single-file.php' ) );
	return true;
}
