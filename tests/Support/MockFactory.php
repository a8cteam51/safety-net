<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Class that has a collection of Mocks for the tests.
 */
class MockFactory {

	/**
	 * Generate a unique string.
	 *
	 * @return string
	 */
	public static function uniqueString() {
		return md5( uniqid( (string) rand(), true ) );
	}


	/**
	 * Creates mock tables and data for WP Mail Logging and Newletter Plugin.
	 *
	 * @return void
	 */
	public static function createNonWCMockData() {
		global $wpdb;

		// Include db_delta function.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$mail_log_table   = $wpdb->prefix . 'wpml_mails';
		$newsletter_table = $wpdb->prefix . 'newsletter';

		// Create the mail log table.
		\dbDelta(
			"CREATE TABLE IF NOT EXISTS $mail_log_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			data longtext NOT NULL,
			PRIMARY KEY  (id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
		);

		// Create the newsletter table.
		\dbDelta(
			"CREATE TABLE IF NOT EXISTS $newsletter_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			data longtext NOT NULL,
			PRIMARY KEY  (id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
		);

		// Insert data.
		$wpdb->insert( $mail_log_table, array( 'data' => 'Test Mail Log Data' ) );
		$wpdb->insert( $newsletter_table, array( 'data' => 'Test Newsletter Data' ) );
	}

		/**
	 * Generate action scheduler logs and actions.
	 *
	 * @return void
	 */
	public static function generateActionSchedulerLogsAndActions() {
		$action_hooks = array( 'woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry', 'woocommerce_scheduled_subscription_end_of_prepaid_term' );

		global $wpdb;
		foreach ( $action_hooks as $hook ) {
			// Create the action.
			$wpdb->insert(
				"{$wpdb->prefix}actionscheduler_actions",
				array(
					'hook'   => $hook,
					'status' => 'pending',
				),
				array(
					'%s',
					'%s',
				)
			);

			$action_id = $wpdb->insert_id;

			// Add a log.
			$wpdb->insert(
				"{$wpdb->prefix}actionscheduler_logs",
				array(
					'action_id' => $action_id,
					'message'   => 'Test',
				),
				array(
					'%d',
					'%s',
				)
			);
		}
	}

	/**
	 * Creates an admin user.
	 *
	 * @param array $args The user arguments.
	 * @param array $meta The user meta arguments.
	 *
	 * @return integer
	 */
	public static function createAdmin( $args = array(), $meta = array() ) {
		$id = self::createUserWithRole( 'administrator', $args );
		foreach ( $meta as $key => $value ) {
			\update_user_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Creates an editor user.
	 *
	 * @param array $args The user arguments.
	 * @param array $meta The user meta arguments.
	 *
	 * @return integer
	 */
	public static function createEditor( $args = array(), $meta = array() ) {
		$id = self::createUserWithRole( 'editor', $args );
		foreach ( $meta as $key => $value ) {
			\update_user_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Creates an author user.
	 *
	 * @param array $args The user arguments.
	 * @param array $meta The user meta arguments.
	 *
	 * @return integer
	 */
	public static function createAuthor( $args = array(), $meta = array() ) {
		$id = self::createUserWithRole( 'author', $args );
		foreach ( $meta as $key => $value ) {
			\update_user_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Creates a subscriber user.
	 *
	 * @param array $args The user arguments.
	 * @param array $meta The user meta arguments.
	 *
	 * @return integer
	 */
	public static function createSubscriber( $args = array(), $meta = array() ) {
		$id = self::createUserWithRole( 'subscriber', $args );
		foreach ( $meta as $key => $value ) {
			\update_user_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Creates a shop manager user.
	 *
	 * @param array $args The user arguments.
	 * @param array $meta The user meta arguments.
	 *
	 * @return integer
	 */
	public static function createShopManager( $args = array(), $meta = array() ) {
		$id = self::createUserWithRole( 'shop_manager', $args );
		foreach ( $meta as $key => $value ) {
			\update_user_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Creates a shop worker user.
	 *
	 * @param array $args The user arguments.
	 * @param array $meta The user meta arguments.
	 *
	 * @return integer
	 */
	public static function createShopWorker( $args = array(), $meta = array() ) {
		$id = self::createUserWithRole( 'shop_worker', $args );
		foreach ( $meta as $key => $value ) {
			\update_user_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Create a customer
	 *
	 * @param array $args The customer arguments.
	 *
	 * @return integer
	 */
	public static function createCustomer( $args = array() ) {
		return self::createUserWithRole( 'customer', $args );
	}

	/**
	 * Creates a user with a role.
	 *
	 * @param string $role The role to assign to the user.
	 * @param array $args The user arguments.
	 *
	 * @return integer
	 */
	public static function createUserWithRole( $role, $args = array() ) {
		// Default args.
		$defaults = array(
			'user_login' => 'testuser_' . self::uniqueString(),
			'user_pass'  => 'password',
			'user_email' => self::uniqueString() . '@example.com',
			'role'       => $role,
		);

		$args = wp_parse_args( $args, $defaults );

		$user_id = wp_insert_user( $args );

		return $user_id;
	}

	/**
	 * Creates an order.
	 *
	 * @param integer $user     The user to create the order for.
	 * @param array  $products The products to add to the order.
	 *
	 * @return \WC_Order
	 */
	public static function createOrder( int $user, array $products ) {
		$order = new \WC_Order();
		$order->set_customer_id( $user );
		$order->set_status( 'completed' );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
		$order->set_customer_ip_address( '111.111.111.111' );
		foreach ( $products as $key => $product ) {
			$order->add_product( $product, 1 );
		}

		$order->set_payment_method( 'cod' ); // Use 'cod' for Cash on Delivery, 'bacs' for Bank Transfer, etc.
		$order->set_payment_method_title( 'Cash on Delivery' );

		// Calculate totals and save the order
		$order->calculate_totals();
		$order->update_status( 'processing' );
		$order->save();

		return $order;
	}

	/**
	 * Generate legacy order and subscriptions.
	 *
	 * @param integer $count_orders The number of orders to create.
	 * @param integer $count_subs   The number of subscriptions to create.
	 *
	 * @return void
	 */
	public static function generateLegacyOrdersAndSubscriptions( $count_orders = 1, $count_subs = 1 ) {
		// Create orders.
		for ( $i = 0; $i < $count_orders; $i++ ) {
			$id = \wp_insert_post(
				array(
					'post_title'  => 'Test Order ' . self::uniqueString(),
					'post_type'   => 'shop_order',
					'post_status' => 'wc-completed',
				)
			);
			\update_post_meta( $id, 'mock_data', \date( 'Y-m-d H:i:s' ) );
		}

		// Create subscriptions.
		for ( $i = 0; $i < $count_subs; $i++ ) {
			$id = \wp_insert_post(
				array(
					'post_title'  => 'Test Subscription ' . self::uniqueString(),
					'post_type'   => 'shop_subscription',
					'post_status' => 'wc-completed',
				)
			);

			\update_post_meta( $id, 'mock_data', \date( 'Y-m-d H:i:s' ) );
		}
	}

	/**
	 * Generate order notes.
	 *
	 * @param \WC_Order $order The order to generate the notes for.
	 *
	 * @return void
	 */
	public static function generateOrderNotes( \WC_Order $order ) {

		// Create the comment with a type of 'order_note'
		\wp_insert_comment(
			array(
				'comment_post_ID'      => $order->get_id(),
				'comment_content'      => 'Test Order Note',
				'comment_type'         => 'order_note',
				'comment_author'       => 'admin',
				'comment_author_email' => $order->get_billing_email(),
				'comment_approved'     => 1,
				'comment_agent'        => 'WooCommerce',
				'user_id'              => $order->get_customer_id(),
			)
		);
	}

	/**
	 * Generates a payment token.
	 *
	 * @param \WC_Order $order The order to generate the token for.
	 *
	 * @return void
	 */
	public static function generatePaymentToken( \WC_Order $order ) {
		$gateway_id = 'stripe';
		$token      = 'tok_' . self::uniqueString();
		$user_id    = $order->get_customer_id();
		$type       = 'CC';
		$is_default = 0;

		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}woocommerce_payment_tokens",
			array(
				'gateway_id' => $gateway_id,
				'token'      => $token,
				'user_id'    => $user_id,
				'type'       => $type,
				'is_default' => $is_default,
			),
			array(
				'%s',
				'%s',
				'%d',
				'%s',
				'%d',
			)
		);

		// Get the last entry.
		$last_token_id = $wpdb->get_var( 'SELECT token_id FROM ' . $wpdb->prefix . 'woocommerce_payment_tokens ORDER BY token_id DESC LIMIT 1' );

		// Generate meta.
		$wpdb->insert(
			"{$wpdb->prefix}woocommerce_payment_tokenmeta",
			array(
				'payment_token_id' => $last_token_id,
				'meta_key'         => 'last4',
				'meta_value'       => '4242',
			),
			array(
				'%d',
				'%s',
				'%s',
			)
		);

		$wpdb->insert(
			"{$wpdb->prefix}woocommerce_payment_tokenmeta",
			array(
				'payment_token_id' => $last_token_id,
				'meta_key'         => 'expiry_year',
				'meta_value'       => '2022',
			),
			array(
				'%d',
				'%s',
				'%s',
			)
		);
	}

	/**
	 * Generates the order product lookup.
	 *
	 * @param \WC_Order $order The order to generate the lookup for.
	 *
	 * @return void
	 */
	public static function generateOrderProductLookup( \WC_Order $order ) {
		global $wpdb;
		// Compile the items.
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$item_id      = $item->get_id();
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$qty          = $item->get_quantity();

			// Insert into line lookup
			$data = array(
				'order_item_id'       => $item_id,
				'order_id'            => $order->get_id(),
				'product_id'          => $product_id,
				'variation_id'        => $variation_id,
				'customer_id'         => $order->get_customer_id(),
				'date_created'        => current_time( 'mysql' ),
				'product_qty'         => $qty,
				'product_net_revenue' => 50.00,
				'coupon_amount'       => 5.00,
			);

			$wpdb->insert( "{$wpdb->prefix}wc_order_product_lookup", $data, array( '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%f', '%f' ) );
		}
	}

	/**
	 * Generates the order status for the order.
	 *
	 * @param \WC_Order $order The order to generate the status for.
	 *
	 * @return void
	 */
	public static function generateOrderStatus( \WC_Order $order ) {
		// Compile the items.
		$order_id           = $order->get_id();
		$parent_id          = $order->get_parent_id();
		$date_created       = $order->get_date_created()->format( 'Y-m-d H:i:s' );
		$date_created_gmt   = $date_created;
		$date_paid          = $order->get_date_paid() ? $order->get_date_paid()->format( 'Y-m-d H:i:s' ) : null;
		$date_complete      = $date_paid;
		$num_items_sold     = $order->get_item_count();
		$total_sales        = $order->get_total();
		$shipping_total     = $order->get_shipping_total();
		$net_total          = $total_sales - $shipping_total;
		$returning_customer = 1;
		$status             = $order->get_status();
		$customer_id        = $order->get_customer_id();

		// Insert into stats lookup
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}wc_order_stats",
			array(
				'order_id'           => $order_id,
				'parent_id'          => $parent_id,
				'date_created'       => $date_created,
				'date_created_gmt'   => $date_created_gmt,
				'date_paid'          => $date_paid,
				'date_completed'     => $date_complete,
				'num_items_sold'     => $num_items_sold,
				'total_sales'        => $total_sales,
				'shipping_total'     => $shipping_total,
				'net_total'          => $net_total,
				'returning_customer' => $returning_customer,
				'status'             => $status,
				'customer_id'        => $customer_id,
			),
			array(
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%f',
				'%f',
				'%f',
				'%d',
				'%s',
				'%d',
			)
		);
	}

	/**
	 * Generate an order based WC Log.
	 *
	 * @param \WC_Order $order The order to generate the log for.
	 */
	public static function generateOrderLog( \WC_Order $order ) {
		$time    = \time();
		$level   = 'info';
		$message = 'Order created';
		$context = array(
			'order_id'    => $order->get_id(),
			'order_key'   => $order->get_order_key(),
			'customer_id' => $order->get_customer_id(),
		);

		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}woocommerce_log",
			array(
				'timestamp' => $time,
				'level'     => $level,
				'message'   => $message,
				'context'   => \wp_json_encode( $context ),
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
			)
		);
	}

	/**
	 * Generates random WC API Keys.
	 *
	 * @param integer $count The number of keys to generate.
	 *
	 * @return void
	 */
	public static function generateApiKeys( $count = 1 ) {

		global $wpdb;

		for ( $i = 0; $i < $count; $i++ ) {
			$user_id         = \get_current_user_id();
			$description     = 'Test API Key';
			$permissions     = 'read_write';
			$consumer_key    = \wp_generate_password( 32, false );
			$consumer_secret = \wp_generate_password( 43, false );
			$nonces          = array();
			$last_access     = \current_time( 'mysql' );
			$truncated_key   = \substr( $consumer_key, 0, 7 );

			$wpdb->insert(
				"{$wpdb->prefix}woocommerce_api_keys",
				array(
					'user_id'         => $user_id,
					'description'     => $description,
					'permissions'     => $permissions,
					'consumer_key'    => $consumer_key,
					'consumer_secret' => $consumer_secret,
					'nonces'          => \wp_json_encode( $nonces ),
					'last_access'     => $last_access,
					'truncated_key'   => $truncated_key,
				),
				array(
					'%d',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
				)
			);
		}
	}

	/**
	 * Get products
	 *
	 * @param integer $count The number of products to create.
	 *
	 * @return array<int \WC_Product>
	 */
	public static function getProducts( $count = 1 ): array {
		// Try to find the amount of products.
		$products = \wc_get_products( array( 'limit' => $count ) );

		// If we have less products than we need, create the rest.
		if ( count( $products ) < $count ) {
			$products_to_create = $count - count( $products );
			for ( $i = 0; $i < $products_to_create; $i++ ) {
				$products[] = self::createProduct();
			}
		}

		return $products;
	}

	/**
	 * Create random product
	 *
	 * @return \WC_Product
	 */
	public static function createProduct() {
		$product = new \WC_Product();
		$product->set_name( 'Test Product' );
		$product->set_regular_price( 10 );
		$product->set_price( 10 );
		$product->set_sku( 'test-product' . self::uniqueString() );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_description( 'This is a test product' );
		$product->set_short_description( 'This is a test product' );
		$product->save();
		return $product;
	}

	/**
	 * Generate memberships and meta.
	 *
	 * @param integer $count The number of memberships to create.
	 *
	 * @return void
	 */
	public static function generateMemberships( $count = 1 ) {
		// Create memberships.
		for ( $i = 0; $i < $count; $i++ ) {
			$id = \wp_insert_post(
				array(
					'post_title'  => 'Test Membership ' . self::uniqueString(),
					'post_type'   => 'wc_user_membership',
					'post_status' => 'wc-active',
				)
			);
			\update_post_meta( $id, 'mock_data', \date( 'Y-m-d H:i:s' ) );
			\update_post_meta( $id, 'more_data', \date( 'Y-m-d H:i:s' ) );
		}
	}

	/**
	 * Generate webhooks.
	 *
	 * @param integer $count The number of webhooks to create.
	 *
	 * @return void
	 */
	public static function generateWebHooks( $count = 1 ) {
		// Create webhooks.
		for ( $i = 0; $i < $count; $i++ ) {
			$webhook = new \WC_Webhook();
			$webhook->set_name( 'Test Webhook ' . self::uniqueString() );
			$webhook->set_status( 'active' );
			$webhook->save();
		}
	}

	/**
	 * Creates a post for a given user.
	 *
	 * @param integer $user_id The user ID to create the post for.
	 * @param array $meta The post meta to add.
	 *
	 * @return integer
	 */
	public static function createPost( $user_id, $user_meta = array() ) {
		$post_id = \wp_insert_post(
			array(
				'post_title'   => 'Test Post ' . self::uniqueString(),
				'post_content' => 'This is a test post',
				'post_status'  => 'publish',
				'post_author'  => $user_id,
			)
		);

		foreach ( $user_meta as $key => $value ) {
			\update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}
}
