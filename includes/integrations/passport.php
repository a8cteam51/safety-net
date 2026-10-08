<?php
/**
 * Passport: scrubs the API credentials of Team51's subscription plugin
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\Passport;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'passport',
			label: 'Passport',
			options: array(
				'passport_api_key',
				'passport_api_secret',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
