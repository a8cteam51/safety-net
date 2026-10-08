<?php
/**
 * WooCommerce Subscriptions: deletes its subscriptions and their renewal actions and pauses renewals while the Tools page toggle is on
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\WooCommerceSubscriptions;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'woocommerce-subscriptions',
			label: 'WooCommerce Subscriptions',
			post_types: array( 'shop_subscription' ),
			// Admins keep their user meta, so their cached subscription IDs would point at deleted subscriptions.
			usermeta: array( '\_wcs\_subscription\_ids\_cache%' ),
			action_scheduler_hooks: array(
				'woocommerce_scheduled_subscription_payment',
				'woocommerce_scheduled_subscription_payment_retry',
				'woocommerce_scheduled_subscription_end_of_prepaid_term',
			),
			hooks: register_paused_store( ... ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);

/**
 * Has Action Scheduler use the store that skips renewal and payment retry actions while the pause is on.
 *
 * @return void
 */
function register_paused_store() {
	// A missing toggle counts as on, since maybe_pause_renewal_actions() only saves it later in this request.
	if ( ! in_array( get_option( 'safety_net_pause_renewal_actions_toggle' ), array( 'on', false ), true ) ) {
		return;
	}

	add_filter( 'action_scheduler_store_class', __NAMESPACE__ . '\paused_store_class', 101 );
}

/**
 * Returns the store class that skips renewal and payment retry actions.
 *
 * @return string
 */
function paused_store_class() {
	// Load the custom class file only when Action Scheduler requests it.
	if ( ! class_exists( 'SafetyNet\ActionScheduler_Custom_DBStore' ) ) {
		require_once dirname( __DIR__ ) . '/classes/class-actionscheduler-custom-dbstore.php';
	}
	return 'SafetyNet\ActionScheduler_Custom_DBStore';
}
