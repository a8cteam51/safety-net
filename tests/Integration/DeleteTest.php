<?php



namespace Tests\Integration;

use Tests\Support\IntegrationTester;
use Tests\Support\MockFactory;

use function SafetyNet\Delete\delete_users_and_orders;

class DeleteTest extends \Codeception\Test\Unit {


	protected IntegrationTester $tester;

	public function testDeleteOrderAndCustomersShouldFailIfPluginsNotDisabledFirst() {

		// This is a bit hacky, but due to the code having a die(), this has been called.
		// via a standalone script, that just calls this can relays the message.
		ob_start();
		$output = shell_exec( PHP_BINARY . ' ' . __DIR__ . '/Helper/DeleteTestDieHandler.php abspath=' . ABSPATH . ' plugin_base=' . SAFETY_NET_PATH );
		ob_get_clean();

		$this->assertStringContainsString( 'Safety Net Error: plugins need to be deactivated first.', $output );
	}

	/**
	 * @testdox When the data is deleted, all orders, payments, webhooks should be removed.
	 *
	 * @return void
	 */
	public function testDeleteOrdersPaymentsWebHooks() {
		global $wpdb;

		// Ensure the options are set to say plugins deactivated.
		\update_option( 'safety_net_plugins_deactivated', 'yes' );

		// Set to using high capacity tables.
		\update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'yes' );
		\update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );

		// Remove the confirmation option.
		delete_option( 'safety_net_data_deleted' );

		// Creates a customer.
		$customer_id = MockFactory::createCustomer();
		MockFactory::generateApiKeys( 2 );
		$order = MockFactory::createOrder( $customer_id, MockFactory::getProducts( 2 ) );
		MockFactory::generateOrderProductLookup( $order );
		MockFactory::generateOrderStatus( $order );
		MockFactory::generateOrderLog( $order );
		MockFactory::generatePaymentToken( $order );
		MockFactory::generateOrderNotes( $order );
		MockFactory::generateMemberships( 2 );
		MockFactory::generateWebHooks( 2 );

		// Generate legacy orders and subscriptions.]
		MockFactory::generateLegacyOrdersAndSubscriptions( 2, 2 );

		// Handle none WC data.
		MockFactory::createNonWCMockData();
		MockFactory::generateActionSchedulerLogsAndActions();

		// The count queries
		$queries = array(
			'woocommerce_order_itemmeta'      => "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta",
			'woocommerce_order_items'         => "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items",
			'wc_orders'                       => "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders",
			'woocommerce_api_keys'            => "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_api_keys",
			'wc_webhooks'                     => "SELECT COUNT(*) FROM {$wpdb->prefix}wc_webhooks",
			'woocommerce_payment_tokens'      => "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_payment_tokens",
			'woocommerce_payment_tokenmeta'   => "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_payment_tokenmeta",
			'wc_order_addresses'              => "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_addresses",
			'wc_order_operational_data'       => "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_operational_data",
			'wc_orders_meta'                  => "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta",
			'wc_customer_lookup'              => "SELECT COUNT(*) FROM {$wpdb->prefix}wc_customer_lookup",
			'wc_order_product_lookup'         => "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_product_lookup",
			'woocommerce_log'                 => "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_log",
			'wc_order_stats'                  => "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_stats",
			'wc_user_membership_and_sub_meta' => "SELECT COUNT(*) FROM $wpdb->postmeta WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wc_user_membership')",
			'wc_user_membership'              => "SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type = 'wc_user_membership'",
			'legacy_order_and_sub_meta'       => "SELECT COUNT(*) FROM $wpdb->postmeta WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' OR post_type = 'shop_subscription')",
			'legacy_order'                    => "SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type = 'shop_order'",
			'legacy_sub'                      => "SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type = 'shop_subscription'",
			'order_notes'                     => "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_type = 'order_note'",
			'wpml_mails'                      => "SELECT COUNT(*) FROM {$wpdb->prefix}wpml_mails",
			'newsletter'                      => "SELECT COUNT(*) FROM {$wpdb->prefix}newsletter",
			'as_payment_logs'                 => "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs lg LEFT JOIN {$wpdb->prefix}actionscheduler_actions aa ON aa.action_id = lg.action_id WHERE aa.hook IN ('woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry', 'woocommerce_scheduled_subscription_end_of_prepaid_term')",
			'as_payment_actions'              => "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook IN ('woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry', 'woocommerce_scheduled_subscription_end_of_prepaid_term')",
		);

		// Iterate over the queries and check the count.
		$pre_count = array();
		foreach ( $queries as $key => $query ) {
			$pre_count[ $key ] = $wpdb->get_var( $query );
		}

		delete_users_and_orders();

		$post_count = array();
		foreach ( $queries as $key => $query ) {
			$post_count[ $key ] = $wpdb->get_var( $query );
		}

		// Iterate over an check the count.
		foreach ( $post_count as $table => $count ) {
			$this->assertEquals( 0, $count, "Table $table should be empty, value before was: " . $pre_count[ $table ] );
		}

		// Check the confirm option is set.
		$this->assertTrue( (bool) get_option( 'safety_net_data_deleted', false ) );

		// You can check the counts by uncommenting the below and running in CLI
		// dd( $pre_count, $post_count );
	}

	/**
	 * @testdox When the data is deleted, all non admin users should be removed and all posts assigned to intiial admin.
	 *
	 * @return void
	 */
	public function testDeleteUsersAndPosts() {
		global $wpdb;

		// Ensure the options are set to say plugins deactivated.
		\update_option( 'safety_net_plugins_deactivated', 'yes' );

		// Remove the confirmation option.
		delete_option( 'safety_net_data_deleted' );

		// Generate the user.
		$admins        = array( MockFactory::createAdmin(), MockFactory::createAdmin() );
		$editors       = array( MockFactory::createEditor(), MockFactory::createEditor() );
		$authors       = array( MockFactory::createAuthor(), MockFactory::createAuthor() );
		$subscribers   = array( MockFactory::createSubscriber(), MockFactory::createSubscriber() );
		$customers     = array( MockFactory::createCustomer(), MockFactory::createCustomer() );
		$shop_managers = array( MockFactory::createShopManager(), MockFactory::createShopManager() );

		// List of posts.
		$posts = array();

		// Create the posts for editors.
		foreach ( $editors as $editor ) {
			$id      = MockFactory::createPost( $editor, array( 'key' => 'value-' . $editor ) );
			$posts[] = array(
				'id'     => $id,
				'author' => $editor,
			);
		}

		// Create the posts for authors.
		foreach ( $authors as $author ) {
			$id      = MockFactory::createPost( $author, array( 'key' => 'value-' . $author ) );
			$posts[] = array(
				'id'     => $id,
				'author' => $author,
			);
		}

		// Clear all users.
		delete_users_and_orders();

		// Check that all users except admins are removed.
		$users = $editors + $authors + $subscribers + $customers + $shop_managers;
		foreach ( $users as $user ) {
			$this->assertEmpty( get_user_by( 'ID', $user ) );
			$this->assertEmpty( get_user_meta( $user ) );
		}

		// Get the admin posts will be assigned to
		$inital_admin = get_users(
			array(
				'role__in' => array(
					'administrator',
				),
				'fields'   => 'ids',
				'number'   => 1,
			)
		)[0];

		// Iterate over the posts and check the author.
		foreach ( $posts as $post ) {
			$this->assertEquals( $inital_admin, get_post( $post['id'] )->post_author );
		}
	}
}
