<?php
/**
 * Payment gateways: scrubs the Afterpay and WooPayments settings
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\PaymentGateways;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'payment-gateways',
			label: 'Payment gateways',
			options: array(
				'woocommerce_afterpay_settings',
				'woocommerce_woocommerce_payments_settings',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
