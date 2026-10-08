<?php
/**
 * Zoho Mail: scrubs its OAuth tokens and client secret and deactivates plugins with "zoho" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\ZohoMail;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'zoho-mail',
			label: 'Zoho Mail',
			plugins: array(
				'zoho',
				'zoho-mail',
			),
			options: array(
				'zmail_access_token',
				'zmail_auth_code',
				'zmail_integ_client_secret',
				'zmail_refresh_token',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
