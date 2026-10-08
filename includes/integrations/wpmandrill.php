<?php
/**
 * Mandrill (wpMandrill): scrubs its settings and deactivates plugins with "wpmandrill" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\WPMandrill;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'wpmandrill',
			label: 'wpMandrill',
			plugins: array( 'wpmandrill' ),
			options: array( 'wpmandrill' ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
