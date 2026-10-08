<?php
/**
 * Jetpack: scrubs its connection secrets except on Atomic, turns off Publicize, Subscriptions and Enhanced Distribution, stops subscription emails and deactivates Jetpack Social
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\Jetpack;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'jetpack',
			label: 'Jetpack',
			plugins: array( 'jetpack-social' ),
			options: array(
				'jetpack_private_options',
				'jetpack_secrets',
			),
			partial_options: array(
				'jetpack_active_modules' => disable_modules( ... ),
			),
			hooks: stop_subscription_emails( ... ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);

/**
 * Removes the modules that publish to or email other people from the active modules.
 *
 * @param array $modules The active modules.
 * @return array
 */
function disable_modules( array $modules ): array {
	return array_filter(
		$modules,
		static function ( $module ) {
			return ! in_array( $module, array( 'enhanced-distribution', 'publicize', 'subscriptions' ), true );
		}
	);
}

/**
 * Sends Jetpack subscription emails only for posts in a category that does not exist.
 *
 * @return void
 */
function stop_subscription_emails() {
	add_filter(
		'jetpack_subscriptions_exclude_all_categories_except',
		static function () {
			return array( 'non-existing' );
		}
	);
}
