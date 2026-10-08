<?php
/**
 * ShipStation: scrubs its WooCommerce authentication key and deactivates plugins with "shipstation" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\ShipStation;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'shipstation',
			label: 'ShipStation',
			plugins: array( 'shipstation' ),
			options: array( 'woocommerce_shipstation_auth_key' ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
