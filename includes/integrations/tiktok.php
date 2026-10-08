<?php
/**
 * TikTok: scrubs its access token, secret and account IDs and deactivates plugins with "tiktok" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\TikTok;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'tiktok',
			label: 'TikTok',
			plugins: array(
				'tiktok',
				'tiktok-for-business',
			),
			options: array(
				'tt4b_access_token',
				'tt4b_advertiser_id',
				'tt4b_app_id',
				'tt4b_bc_id',
				'tt4b_catalog_id',
				'tt4b_external_business_id',
				'tt4b_external_data',
				'tt4b_secret',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
