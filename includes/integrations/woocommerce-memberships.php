<?php
/**
 * WooCommerce Memberships: deletes its members' memberships
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\WooCommerceMemberships;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'woocommerce-memberships',
			label: 'WooCommerce Memberships',
			post_types: array( 'wc_user_membership' ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
