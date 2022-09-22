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
	$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta" );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items" );
	$wpdb->query( "DELETE FROM $wpdb->comments WHERE comment_type = 'order_note'" );
	$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' OR post_type = 'shop_subscription' )" );
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'shop_order'" );
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'shop_subscription'" );

	$users  = $wpdb->get_results( "SELECT ID FROM $wpdb->users ORDER BY ID" );
	$admins = get_admin_user_ids();

	error_log(print_r($admins,1));

	foreach ( $users as $user ) {
		// Skip administrators.
		if ( in_array( $user->ID, $admins, true ) ) {
			continue;
		}

		// First, reassign any posts to an admin.
		reassign_posts( $user->ID );

		// Get all of their meta and delete it.
		delete_all_users_meta( $user->ID );

		// Delete the user.
		$wpdb->delete( $wpdb->users, array( 'ID' => $user->ID ) );
	}

	// Set option so this function doesn't run again.
	update_option( 'safety_net_data_deleted', true );
}

/**
 * Reassigns all of a user's posts to an admin.
 *
 * @param int $user_id The ID of the user.
 *
 * @return void
 */
function reassign_posts( $user_id ) {
	global $wpdb;

	$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_author = %d", $user_id ) );

	$wpdb->update( $wpdb->posts, [ 'post_author' => get_admin_id() ], [ 'post_author' => $user_id ] );

	if ( ! empty( $post_ids ) ) {
		foreach ( $post_ids as $post_id ) {
			clean_post_cache( $post_id );
		}
	}
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