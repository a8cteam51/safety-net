<?php
/**
 * Newsletter: deletes its subscribers and deactivates plugins with "newsletter" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\Newsletter;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'newsletter',
			label: 'Newsletter',
			plugins: array( 'newsletter' ),
			tables: array( 'newsletter' ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
