<?php

namespace SafetyNet\DisableWebhooks;

add_action( 'safety_net_disable_webhooks', __NAMESPACE__ . '\disable_webhooks' );

/**
 * Disables all WooCommerce webhooks.
 *
 * @return void
 */
function disable_webhooks() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'wc_webhooks';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "UPDATE {$wpdb->prefix}wc_webhooks SET status = 'disabled'" );
	}

	// Set option so this function doesn't run again.
	update_option( 'safety_net_webhooks_disabled', true );

	wp_cache_flush();
}
