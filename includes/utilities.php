<?php

namespace SafetyNet\Utilities;

/**
 * Return an array of user IDs of site admins.
 *
 * @return array
 */
function get_admin_user_ids(): array {
	global $wpdb;

	return $wpdb->get_col( "SELECT u.ID FROM $wpdb->users u INNER JOIN $wpdb->usermeta m ON m.user_id = u.ID WHERE m.meta_key = '{$wpdb->prefix}capabilities' AND m.meta_value LIKE '%administrator%' ORDER BY u.user_registered" );
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
	if ( ( $handle = fopen( SAFETY_NET_URL . '/assets/data/' . $filename, 'r' ) ) !== false ) {
		while ( ( $data = fgetcsv( $handle, 1000 ) ) !== false ) {
			$num = count( $data );
			$row++;
			for ( $c = 0; $c < $num; $c++ ) {
				$denylist_array[] = trim( $data[ $c ] );
			}
		}
		fclose( $handle );
	}
	return array_filter( $denylist_array );
}
