<?php
/**
 * Yotpo: scrubs its settings and deactivates plugins with "yotpo" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\Yotpo;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'yotpo',
			label: 'Yotpo',
			plugins: array( 'yotpo' ),
			options: array( 'yotpo_settings' ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
