<?php
/**
 * PayPal: scrubs the credentials of the WooCommerce PayPal, PayPal Payments and Braintree gateways and deactivates plugins with "paypal" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\PayPal;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

const CREDENTIAL_KEYS = array( 'enabled', 'client_secret_production', 'client_id_production', 'client_secret', 'client_id', 'merchant_id', 'merchant_email', 'merchant_id_production', 'merchant_email_production', 'publishable_key', 'secret_key', 'webhook_secret' );

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'paypal',
			label: 'PayPal',
			plugins: array( 'paypal' ),
			options: array(
				'woocommerce_braintree_credit_card_settings',
				'woocommerce_braintree_paypal_settings',
				'woocommerce_paypal_settings',
			),
			// PayPal Payments fatals when its whole settings arrays are blank.
			partial_options: array(
				'woocommerce_ppcp-gateway_settings' => CREDENTIAL_KEYS,
				'woocommerce-ppcp-settings'         => CREDENTIAL_KEYS,
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
