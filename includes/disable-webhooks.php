<?php

namespace SafetyNet\DisableWebhooks;

use function SafetyNet\Utilities\get_denylist_array;

add_action( 'safety_net_disable_webhooks', __NAMESPACE__ . '\disable_webhooks' );

/*
* Deactivate plugins from a denylist
*/
function disable_webhooks() {
	dump( 'calling ' . __FUNCTION__ );
	global $wpdb;

	// Delete all transients
	$wpdb->query( "UPDATE {$wpdb->prefix}wc_webhooks SET status = 'disabled'" );

	// Set option so this function doesn't run again.
	update_option( 'safety_net_webhooks_disabled', true );

	wp_cache_flush();
}