<?php

namespace SafetyNet\Delete;

/**
 * Deletes all users and their data, except administrators.
 *
 * @return void
 */
function delete_users() {
	global $wpdb;

	$users = $wpdb->get_results("SELECT ID FROM $wpdb->users ORDER BY ID");

	foreach ( $users as $user ) {
		// Skip administrators.
		if ( user_can( $user->ID, 'administrator' ) ) {
			continue;
		}

		// First, reassign any posts to an admin
		reassign_posts( $user->ID);

		// Get all of their meta and delete it
		delete_all_users_meta( $user->ID );

		// Delete the user
		$wpdb->delete( $wpdb->users, [ 'ID' => $user->ID ] );
	}
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