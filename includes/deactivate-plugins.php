<?php

namespace SafetyNet\DeactivatePlugins;

use function SafetyNet\Utilities\get_denylist_array;
use function SafetyNet\Utilities\get_payment_gateway_plugins;

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

	$denylisted_plugins = apply_filters( 'safety_net_denylisted_plugins', get_denylist_array( 'plugins' ) );
	$gateway_plugins    = get_payment_gateway_plugins();

	foreach ( $all_installed_plugins as $installed_plugin ) {

		if ( stristr( $installed_plugin, 'safety-net' ) ) {
			continue;
		}

		$should_deactivate = in_array( $installed_plugin, $gateway_plugins, true );

		foreach ( $denylisted_plugins as $denylisted_plugin ) {

			// denylist can be partial matches, i.e. 'paypal' will match with any plugin that has 'paypal' in the slug
			if ( stristr( $installed_plugin, $denylisted_plugin ) ) {
				$should_deactivate = true;
				break;
			}
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
		}
		update_option( 'active_plugins', $current );
	}

	update_option( 'safety_net_plugins_deactivated', true );

	// Gateways can't be traced until WooCommerce has loaded, so leave them for the wp_loaded pass.
	if ( class_exists( 'WooCommerce' ) && did_action( 'plugins_loaded' ) ) {
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

	delete_option( 'safety_net_gateway_plugins_pending' );
	update_option( 'safety_net_gateway_plugins_deactivated', true );
}
