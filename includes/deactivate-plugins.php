<?php

namespace SafetyNet\DeactivatePlugins;

use function SafetyNet\Utilities\get_denylist_array;

add_action( 'safety_net_deactivate_plugins', __NAMESPACE__ . '\deactivate_plugins' );
add_action( 'safety_net_scrub_options', __NAMESPACE__ . '\scrub_options' );

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

/*
* Clear options such as API keys so that plugins won't talk to 3rd parties
*/
function scrub_options() {

	update_option( 'admin_email', 'safetynet@scrubbedthis.option' );

	$options_to_clear = get_denylist_array( 'options' );
	$options_to_clear = apply_filters( 'safety_net_options_to_clear', $options_to_clear );

	foreach ( $options_to_clear as $option ) {
		$option_value = get_option( $option );
		if ( $option_value ) {

			update_option( $option . '_backup', $option_value );

			if ( 'woocommerce_ppcp-gateway_settings' === $option || 'woocommerce-ppcp-settings' === $option || 'woocommerce_stripe_settings' === $option ) {
				// we need to more selectively wipe parts of these options, because the respective plugins will fatal if the entire options are blank
				$keys_to_scrub = array( 'enabled', 'client_secret_production', 'client_id_production', 'client_secret', 'client_id', 'merchant_id', 'merchant_email', 'merchant_id_production', 'merchant_email_production', 'publishable_key', 'secret_key', 'webhook_secret' );
				$option_array  = $option_value;
				foreach ( $keys_to_scrub as $key ) {
					if ( array_key_exists( $key, $option_array ) ) {
						$option_array[ $key ] = '';
					}
				}
				update_option( $option, $option_array );
			} elseif ( 'jetpack_active_modules' === $option ) {
				// Clear some Jetpack options to disable specific modules.
				$modules_to_disable = array( 'publicize', 'subscriptions' );
				$modules_array      = array_filter(
					$option_value,
					function( $v ) use ( $modules_to_disable ) {
						return ! in_array( $v, $modules_to_disable, true );
					},
				);

				update_option( $option, $modules_array );
			} elseif ( 'wprus' === $option ) {
				// Clear some WP Remote Users Sync options to disable only keys needed for remote connections and keep the remaining settings intact.
				$keys_to_scrub = array(
					'encryption' => array(
						'aes_key',
						'hmac_key',
					),
				);
				$option_array  = $option_value;
				foreach ( $keys_to_scrub as $index => $keys ) {
					if ( array_key_exists( $index, $option_array ) ) {
						foreach ( $keys as $key ) {
							if ( array_key_exists( $key, $option_array[ $index ] ) ) {
								$option_array[ $index ][ $key ] = '';
							}
						}
					}
				}
				update_option( $option, $option_array );
			} else {
				// Some plugins don't like it when options are deleted, so we will save their value as either an empty string or array, depending on which it already is.
				if ( is_array( get_option( $option ) ) ) {
					$empty_array = array();
					update_option( $option, $empty_array );
				} else {
					update_option( $option, '' );
				}
			}
		}
	}

	update_option( 'safety_net_options_scrubbed', true );
}
