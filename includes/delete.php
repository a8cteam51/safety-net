<?php

namespace SafetyNet\Delete;

use function SafetyNet\Integrations\get_integrations;
use function SafetyNet\Integrations\run_phase;
use function SafetyNet\Utilities\get_admin_user_ids;

add_action( 'safety_net_delete_data', __NAMESPACE__ . '\delete_users_and_orders' );

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
	$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription' ) )" );
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type IN ( 'shop_order', 'shop_order_refund', 'shop_order_placehold' )" );
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'shop_subscription'" );

	// Delete Woo memberships
	$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wc_user_membership' )" );
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'wc_user_membership'" );

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

	// Delete Woo webhooks
	$table_name = $wpdb->prefix . 'wc_webhooks';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_webhooks" );
	}

	// Delete Woo payment tokens
	$table_name = $wpdb->prefix . 'woocommerce_payment_tokens';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_payment_tokens" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_payment_tokenmeta" );
	}

	// Delete Woo customers and analytics
	$table_name = $wpdb->prefix . 'wc_customer_lookup';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_customer_lookup" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_order_product_lookup" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_order_stats" );
	}

	foreach ( array( 'woocommerce_sessions', 'woocommerce_downloadable_product_permissions', 'wc_download_log' ) as $wc_table ) {
		$wc_full_table = $wpdb->prefix . $wc_table;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wc_full_table ) ) === $wc_full_table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$wc_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from hardcoded allow-list above.
		}
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

    // Delete Newsletter plugin subscribers
    $table_name = $wpdb->prefix . 'newsletter';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}newsletter" );
    }

	// Delete Give plugin data
	if ( ! defined( 'SAFETY_NET_SKIP_GIVEWP' ) || SAFETY_NET_SKIP_GIVEWP !== true ) {
		$table_name = $wpdb->prefix . 'give_donors';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}give_donors" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}give_donormeta" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}give_donationmeta" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}give_comments" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}give_commentmeta" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}give_sessions" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}give_subscriptions" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}give_subscriptionmeta" );
		}

		// Delete Give payment and donation posts
		$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'give_payment' )" );
		$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'give_payment'" );
	}

	// Delete PMPro data
	$table_name = $wpdb->prefix . 'pmpro_membership_orders';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}pmpro_membership_orders" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}pmpro_membership_ordermeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}pmpro_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}pmpro_subscriptionmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}pmpro_memberships_users" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}pmpro_discount_codes_uses" );

		$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key = 'pmpro_stripe_customerid'" );
		$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key LIKE 'pmpro_b%'" );
	}

	// Delete BuddyPress data. Its tables are network-wide on multisite; this is bp_core_get_table_prefix(), which may not be loaded yet.
	$bp_prefix  = apply_filters( 'bp_core_get_table_prefix', $wpdb->base_prefix );
	$table_name = $bp_prefix . 'bp_xprofile_data';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$bp_prefix}bp_xprofile_data" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table prefix from BuddyPress or $wpdb.

		// Signups is a network-wide table on multisite, and BuddyPress uses the same name on single sites.
		$table_name = $wpdb->base_prefix . 'signups';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			$wpdb->query( "DELETE FROM {$wpdb->base_prefix}signups" );
		}

		$table_name = $bp_prefix . 'bp_friends';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			$wpdb->query( "DELETE FROM {$bp_prefix}bp_friends" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table prefix from BuddyPress or $wpdb.
			$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key = 'total_friend_count'" );
		}

		foreach ( array( 'bp_messages_messages', 'bp_messages_recipients', 'bp_messages_notices', 'bp_messages_meta' ) as $bp_table ) {
			$bp_full_table = $bp_prefix . $bp_table;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bp_full_table ) ) === $bp_full_table ) {
				$wpdb->query( "DELETE FROM {$bp_full_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from hardcoded allow-list above.
			}
		}

		$table_name = $bp_prefix . 'bp_notifications';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			$wpdb->query( "DELETE FROM {$bp_prefix}bp_notifications" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table prefix from BuddyPress or $wpdb.
			$wpdb->query( "DELETE FROM {$bp_prefix}bp_notifications_meta" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table prefix from BuddyPress or $wpdb.
		}
	}

	// Delete Jetpack CRM (Zero BS CRM) contacts and related PII
	$table_name = $wpdb->prefix . 'zbs_contacts';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$zbs_tables = array(
			'zbs_contacts',
			'zbs_contactmeta',
			'zbs_companies',
			'zbs_companymeta',
			'zbs_quotes',
			'zbs_quotemeta',
			'zbs_invoices',
			'zbs_invoicemeta',
			'zbs_transactions',
			'zbs_transactionmeta',
			'zbs_lineitems',
			'zbs_events',
			'zbs_eventmeta',
			'zbs_logs',
			'zbs_mail',
			'zbs_lists',
			'zbs_tags',
			'zbs_tagmeta',
			'zbs_aliases',
			'zbs_objlinks',
		);
		foreach ( $zbs_tables as $zbs_table ) {
			$zbs_full_table = $wpdb->prefix . $zbs_table;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $zbs_full_table ) ) === $zbs_full_table ) {
				$wpdb->query( "DELETE FROM {$wpdb->prefix}{$zbs_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from hardcoded allow-list above.
			}
		}
	}

	// Delete WPForms entries (may contain submitted PII)
	$table_name = $wpdb->prefix . 'wpforms_entries';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wpforms_entries" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wpforms_entry_meta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wpforms_entry_fields" );
	}

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

	// Admins keep their user meta, so their cached subscription IDs would point at deleted subscriptions.
	$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->usermeta WHERE meta_key LIKE %s", $wpdb->esc_like( '_wcs_subscription_ids_cache' ) . '%' ) );

	// Set option so this function doesn't run again.
	update_option( 'safety_net_data_deleted', true );

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
