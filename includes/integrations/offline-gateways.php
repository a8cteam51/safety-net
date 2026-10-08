<?php
/**
 * Offline payment gateways: names the gateways whose plugins the gateway pass keeps active
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\OfflineGateways;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'offline-gateways',
			label: 'Offline payment gateways',
			// These gateways (and their subclasses) never contact a payment processor, so they shouldn't take the rest of their plugin down with them.
			offline_gateways: array(
				'WC_Gateway_BACS',
				'WC_Gateway_Cheque',
				'WC_Gateway_COD',
				'WC_Pre_Orders_Gateway_Pay_Later',
				'WC_Bookings_Gateway',
				'WC_Gateway_Account_Funds',
				'Kestrel\\Account_Funds\\Gateway',
				'WC_GZD_Gateway_Invoice',
				'WC_GZD_Gateway_Direct_Debit',
				'WCPOS\\WooCommercePOS\\Gateways\\Card',
				'WCPOS\\WooCommercePOS\\Gateways\\Cash',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
