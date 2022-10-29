<?php

namespace SafetyNet\Delete;

use function SafetyNet\Utilities\get_admin_user_ids;

add_action( 'safety_net_delete_data', __NAMESPACE__ . '\delete_users_and_orders' );

/**
 * Deletes all users and their data, except administrators.
 *
 * Also deletes orders and subscriptions.
 *
 * @return void
 */
function delete_users_and_orders() {
	global $wpdb;

	// Delete orders, order meta, and subscriptions.

	// check if table exists before purging 
	$table_name = $wpdb->prefix . 'woocommerce_order_itemmeta';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'") == $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta" );
	}
	// check if table exists before purging 
	$table_name = $wpdb->prefix . 'woocommerce_order_items';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'") == $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items" );
	}
	$wpdb->query( "DELETE FROM $wpdb->comments WHERE comment_type = 'order_note'" );
	$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' OR post_type = 'shop_subscription' )" );
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'shop_order'" );
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'shop_subscription'" );

	// Delete renewal scheduled actions
	$table_name = $wpdb->prefix . 'actionscheduler_logs'; // check if table exists before purging 
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'") == $table_name ) {
		$wpdb->query( "DELETE lg FROM {$wpdb->prefix}actionscheduler_logs lg LEFT JOIN {$wpdb->prefix}actionscheduler_actions aa ON aa.action_id = lg.action_id WHERE aa.hook IN ( 'woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry', 'woocommerce_scheduled_subscription_end_of_prepaid_term' )" );
	}
	$table_name = $wpdb->prefix . 'actionscheduler_actions'; // check if table exists before purging 
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'") == $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook IN ( 'woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_retry', 'woocommerce_scheduled_subscription_end_of_prepaid_term' )" );
	}

	// Reassigning all posts to the first admin user
	reassign_all_posts();

	$admins          = get_admin_user_ids();
	$admin_list_string = implode( ",", $admins );

	// Delete all non-admin users and their usermeta
	$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE user_id NOT IN ( $admin_list_string )" );
	$wpdb->query( "DELETE FROM $wpdb->users WHERE ID NOT IN ( $admin_list_string )" );

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
 * Deletes all meta associated with a user.
 *
 * @param int $user_id The ID of the user.
 *
 * @return void
 */
function delete_all_users_meta( $user_id ) {
	global $wpdb;

	$meta = $wpdb->get_col( $wpdb->prepare( "SELECT umeta_id FROM $wpdb->usermeta WHERE user_id = %d", $user_id ) );

	foreach ( $meta as $mid ) {
		delete_metadata_by_mid( 'user', $mid );
	}
}

/**
 * Returns an admin ID that posts can be reassigned to.
 *
 * @return mixed
 */
function get_admin_id() {
	$admin = get_users(
		[
			'role__in' => [
				'administrator'
			],
			'fields' => 'ids',
			'number' => 1
		]
	);

	return $admin[0];
}