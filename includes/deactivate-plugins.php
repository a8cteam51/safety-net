<?php

namespace SafetyNet\DeactivatePlugins;

use function SafetyNet\Integrations\plugin_patterns;
use function SafetyNet\Utilities\get_payment_gateway_plugins;
use function SafetyNet\Utilities\is_denylisted_plugin;
use function SafetyNet\Utilities\should_change_network;

add_action( 'safety_net_deactivate_plugins', __NAMESPACE__ . '\deactivate_plugins' );
add_action( 'safety_net_deactivate_gateway_plugins', __NAMESPACE__ . '\deactivate_gateway_plugins' );

/*
* Deactivate plugins from a denylist
*/
function deactivate_plugins() {

	if ( ! get_option( 'safety_net_options_scrubbed' ) ) {
		echo wp_json_encode(
			array(
				'success' => false,
				'message' => esc_html__( 'Safety Net Error: options need to be scrubbed first.' ),
			)
		);

		die();
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$all_installed_plugins = array_keys( get_plugins() );

	$denylisted_plugins = apply_filters( 'safety_net_denylisted_plugins', plugin_patterns() );
	$gateway_plugins    = get_payment_gateway_plugins();

	$denylisted_matches = array();
	foreach ( $all_installed_plugins as $installed_plugin ) {

		if ( stristr( $installed_plugin, 'safety-net' ) ) {
			continue;
		}

		$should_deactivate = in_array( $installed_plugin, $gateway_plugins, true );

		if ( is_denylisted_plugin( $installed_plugin, $denylisted_plugins ) ) {
			$should_deactivate    = true;
			$denylisted_matches[] = $installed_plugin;
		}

		if ( ! $should_deactivate ) {
			continue;
		}

		// remove plugin silently from active plugins list without triggering hooks
		$current = get_option( 'active_plugins', array() );
		// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
		$key = array_search( $installed_plugin, $current );
		if ( false !== $key ) {
			array_splice( $current, $key, 1 );
			keep_in_jetpack_autoloader( $installed_plugin );
		}
		update_option( 'active_plugins', $current );
	}

	deactivate_network_plugins( $denylisted_matches, 'safety_net_deactivate_plugins', 'safety_net_network_plugins_deactivated' );

	update_option( 'safety_net_plugins_deactivated', true );

	// Gateways can't be traced until WooCommerce has loaded, so leave them for the wp_loaded pass.
	if ( class_exists( 'WooCommerce' ) && did_action( 'plugins_loaded' ) ) {
		deactivate_network_plugins( $gateway_plugins, 'safety_net_deactivate_plugins', 'safety_net_network_gateway_plugins_deactivated' );
		delete_option( 'safety_net_gateway_plugins_pending' );
		update_option( 'safety_net_gateway_plugins_deactivated', true );
	} else {
		update_option( 'safety_net_gateway_plugins_pending', true );
	}
}

/**
 * Deactivates plugins that register a WooCommerce payment gateway, without triggering deactivation hooks.
 *
 * @return void
 */
function deactivate_gateway_plugins() {
	$gateway_plugins = get_payment_gateway_plugins();

	if ( $gateway_plugins ) {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_values( array_diff( $active_plugins, $gateway_plugins ) ) );
	}

	// Without WooCommerce nothing was traced, so the network's gateway pass is still to come.
	if ( class_exists( 'WooCommerce' ) && did_action( 'plugins_loaded' ) ) {
		deactivate_network_plugins( $gateway_plugins, 'safety_net_deactivate_gateway_plugins', 'safety_net_network_gateway_plugins_deactivated' );
	}

	delete_option( 'safety_net_gateway_plugins_pending' );
	update_option( 'safety_net_gateway_plugins_deactivated', true );
}

/**
 * Removes plugins from the network's active plugins on multisite, without triggering deactivation hooks.
 *
 * @param string[] $plugins        Plugin basenames.
 * @param string   $automatic_hook The action that fires the automatic run, which does this once per network.
 * @param string   $flag           The network option that records it was done.
 *
 * @return void
 */
function deactivate_network_plugins( array $plugins, string $automatic_hook, string $flag ) {
	if ( ! should_change_network( $automatic_hook, $flag, 'manage_network_plugins' ) ) {
		return;
	}

	$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
	$remaining       = array_diff_key( $network_plugins, array_flip( $plugins ) );

	if ( count( $remaining ) !== count( $network_plugins ) ) {
		array_map( __NAMESPACE__ . '\keep_in_jetpack_autoloader', array_keys( array_diff_key( $network_plugins, $remaining ) ) );
		update_site_option( 'active_sitewide_plugins', $remaining );
	}

	update_site_option( $flag, true );
}

/**
 * Keeps a plugin deactivated while plugins are loading in the Jetpack Autoloader's class map until the request ends.
 *
 * @param string $plugin Plugin basename.
 *
 * @return void
 */
function keep_in_jetpack_autoloader( string $plugin ) {
	global $jetpack_autoloader_activating_plugins_paths;

	if ( did_action( 'plugins_loaded' ) || '.' === dirname( $plugin ) ) {
		return;
	}

	// The same path the autoloader derives from active_plugins, so its list does not change.
	$file      = wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin );
	$real_path = realpath( $file );
	if ( false !== $real_path && $real_path !== $file ) {
		$file = wp_normalize_path( $real_path );
	}

	// A later plugin's autoloader would rebuild its class map without a plugin that is already running; one not loaded yet never runs.
	if ( ! in_array( $file, array_map( 'wp_normalize_path', get_included_files() ), true ) ) {
		return;
	}

	$directory = dirname( $file );
	if ( ! is_file( $directory . '/vendor/composer/jetpack_autoload_classmap.php' ) ) {
		return;
	}

	if ( ! is_array( $jetpack_autoloader_activating_plugins_paths ) ) {
		$jetpack_autoloader_activating_plugins_paths = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
	if ( ! in_array( $directory, $jetpack_autoloader_activating_plugins_paths, true ) ) {
		$jetpack_autoloader_activating_plugins_paths[] = $directory; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}
