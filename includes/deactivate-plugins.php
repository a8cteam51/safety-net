<?php

namespace SafetyNet\DeactivatePlugins;

/*
* Deactivate plugins from a blacklist
*/
function deactivate_plugins() {

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$all_installed_plugins = array_keys( get_plugins() );

	// blacklist can be partial matches, i.e. 'paypal' will match with any plugin that has 'paypal' in the slug
	$blacklisted_plugins = array( 'paypal', 'stripe', 'affirm', 'smtp', 'in-stock-mailer-for-wc', 'klaviyo', 'wp-mail-bank', 'mailchimp', 'mailgun', 'metorik', 'sendinblue', 'wp-sendgrid-mailer', 'socketlabs', 'shipstation', 'wp-console', 'wp-ses', 'algolia', 'zapier' );
	$blacklisted_plugins = apply_filters( 'safety_net_blacklisted_plugins', $blacklisted_plugins );

	// let's tack on all the Woo payment methods, in case we can deactivate any of those too
	if ( class_exists( 'woocommerce' ) ) {
		$installed_payment_methods = array_keys( WC()->payment_gateways->payment_gateways() );
		foreach ( $installed_payment_methods as $key => $installed_payment_method ) {
			$installed_payment_method = str_replace( '_', '-', $installed_payment_method );
			$blacklisted_plugins[]    = $installed_payment_method;
		}
	}

	foreach ( $all_installed_plugins as $key => $installed_plugin ) {

		if ( stristr( $installed_plugin, 'safety-net' ) ) {
			continue;
		}

		foreach ( $blacklisted_plugins as $blacklisted_plugin ) {

			if ( stristr( $installed_plugin, $blacklisted_plugin ) ) {

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

}

/*
* Clear options such as API keys so that plugins won't talk to 3rd parties
*/
function scrub_options() {
	$options_to_clear = array( 'woocommerce_shipstation_auth_key', 'woocommerce_braintree_paypal_settings', 'woocommerce_braintree_credit_card_settings', 'klaviyo_settings', 'klaviyo_api_key', 'woocommerce_stripe_account_settings', 'woocommerce_stripe_api_settings', 'woocommerce_stripe_settings', 'woocommerce_ppcp-gateway_settings', 'woocommerce-ppcp-settings', 'woocommerce_paypal_settings', 'woocommerce_woocommerce_payments_settings' );
	$options_to_clear = apply_filters( 'safety_net_options_to_clear', $options_to_clear );

	foreach ( $options_to_clear as $option ) {
		if ( get_option( $option ) ) {
			update_option( $option . '_backup', get_option( $option ) );
			update_option( $option, '' );
		}
	}

}
