<?php

namespace SafetyNet\Utilities;

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
		$wpdb->prepare( "SELECT ID, user_login FROM {$wpdb->users} LIMIT 1000 OFFSET %d", $offset ),
		ARRAY_A
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

function get_admin_user_ids(): array {
	global $wpdb;

	return $wpdb->get_col( "SELECT u.ID FROM $wpdb->users u INNER JOIN $wpdb->usermeta m ON m.user_id = u.ID WHERE m.meta_key = 'wp_capabilities' AND m.meta_value LIKE '%administrator%' ORDER BY u.user_registered" );
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
		$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' OR post_type = 'shop_subscription' LIMIT 1000 OFFSET %d", $offset ),
		ARRAY_A
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
		$wpdb->prepare( "SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup LIMIT 1000 OFFSET %d", $offset ),
		ARRAY_A
	);
}

/**
 * Returns true if plugin is running on production
 *
 * @return bool
 */
function is_production() {
	// If we're not on staging, development, or a local environment, return true.
	if ( ! in_array( wp_get_environment_type(), array( 'staging', 'development', 'local' ), true ) ) {
		return true;
	}
}

/**
 * Reads the plugin or options denylist txt files, and returns an array for use
 *
 * @param string accepts options|plugins
 *
 * @return array
 */
function get_denylist_array( $denylist_type ) {

	$denylist_array = array();

	if ( 'options' === $denylist_type ) {
		$filename = 'option_scrublist.txt';
	} elseif ( 'plugins' === $denylist_type ) {
		$filename = 'plugin_denylist.txt';
	}

	$row = 1;
	if ( ( $handle = fopen( WP_PLUGIN_DIR . '/safety-net/assets/data/' . $filename, 'r' ) ) !== false ) {
		while ( ( $data = fgetcsv( $handle, 1000 ) ) !== false ) {
			$num = count( $data );
			$row++;
			for ( $c = 0; $c < $num; $c++ ) {
				$denylist_array[] = $data[ $c ];
			}
		}
		fclose( $handle );
	}
	return $denylist_array;
}
