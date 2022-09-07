<?php

use function SafetyNet\Anonymize\anonymize_orders;
use function SafetyNet\Anonymize\anonymize_users;
use function SafetyNet\Anonymize\anonymize_customers;
use function SafetyNet\Delete\delete_users_and_orders;

/**
* Anonymizer command line utilities.
*/
class SafetyNet_CLI extends WP_CLI_Command {

	/**
	* Anonymize a user and their data
	*
	* ## EXAMPLES
	*
	* wp safety-net anonymize
	*
	*/
	public function anonymize( $args ) {
		anonymize_users();

		anonymize_orders();

		anonymize_customers();

		update_option( 'anonymized_status', true, false );

		WP_CLI::success( __( 'Users and their data have been anonymized' ) );
	}

	/**
	* Delete all non-admin users and their data
	*
	* ## EXAMPLES
	*
	* wp safety-net delete
	*
	*/
	public function delete() {
		delete_users_and_orders();

		WP_CLI::success( __( 'Users and their data have been deleted' ) );
	}

	/**
	 * Clear options such as API keys so that plugins won't talk to 3rd parties
	 *
	 * ## EXAMPLES
	 *
	 * wp safety-net scrub-options
	 *
	 * @subcommand scrub-options
	 *
	 */
	public function scrub_options() {
		$options_to_clear = array( 'shareasale_wc_tracker_options', 'mc4wp', 'woocommerce_afterpay_settings', 'mailchimp-woocommerce', 'mailchimp-woocommerce-cached-api-account-name', 'wpmandrill', 'woocommerce_shipstation_auth_key', 'woocommerce_braintree_paypal_settings', 'woocommerce_braintree_credit_card_settings', 'klaviyo_settings', 'klaviyo_api_key', 'woocommerce_stripe_account_settings', 'woocommerce_stripe_api_settings', 'woocommerce_stripe_settings', 'woocommerce_ppcp-gateway_settings', 'woocommerce-ppcp-settings', 'woocommerce_paypal_settings', 'woocommerce_woocommerce_payments_settings' );
		$options_to_clear = apply_filters( 'safety_net_options_to_clear', $options_to_clear );

		foreach ( $options_to_clear as $option ) {
			if ( get_option( $option ) ) {

				update_option( $option . '_backup', get_option( $option ) );

				if ( 'woocommerce_ppcp-gateway_settings' === $option || 'woocommerce-ppcp-settings' === $option || 'woocommerce_stripe_settings' === $option ) {
					// we need to more selectively wipe parts of these options, because the respective plugins will fatal if the entire options are blank
					$keys_to_scrub = array( 'enabled', 'client_secret_production', 'client_id_production', 'client_secret', 'client_id', 'merchant_id', 'merchant_email', 'merchant_id_production', 'merchant_email_production', 'publishable_key', 'secret_key', 'webhook_secret' );
					$option_array  = get_option( $option );
					foreach ( $keys_to_scrub as $key ) {
						if ( array_key_exists( $key, $option_array ) ) {
							$option_array[ $key ] = '';
						}
					}
					update_option( $option, $option_array );
				} else {
					update_option( $option, '' );
				}

				WP_CLI::line( "Scrubbed {$option}" );
			}
		}

		WP_CLI::success( __( 'All options have been scrubbed.' ) );
	}

	/**
	 * Deactivate problematic plugins from a denylist
	 *
	 * ## EXAMPLES
	 *
	 * wp safety-net deactivate-plugins
	 *
	 * @subcommand deactivate-plugins
	 *
	 */
	public function deactivate_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_installed_plugins = array_keys( get_plugins() );

		// denylist can be partial matches, i.e. 'paypal' will match with any plugin that has 'paypal' in the slug
		$denylisted_plugins = array( 'avatax', 'postmatic', 'webgility', 'shareasale', 'paypal', 'stripe', 'affirm', 'smtp', 'in-stock-mailer-for-wc', 'klaviyo', 'wp-mail-bank', 'mailchimp', 'mailgun', 'metorik', 'sendinblue', 'wp-sendgrid-mailer', 'socketlabs', 'shipstation', 'wpmandrill', 'wp-console', 'wp-ses', 'algolia', 'zapier' );
		$denylisted_plugins = apply_filters( 'safety_net_denylisted_plugins', $denylisted_plugins );

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

				if ( stristr( $installed_plugin, $denylisted_plugin ) ) {

					// remove plugin silently from active plugins list without triggering hooks
					$current = get_option( 'active_plugins', array() );
					// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
					$key = array_search( $installed_plugin, $current );
					if ( false !== $key ) {
						array_splice( $current, $key, 1 );
					}
					update_option( 'active_plugins', $current );
					WP_CLI::line( "Deactivated $installed_plugin" );
					break; // break out of nested loop once plugin has been deactivated
				}
			}
		}

		WP_CLI::success( __( 'Problematic plugins have been deactivated.' ) );
	}
}

$instance = new SafetyNet_CLI();

WP_CLI::add_command( 'safety-net', $instance );
