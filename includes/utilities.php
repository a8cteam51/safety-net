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
 * The function @{wp_get_environment_type()} from WP Core will default to 'production' if the environment type is set
 * to anything other than 'staging', 'development', or 'local'. However, some hosts like Pressable and tools like
 * WPCOM Studio set an unsupported environment type via the constant `WP_ENVIRONMENT_TYPE` (in both cases, `sandbox`).
 *
 * This function tries to reconcile that.
 *
 * @return string
 */
function get_environment_type(): string {
	$current_env = wp_get_environment_type();

	if ( 'production' === $current_env ) { // Either true production or fallback production due to an unsupported environment type.
		$other_supported_envs = array( 'sandbox', 'dev', 'develop' );

		if ( function_exists( 'getenv' ) ) {
			$env = getenv( 'WP_ENVIRONMENT_TYPE' );
			if ( in_array( $env, $other_supported_envs, true ) ) {
				$current_env = $env;
			}
		}

		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && in_array( WP_ENVIRONMENT_TYPE, $other_supported_envs, true ) ) {
			$current_env = WP_ENVIRONMENT_TYPE;
		}
	}

	return $current_env;
}

/**
 * Returns true if plugin is running on production.
 *
 * @return boolean
 */
function is_production() {
	return 'production' === get_environment_type();
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

/**
 * Renders an admin notice, if the plugin is running on production
 *
 * @filter safety_net_show_production_notice
 *
 * @return void
 */
function show_production_notice() {
	// If not production, return.
	if ( ! is_production() ) {
		return;
	}

	// Check the if the user has the capability to manage options.
	$allowed = current_user_can( 'manage_options' );
	// Filter for third-party plugins to add their own capability check.
	$allowed = apply_filters( 'safety_net_show_production_notice', $allowed );

	if ( ! $allowed ) {
		return;
	}

	// Check if the constant starts as an mu plugin.
	$is_mu = defined( 'WPMU_PLUGIN_DIR' ) && \str_starts_with( SAFETY_NET_PATH, WPMU_PLUGIN_DIR );
	?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo esc_html(
					sprintf(
						// translators: %s: Is plugin or mu-plugin.
						__( 'Safety Net is active on a production site, which restricts certain processes from running. To proceed, either remove the %s or switch the site to a staging or development environment.', 'safety-net' ),
						$is_mu ? __( 'mu-plugin', 'safety-net' ) : __( 'plugin', 'safety-net' )
					)
				);
				?>
			</p>
		</div>
		<?php
}
