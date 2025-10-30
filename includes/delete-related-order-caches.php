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

	// 2. Delete related order caches
	$related_order_cache_keys = array(
		'_subscription_renewal_order_ids_cache',
		'_subscription_switch_order_ids_cache',
		'_subscription_resubscribe_order_ids_cache',
	);

	// Delete data from the High Performance Order Tables
	$table_name   = $wpdb->prefix . 'wc_orders_meta';
	$hpos_enabled = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

	foreach ( $related_order_cache_keys as $cache_key ) {
		if ( $hpos_enabled ) {
			$wpdb->delete(
				$table_name,
				array( 'meta_key' => $cache_key ),
				array( '%s' )
			);
		} else {
			delete_metadata( 'post', null, $cache_key, '', true );
		}
	}

	// Set option so this function doesn't run again.
	update_option( 'safety_net_caches_deleted', true );

	wp_cache_flush();
}