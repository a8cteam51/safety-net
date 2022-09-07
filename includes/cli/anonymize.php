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
}

$instance = new SafetyNet_CLI();

WP_CLI::add_command( 'safety-net', $instance );
