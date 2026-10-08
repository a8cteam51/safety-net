<?php
/**
 * Kit (formerly ConvertKit): scrubs its API credentials and deactivates plugins with "convertkit" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\ConvertKit;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'convertkit',
			label: 'Kit',
			plugins: array( 'convertkit' ),
			partial_options: array(
				'_wp_convertkit_settings' => array( 'access_token', 'refresh_token', 'token_expires', 'api_key', 'api_secret' ),
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
