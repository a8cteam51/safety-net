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
			'api_key'      => 'k',
			'api_secret'   => 's',
			'access_token' => 't',
			'other'        => 'keep',
		)
	);
	update_option(
		'apple_news_settings',
		array(
			'api_key'                     => 'k',
			'api_secret'                  => 's',
			'api_channel'                 => 'c',
			'api_autosync'                => 'yes',
			'apple_news_enable_debugging' => 'yes',
			'other'                       => 'keep',
		)
	);
	update_option( 'blog_public', '1' );
	update_option( 'default_pingback_flag', '1' );
	set_transient( 'sn_seed', 'x', DAY_IN_SECONDS );
	add_option( '_transient_nelio_content_news', 'x' );

	sn_test_install_fixture_plugins();
	sn_test_activate_plugins(
		array(
			'barcode-label-printer/barcode-label-printer.php',
			'mailchimp-for-wp/mailchimp-for-wp.php',
			'my-stripe-addon/my-stripe-addon.php',
			'wp-mail-smtp/wp_mail_smtp.php',
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
	);
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

	$renewal_actions = array();
	foreach ( array( 'woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry', 'woocommerce_scheduled_subscription_end_of_prepaid_term' ) as $hook ) {
		$renewal_actions[] = as_schedule_single_action( time() - HOUR_IN_SECONDS, $hook, array( 'subscription_id' => $subscription ) );
	}
	$keep_action = as_schedule_single_action( time() - HOUR_IN_SECONDS, 'sn_keep_hook', array( 'seed' => 1 ) );

	update_user_meta( 1, '_wcs_subscription_ids_cache', array( 101 ) );
	update_user_meta( 1, 'awcs_subscription_ids_cache', 'control' );

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
	foreach ( array( 'shop' => $shop, 'empty' => $empty ) as $key => $blog_id ) {
		switch_to_blog( $blog_id );
		$posts[ $key ] = wp_insert_post( array( 'post_title' => "SN seed $key by customer", 'post_status' => 'publish', 'post_author' => $users['customer2'] ) );
		update_option( 'klaviyo_api_key', "pk_live_$key" );
		update_option( 'blog_public', '1' );
		set_transient( 'sn_seed', 'x', DAY_IN_SECONDS );
		restore_current_blog();
	}
	update_option( 'klaviyo_api_key', 'pk_live_main' );
	set_transient( 'sn_seed', 'x', DAY_IN_SECONDS );

	return array(
		'users'       => $users,
		'sites'       => array(
			'main'  => 1,
			'shop'  => $shop,
			'empty' => $empty,
		),
		'posts'       => $posts,
		'admin_email' => get_option( 'admin_email' ),
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
	$tables = array( 'wpml_mails', 'newsletter', 'give_donors', 'give_donormeta', 'give_donationmeta', 'give_comments', 'give_commentmeta', 'give_sessions', 'give_subscriptions', 'give_subscriptionmeta', 'pmpro_membership_orders', 'pmpro_membership_ordermeta', 'pmpro_subscriptions', 'pmpro_subscriptionmeta', 'pmpro_memberships_users', 'pmpro_discount_codes_uses', 'bp_xprofile_data', 'signups', 'bp_friends', 'bp_messages_messages', 'bp_messages_recipients', 'bp_messages_notices', 'bp_messages_meta', 'bp_notifications', 'bp_notifications_meta', 'zbs_contacts', 'zbs_contactmeta', 'zbs_transactions', 'wpforms_entries', 'wpforms_entry_meta', 'wpforms_entry_fields' );
	foreach ( $tables as $table ) {
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}$table ( id INTEGER PRIMARY KEY, data TEXT )" ); // phpcs:ignore
		$wpdb->insert( $wpdb->prefix . $table, array( 'data' => 'customer1@example.com' ) );
	}
	$donation = wp_insert_post( array( 'post_type' => 'give_payment', 'post_status' => 'publish', 'post_title' => 'SN seed donation' ) );
	add_post_meta( $donation, '_give_payment_donor_email', 'customer1@example.com' );
	update_user_meta( 1, 'pmpro_stripe_customerid', 'cus_x' );
	update_user_meta( 1, 'pmpro_bfirstname', 'Admin' );
	update_user_meta( 1, 'total_friend_count', 3 );
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
	sn_test_activate_plugins( array( 'mailchimp-for-wp/mailchimp-for-wp.php' ) );
	$state = sn_test_ajax_sentinels( $login );
	if ( 1 !== $state['user'] || 'x' !== $state['transient'] || 'sentinel' !== $state['secret'] || ! $state['mailchimp'] ) {
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
