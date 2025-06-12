<?php

namespace SafetyNet\DisableWebhooks;

add_action( 'safety_net_disable_webhooks', __NAMESPACE__ . '\disable_webhooks' );

/*
* Deactivate plugins from a denylist
*/
function disable_webhooks() {
	global $wpdb;

	// Delete all transients
	$wpdb->query( "UPDATE {$wpdb->prefix}wc_webhooks SET status = 'disabled'" );

	// Set option so this function doesn't run again.
	update_option( 'safety_net_webhooks_disabled', true );

	wp_cache_flush();
}

/**
 * Stops PMPro from registering its cron jobs.
 */
add_filter( 'pre_get_ready_cron_jobs', static function ( $cron_jobs ) {
	add_filter( 'pmpro_registered_crons', '__return_empty_array', PHP_INT_MAX );
	return $cron_jobs;
}, 0 );
