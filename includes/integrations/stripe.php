<?php
/**
 * Stripe: scrubs the WooCommerce Stripe gateway's credentials and deactivates plugins with "stripe" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\Stripe;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'stripe',
			label: 'Stripe',
			plugins: array( 'stripe' ),
			options: array(
				'woocommerce_stripe_account_settings',
				'woocommerce_stripe_api_settings',
			),
			// The gateway fatals when its whole settings array is blank.
			partial_options: array(
				'woocommerce_stripe_settings' => array( 'enabled', 'client_secret_production', 'client_id_production', 'client_secret', 'client_id', 'merchant_id', 'merchant_email', 'merchant_id_production', 'merchant_email_production', 'publishable_key', 'secret_key', 'webhook_secret' ),
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
