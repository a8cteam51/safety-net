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
 * @param string $denylist_type Type of denylist. Accepts 'options' or 'plugins'.
 *
 * @return array
 */
function get_denylist_array( $denylist_type ): array {
	global $wp_filesystem;

	if ( ! $wp_filesystem ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}

	$denylist_array = array();
	$filename       = 'options' === $denylist_type ? 'option_scrublist.txt' : 'plugin_denylist.txt';
	$file_path      = SAFETY_NET_PATH . '/assets/data/' . $filename;

	if ( ! $wp_filesystem->exists( $file_path ) ) {
		return $denylist_array;
	}

	$file_contents = $wp_filesystem->get_contents( $file_path );
	if ( false === $file_contents ) {
		return $denylist_array;
	}

	$rows = explode( "\n", $file_contents );

	foreach ( $rows as $row ) {
		$data = str_getcsv( $row );
		foreach ( $data as $item ) {
			$denylist_array[] = trim( $item );
		}
	}

	return array_filter( $denylist_array );
}
