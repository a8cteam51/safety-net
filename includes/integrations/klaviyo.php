<?php
/**
 * Klaviyo: scrubs its API keys and settings and deactivates plugins with "klaviyo" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\Klaviyo;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'klaviyo',
			label: 'Klaviyo',
			plugins: array( 'klaviyo' ),
			options: array(
				'klaviyo_api_key',
				'klaviyo_edd_license_key',
				'klaviyo_settings',
				'novos_klaviyo_option_name',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
