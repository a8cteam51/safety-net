<?php
/**
 * Nelio Content: scrubs its settings and cached news and deactivates plugins with "nelio-content" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\NelioContent;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'nelio-content',
			label: 'Nelio Content',
			plugins: array( 'nelio-content' ),
			options: array(
				'_transient_nelio_content_news',
				'_transient_timeout_nelio_content_news',
				'nelio-content_settings',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
