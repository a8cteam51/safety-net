<?php
/**
 * Mailchimp: scrubs the Mailchimp for WooCommerce and Mailchimp for WordPress settings and the Gravity Forms Mailchimp Add-On's credentials, and deactivates plugins with "mailchimp" in their name
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
			// The add-on fatals when it writes a group key into a blanked settings array.
			partial_options: array(
				'gravityformsaddon_gravityformsmailchimp_settings' => array( 'access_token', 'apiKey' ),
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
