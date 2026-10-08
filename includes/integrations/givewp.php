<?php
/**
 * GiveWP: deletes its donors, donations and subscriptions unless SAFETY_NET_SKIP_GIVEWP is true, and deactivates GiveWP and its add-ons either way
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\GiveWP;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'givewp',
			label: 'GiveWP',
			plugins: array( 'give-', 'give/' ),
			delete: delete_donations( ... ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);

/**
 * Deletes GiveWP's donors, donations and subscriptions with their meta, unless SAFETY_NET_SKIP_GIVEWP is true.
 *
 * @return void
 */
function delete_donations() {
	global $wpdb;

	if ( defined( 'SAFETY_NET_SKIP_GIVEWP' ) && true === SAFETY_NET_SKIP_GIVEWP ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Direct access bypasses GiveWP, which may not be loaded; table names come from $wpdb.
	$table_name = $wpdb->prefix . 'give_donors';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}give_donors" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}give_donormeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}give_donationmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}give_comments" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}give_commentmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}give_sessions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}give_subscriptions" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}give_subscriptionmeta" );
	}

	$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'give_payment' )" );
	$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'give_payment'" );
	// phpcs:enable
}
