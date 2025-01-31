<?php

namespace SafetyNet\DeleteTransients;

add_action( 'safety_net_delete_transients', __NAMESPACE__ . '\delete_transients' );

/**
 * Deletes all transients.
 *
 * @return void
 */
function delete_transients() {

	global $wpdb;

	// Delete all transients
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_%'" );

	// Set option so this function doesn't run again.
	update_option( 'safety_net_transients_deleted', true );

	wp_cache_flush();
}
