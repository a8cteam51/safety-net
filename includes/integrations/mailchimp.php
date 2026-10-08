<?php
/**
 * Mailchimp: scrubs the Mailchimp for WooCommerce and Mailchimp for WordPress settings and deactivates plugins with "mailchimp" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\Mailchimp;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'mailchimp',
			label: 'Mailchimp',
			plugins: array( 'mailchimp' ),
			options: array(
				'mailchimp-woocommerce',
				'mailchimp-woocommerce-cached-api-account-name',
				'mc4wp',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
