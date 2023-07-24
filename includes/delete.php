<?php

namespace SafetyNet\Delete;

use function SafetyNet\Utilities\get_admin_user_ids;

add_action( 'safety_net_delete_data', __NAMESPACE__ . '\delete_users_and_orders' );

/**
 * Deletes all users and their data, except administrators.
 *
 * Also deletes WooCommerce data, such as orders and subscriptions.
 *
 * @return void
 */
function delete_users_and_orders() {
	if ( ! get_option( 'safety_net_plugins_deactivated' ) ) {
		echo wp_json_encode(
			array(
				'success' => false,
				'message' => esc_html__( 'Safety Net Error: plugins need to be deactivated first.' ),
			)
		);

		die();
	}

	global $wpdb;

	// Delete orders and subscriptions
	$table_name = $wpdb->prefix . 'woocommerce_order_itemmeta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta" );
	}
	$table_name = $wpdb->prefix . 'woocommerce_order_items';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items" );
	}
	$wpdb->query( "DELETE FROM $wpdb->comments WHERE comment_type = 'order_note'" );
	$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' OR post_type = 'shop_subscription' )" );
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'shop_order'" );
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'shop_subscription'" );

	// Delete data from the High Performance Order Tables
	$table_name = $wpdb->prefix . 'wc_orders';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_orders" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_order_addresses" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_order_operational_data" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_orders_meta" );
	}

	// Delete Woo API keys
	$table_name = $wpdb->prefix . 'woocommerce_api_keys';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_api_keys" );
	}

	// Delete renewal scheduled actions
	$table_name = $wpdb->prefix . 'actionscheduler_logs'; // check if table exists before purging
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE lg FROM {$wpdb->prefix}actionscheduler_logs lg LEFT JOIN {$wpdb->prefix}actionscheduler_actions aa ON aa.action_id = lg.action_id WHERE aa.hook IN ( 'woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry', 'woocommerce_scheduled_subscription_end_of_prepaid_term' )" );
	}
	$table_name = $wpdb->prefix . 'actionscheduler_actions'; // check if table exists before purging
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook IN ( 'woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry', 'woocommerce_scheduled_subscription_end_of_prepaid_term' )" );
	}

	// Delete WP Mail Logging logs
	$table_name = $wpdb->prefix . 'wpml_mails'; // check if table exists before purging
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wpml_mails" );
	}

	// Reassigning all posts to the first admin user
	reassign_all_posts();

	$admins = get_admin_user_ids(); // returns an array of ids

	// Delete all non-admin users and their user meta
	$placeholders = implode( ',', array_fill( 0, count( $admins ), '%d' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->usermeta WHERE user_id NOT IN ($placeholders)", ...$admins ) ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->users WHERE ID NOT IN ($placeholders)", ...$admins ) ); // phpcs:ignore

	// Set option so this function doesn't run again.
	update_option( 'safety_net_data_deleted', true );

	wp_cache_flush();
}

/**
 * Reassigns all posts to an admin.
 *
 * @return void
 */
function reassign_all_posts() {
	global $wpdb;

	$wpdb->get_results( $wpdb->prepare( "UPDATE $wpdb->posts SET post_author = %d", get_admin_id() ) );

	wp_cache_flush();
}

/**
 * Returns an admin ID that posts can be reassigned to.
 *
 * @return mixed
 */
function get_admin_id() {
	$admin = get_users(
		array(
			'role__in' => array(
				'administrator',
			),
			'fields'   => 'ids',
			'number'   => 1,
		)
	);

	return $admin[0];
}
