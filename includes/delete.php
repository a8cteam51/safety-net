<?php

namespace SafetyNet\Delete;

use function SafetyNet\Integrations\get_integrations;
use function SafetyNet\Integrations\run_phase;
use function SafetyNet\ScrubOptions\delete_option_directly;
use function SafetyNet\Utilities\get_admin_user_ids;
use function SafetyNet\Utilities\get_keep_config;

add_action( 'safety_net_delete_data', __NAMESPACE__ . '\delete_users_and_orders' );
add_action( 'safety_net_keep_data', __NAMESPACE__ . '\keep_data' );

/**
 * Deletes all users and their data, except administrators.
 *
 * Also deletes WooCommerce and GiveWP data, such as orders and subscriptions.
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

	delete_integration_data();
	run_phase( 'delete' );

	// Reassigning all posts to the first admin user
	reassign_all_posts();

	$admins = get_admin_user_ids(); // returns an array of ids

	// Delete all non-admin users and their user meta, but never every user when no admin can be found.
	if ( $admins ) {
		$placeholders = implode( ',', array_fill( 0, count( $admins ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->usermeta WHERE user_id NOT IN ($placeholders)", ...$admins ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->users WHERE ID NOT IN ($placeholders)", ...$admins ) ); // phpcs:ignore
	} else {
		error_log( 'Safety Net: no administrators found, so users were not deleted.' ); // phpcs:ignore -- Logging is okay here.
	}

	// Set option so this function doesn't run again.
	update_option( 'safety_net_data_deleted', true );

	wp_cache_flush();
}

/**
 * Runs instead of the delete step while SAFETY_NET_DELETE_DATA is false: keeps users, orders and subscriptions but removes the scrubbed options' backups and whatever the integrations' keep phase removes.
 *
 * @return void
 */
function keep_data() {
	global $wpdb;

	// Backups hold the live credentials the scrub removed, which must not be restorable next to real customer data.
	foreach ( $wpdb->get_col( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%\\_sn\\_backup'" ) as $option ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		delete_option_directly( $option );
	}

	run_phase( 'keep' );

	update_option( 'safety_net_data_kept', true );

	$until = get_keep_config()['until'];
	error_log( sprintf( 'Safety Net: users, orders and subscriptions are kept on site %d because SAFETY_NET_DELETE_DATA is false (%s).', get_current_blog_id(), $until ? "until $until" : 'no expiry date' ) ); // phpcs:ignore -- Logging is okay here.

	wp_cache_flush();
}

/**
 * Deletes the data every integration declares: its tables, posts, comments, user meta, scheduled actions and uploaded files.
 *
 * @return void
 */
function delete_integration_data() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct access bypasses the plugins, which may not be loaded; table names come from $wpdb and the integrations.
	foreach ( get_integrations() as $integration ) {
		$tables = array_merge(
			array_map( fn( $table ) => $wpdb->prefix . $table, $integration->tables ),
			array_map( fn( $table ) => $wpdb->base_prefix . $table, $integration->network_tables )
		);
		foreach ( $tables as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
				$wpdb->query( "DELETE FROM {$table}" );
			}
		}

		foreach ( $integration->post_types as $post_type ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = %s )", $post_type ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE post_type = %s", $post_type ) );
		}

		foreach ( $integration->comment_types as $comment_type ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->comments WHERE comment_type = %s", $comment_type ) );
		}

		foreach ( $integration->usermeta as $pattern ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->usermeta WHERE meta_key LIKE %s", $pattern ) );
		}

		foreach ( $integration->action_scheduler_hooks as $hook ) {
			$table_name = $wpdb->prefix . 'actionscheduler_logs';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
				$wpdb->query( $wpdb->prepare( "DELETE lg FROM {$wpdb->prefix}actionscheduler_logs lg LEFT JOIN {$wpdb->prefix}actionscheduler_actions aa ON aa.action_id = lg.action_id WHERE aa.hook = %s", $hook ) );
			}
			$table_name = $wpdb->prefix . 'actionscheduler_actions';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s", $hook ) );
			}
		}

		foreach ( $integration->upload_globs as $pattern ) {
			$uploads = wp_upload_dir( null, false )['basedir'];
			// glob() would read brackets or asterisks in the uploads path as a pattern and find nothing; Windows paths can't be escaped.
			$base  = '/' === DIRECTORY_SEPARATOR ? addcslashes( $uploads, '\\*?[]' ) : $uploads;
			$files = glob( $base . '/' . $pattern );
			foreach ( is_array( $files ) ? $files : array() as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file_from_directory( $file, $uploads );
				}
			}
		}
	}
	// phpcs:enable
}

/**
 * Reassigns all posts to an admin.
 *
 * @return void
 */
function reassign_all_posts() {
	global $wpdb;

	$admin_id = get_admin_id();
	if ( ! $admin_id ) {
		return;
	}

	$wpdb->get_results( $wpdb->prepare( "UPDATE $wpdb->posts SET post_author = %d", $admin_id ) );

	wp_cache_flush();
}

/**
 * Returns an admin ID that posts can be reassigned to, or 0 if there is none.
 *
 * @return int|string
 */
function get_admin_id() {
	global $wpdb;

	// Not get_users(): user queries must not run before plugins_loaded, when the automatic run fires.
	$admin = $wpdb->get_col( $wpdb->prepare( "SELECT u.ID FROM $wpdb->users u INNER JOIN $wpdb->usermeta m ON m.user_id = u.ID WHERE m.meta_key = %s AND m.meta_value LIKE %s ORDER BY u.ID LIMIT 1", $wpdb->prefix . 'capabilities', '%' . $wpdb->esc_like( '"administrator"' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// A network site can have no administrator of its own, only super admins.
	if ( empty( $admin ) ) {
		$admin = get_admin_user_ids();
	}

	return $admin[0] ?? 0;
}
