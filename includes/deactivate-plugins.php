<?php

namespace SafetyNet\DeactivatePlugins;

use function SafetyNet\Utilities\get_denylist_array;

add_action( 'safety_net_deactivate_plugins', __NAMESPACE__ . '\deactivate_plugins' );

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

	// let's tack on all the Woo payment methods, in case we can deactivate any of those too
	if ( class_exists( 'woocommerce' ) ) {
		$installed_payment_methods = array_keys( WC()->payment_gateways->payment_gateways() );
		foreach ( $installed_payment_methods as $key => $installed_payment_method ) {
			$installed_payment_method = str_replace( '_', '-', $installed_payment_method );
			$denylisted_plugins[]     = $installed_payment_method;
		}
	}

	foreach ( $all_installed_plugins as $key => $installed_plugin ) {

		if ( stristr( $installed_plugin, 'safety-net' ) ) {
			continue;
		}

		foreach ( $denylisted_plugins as $denylisted_plugin ) {

			// denylist can be partial matches, i.e. 'paypal' will match with any plugin that has 'paypal' in the slug
			if ( stristr( $installed_plugin, $denylisted_plugin ) ) {

				// remove plugin silently from active plugins list without triggering hooks
				$current = get_option( 'active_plugins', array() );
				// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
				$key = array_search( $installed_plugin, $current );
				if ( false !== $key ) {
					array_splice( $current, $key, 1 );
				}
				update_option( 'active_plugins', $current );
				break; // break out of nested loop once plugin has been deactivated

			}
		}
	}

	update_option( 'safety_net_plugins_deactivated', true );
}