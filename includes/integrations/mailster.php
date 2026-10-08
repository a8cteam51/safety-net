<?php
/**
 * Mailster: scrubs its settings and deactivates plugins with "mailster" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\Mailster;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'mailster',
			label: 'Mailster',
			plugins: array( 'mailster' ),
			options: array( 'mailster_options' ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
