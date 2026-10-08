<?php
/**
 * ShareASale: scrubs its WooCommerce tracker settings and deactivates plugins with "shareasale" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\ShareASale;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'shareasale',
			label: 'ShareASale',
			plugins: array( 'shareasale' ),
			options: array( 'shareasale_wc_tracker_options' ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
