<?php

namespace Anonymizer\Utilities;

/**
 * Returns an array of up to 1,000 real users from the database.
 *
 * @param int $offset The amount to offset.
 *
 * @return array
 */
function get_users( int $offset = 0 ): array {
	global $wpdb;

	return $wpdb->get_results(
		$wpdb->prepare( "SELECT ID, user_login FROM {$wpdb->users} LIMIT 1000 OFFSET %d", $offset ), ARRAY_A
	);
}

/**
 * Return an array of user IDs of WooCommerce customers.
 *
 * @return array
 */
function get_customer_user_ids(): array {
	global $wpdb;

	return $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value > 0" );
}

/**
 * Returns an array of up to 1,000 WooCommerce orders from the database.
 *
 * @param int $offset The amount to offset.
 *
 * @return array
 */
function get_orders( int $offset = 0 ): array {
	global $wpdb;

	return $wpdb->get_results(
		$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' LIMIT 1000 OFFSET %d", $offset ), ARRAY_A
	);
}

/**
 * Returns an array of up to 1,000 WooCommerce customers from the database.
 *
 * @param int $offset The amount to offset.
 *
 * @return array
 */
function get_customers( int $offset = 0 ): array {
	global $wpdb;

	return $wpdb->get_results(
		$wpdb->prepare( "SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup LIMIT 1000 OFFSET %d", $offset ), ARRAY_A
	);
}