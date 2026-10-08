<?php
/**
 * Publish to Apple News: scrubs its API credentials, turns off syncing posts and its debugging email, and deactivates it
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\AppleNews;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'apple-news',
			label: 'Publish to Apple News',
			plugins: array( 'publish-to-apple-news' ),
			partial_options: array(
				'apple_news_settings' => array(
					'api_key',
					'api_secret',
					'api_channel',
					'apple_news_admin_email',
					'api_autosync'                => 'no',
					'api_autosync_update'         => 'no',
					'api_autosync_trash'          => 'no',
					'api_autosync_delete'         => 'no',
					'api_autosync_unpublish'      => 'no',
					'apple_news_enable_debugging' => 'no',
				),
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
