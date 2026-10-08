<?php
/**
 * HubSpot: scrubs its access token and deactivates plugins with "hubspot" or "leadin" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\HubSpot;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'hubspot',
			label: 'HubSpot',
			plugins: array(
				'hubspot',
				'leadin',
			),
			options: array( 'leadin_access_token' ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
