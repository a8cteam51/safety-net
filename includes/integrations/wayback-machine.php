<?php
/**
 * Internet Archive Wayback Machine Link Fixer: scrubs its Archive API keys and deactivates it
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\WaybackMachine;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'wayback-machine',
			label: 'Internet Archive Wayback Machine Link Fixer',
			plugins: array( 'internet-archive-wayback-machine-link-fixer' ),
			options: array(
				'iawmlf_archive_api_access',
				'iawmlf_archive_api_secret',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
