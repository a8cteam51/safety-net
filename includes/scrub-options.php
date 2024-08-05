<?php

namespace SafetyNet\ScrubOptions;
use WC_Data_Store;
use WC_Webhook;

use function SafetyNet\Utilities\get_denylist_array;

add_action( 'safety_net_scrub_options', __NAMESPACE__ . '\scrub_options' );

/*
* Clear options such as API keys so that plugins won't talk to 3rd parties
*/
function scrub_options() {

	safety_net_update_option_direct( 'admin_email', 'safetynet@scrubbedthis.option' );

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
				safety_net_update_option_direct( $option, $option_array );
			} elseif ( 'jetpack_active_modules' === $option ) {
				// Clear some Jetpack options to disable specific modules.
				$modules_to_disable = array( 'enhanced-distribution', 'publicize', 'subscriptions' );
				$modules_array      = array_filter(
					$option_value,
					function( $v ) use ( $modules_to_disable ) {
						return ! in_array( $v, $modules_to_disable, true );
					},
				);

				safety_net_update_option_direct( $option, $modules_array );
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
				safety_net_update_option_direct( $option, $option_array );
			} else {
				// Some plugins don't like it when options are deleted, so we will save their value as either an empty string or array, depending on which it already is.
				if ( is_array( get_option( $option ) ) ) {
					$empty_array = array();
					safety_net_update_option_direct( $option, $empty_array );
				} else {
					safety_net_update_option_direct( $option, '' );
				}
			}
		}
	}

	// Disable all Woo Webhooks
	if ( class_exists( 'WooCommerce' ) ) {
		$data_store = WC_Data_Store::load( 'webhook' );
		$webhooks   = $data_store->search_webhooks();

		if ( ! empty( $webhooks ) ) {
			foreach ( $webhooks as $webhook_id ) {
				$webhook = new WC_Webhook( $webhook_id );
				$webhook->set_status( 'disabled' );
				$webhook->save();
			}
		}
	}

	update_option( 'safety_net_options_scrubbed', true );

	// Clear object cache since the updates happen directly in the database.
	wp_cache_flush();
}

/**
 * Remove some options from scrubbing that are needed on WordPress.com.
 *
 * @param array $options_to_clear Options to clear.
 *
 * @return array
 */
function safety_net_scrub_options_wpcom( $options_to_clear ) {
	if ( defined( 'IS_WPCOM' ) && IS_WPCOM ) {
		$unset_wpcom_options = array( 'jetpack_private_options', 'jetpack_secrets', 'jetpack_active_modules' );
		$options_to_clear = array_diff( $options_to_clear, $unset_wpcom_options );
	}

	return $options_to_clear;
}
add_filter( 'safety_net_options_to_clear', __NAMESPACE__ . '\safety_net_scrub_options_wpcom' );

/**
 * Updates options directly in the database to prevent notifications from being sent.
 * 
 * @param string $option_name The name of the option to update.
 * @param mixed $option_value The value to set the option to.
 * 
 * @return void
 */
function safety_net_update_option_direct( $option_name, $option_value ) {
	global $wpdb;

	if ( is_array( $option_value ) ) {
		$option_value = serialize( $option_value );
	}

	$wpdb->update(
		$wpdb->options,
		array( 'option_value' => $option_value ),
		array( 'option_name' => $option_name ),
	);
}