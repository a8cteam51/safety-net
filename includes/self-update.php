<?php
/**
 * Offers GitHub releases of Safety Net as plugin updates.
 *
 * @package SafetyNet
 */

namespace SafetyNet\SelfUpdate;

const RELEASE_TRANSIENT = 'safety_net_github_latest_release';

add_filter( 'update_plugins_github.com', __NAMESPACE__ . '\check_for_update', 10, 3 );

/**
 * Offers the latest GitHub release as a plugin update.
 *
 * @param array|false $update      The plugin update data, or false if none was provided yet.
 * @param array       $plugin_data The plugin headers.
 * @param string      $plugin_file The plugin basename.
 *
 * @return array|false
 */
function check_for_update( $update, array $plugin_data, string $plugin_file ) {
	if ( SAFETY_NET_BASENAME !== $plugin_file || false !== $update ) {
		return $update;
	}

	// The release zip unpacks to safety-net/, so updating any other folder would leave the active plugin entry pointing at a deleted path.
	if ( 'safety-net' !== dirname( $plugin_file ) ) {
		return $update;
	}

	$release = get_latest_release();
	if ( empty( $release ) ) {
		return $update;
	}

	// Core compares versions itself; returning the current release too lists the plugin under no_update, which enables the auto-updates toggle.
	return array(
		'slug'    => 'safety-net',
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	);
}

/**
 * Returns the version, page URL, and zip URL of the latest GitHub release.
 *
 * @return array Empty if the release could not be fetched.
 */
function get_latest_release(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag, checked the same way by core's update-core.php.
	$force_check = ! empty( $_GET['force-check'] );

	$release = get_transient( RELEASE_TRANSIENT );
	if ( is_array( $release ) && ! $force_check ) {
		return $release;
	}

	$release  = array();
	$response = wp_remote_get( 'https://api.github.com/repos/a8cteam51/safety-net/releases/latest' );
	$body     = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 === wp_remote_retrieve_response_code( $response ) && isset( $body['tag_name'], $body['html_url'] ) ) {
		foreach ( $body['assets'] ?? array() as $asset ) {
			if ( 'safety-net.zip' === ( $asset['name'] ?? '' ) ) {
				$release = array(
					'version' => ltrim( $body['tag_name'], 'v' ),
					'url'     => $body['html_url'],
					'package' => $asset['browser_download_url'],
				);
				break;
			}
		}
	}

	set_transient( RELEASE_TRANSIENT, $release, empty( $release ) ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );

	return $release;
}
