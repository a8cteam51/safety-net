<?php
/**
 * NMI (Pledged Plugins): blanks the credit card and eCheck gateways' API keys and passwords without a backup
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\NMI;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'nmi',
			label: 'NMI (Pledged Plugins)',
			// A restorable backup would let a copy charge real cards and bank accounts, so the keys are blanked without one and the gateways keep their other settings.
			delete_partial_options: array(
				'woocommerce_nmi_settings'        => array( 'private_key', 'public_key', 'checkout_key', 'password' ),
				'woocommerce_nmi-echeck_settings' => array( 'private_key', 'public_key', 'password' ),
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
