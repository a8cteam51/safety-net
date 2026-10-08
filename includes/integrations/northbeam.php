<?php
/**
 * Northbeam: scrubs its API key and client ID
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\Northbeam;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'northbeam',
			label: 'Northbeam',
			options: array(
				'northbeam_api_key',
				'northbeam_client_id',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
