<?php
/**
 * ReferralCandy: scrubs its WooCommerce settings and deactivates plugins with "referralcandy" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\ReferralCandy;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'referralcandy',
			label: 'ReferralCandy',
			plugins: array( 'referralcandy' ),
			options: array( 'woocommerce_referralcandy_settings' ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
