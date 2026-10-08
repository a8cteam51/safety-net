<?php
/**
 * WP Mail Logging: deletes its log of sent emails
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\WPMailLogging;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'wp-mail-logging',
			label: 'WP Mail Logging',
			tables: array( 'wpml_mails' ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
