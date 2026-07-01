<?php

namespace SafetyNet\DeletedRelatedOrderCaches;

add_action( 'safety_net_delete_caches', __NAMESPACE__ . '\delete_all_subscription_caches' );

/**
 * This deletes the related order caches for subscriptions and users.
 *
 * @link https://woocommerce.com/document/subscriptions/develop/cache/#subscription-related-order-cache
 * @link https://woocommerce.com/document/subscriptions/develop/cache/#customer-s-subscription-cache
 *
 * @return void
 */
function delete_all_subscription_caches() {
	/** @var \wpdb $wpdb */
	global $wpdb;

	// 1. Delete customer subscription IDs cache
	$customer_cache_key = '_wcs_subscription_ids_cache';

	// For multisite, append blog ID
	if ( is_multisite() ) {
		$customer_cache_key .= '_' . get_current_blog_id();
	}

	// Delete from wp_usermeta
	delete_metadata( 'user', null, $customer_cache_key, '', true );

	// Check if HPOS tables exists
	$table_name   = $wpdb->prefix . 'wc_orders_meta';
	$hpos_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

	// 2. Delete related order caches
	$related_order_cache_keys = array(
		'_subscription_renewal_order_ids_cache',
		'_subscription_switch_order_ids_cache',
		'_subscription_resubscribe_order_ids_cache',
	);

	$placeholders = implode( ', ', array_fill( 0, count( $related_order_cache_keys ), '%s' ) );

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...$related_order_cache_keys
		)
	);

	if ( $hpos_exists ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE meta_key IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$related_order_cache_keys
			)
		);
	}

	// Set option so this function doesn't run again.
	update_option( 'safety_net_caches_deleted', true );

	wp_cache_flush();
}