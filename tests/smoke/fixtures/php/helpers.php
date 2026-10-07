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

function sn_test_snapshot(): array {
	global $wpdb;
	$options = array();
	foreach ( array( 'admin_email', 'blogname', 'blog_public', 'klaviyo_api_key', 'mc4wp', 'woocommerce_stripe_settings', 'woocommerce-ppcp-settings', 'jetpack_active_modules', 'jetpack_secrets', 'pmpro_gateway', 'pmpro_gateway_environment', 'pmpro_last_known_url', 'default_pingback_flag', 'sn_custom_secret', 'wprus', '_wp_convertkit_settings', 'apple_news_settings', 'active_plugins', '_transient_sn_seed', '_transient_nelio_content_news' ) as $name ) {
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
	);
}

function sn_test_ajax_sentinels( string $login ): array {
	global $wpdb;
	$state = array(
		'user'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->users WHERE user_login = %s", $login ) ),
		'transient' => sn_test_raw_option( '_transient_sn_sentinel' ),
		'secret'    => sn_test_raw_option( 'sn_custom_secret' ),
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
