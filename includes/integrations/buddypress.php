<?php
/**
 * BuddyPress: deletes its member profiles, signups, friendships, messages and notifications
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\BuddyPress;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'buddypress',
			label: 'BuddyPress',
			delete: delete_member_data( ... ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);

/**
 * Deletes BuddyPress profiles, signups, friendships, messages and notifications.
 *
 * @return void
 */
function delete_member_data() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct access bypasses BuddyPress, which may not be loaded; table prefixes come from BuddyPress or $wpdb.
	// Its tables are network-wide on multisite; this is bp_core_get_table_prefix(), which may not be loaded yet.
	$bp_prefix  = apply_filters( 'bp_core_get_table_prefix', $wpdb->base_prefix );
	$table_name = $bp_prefix . 'bp_xprofile_data';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) !== $table_name ) {
		return;
	}

	$wpdb->query( "DELETE FROM {$bp_prefix}bp_xprofile_data" );

	// Signups is a network-wide table on multisite, and BuddyPress uses the same name on single sites.
	$table_name = $wpdb->base_prefix . 'signups';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}signups" );
	}

	$table_name = $bp_prefix . 'bp_friends';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$bp_prefix}bp_friends" );
		$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key = 'total_friend_count'" );
	}

	foreach ( array( 'bp_messages_messages', 'bp_messages_recipients', 'bp_messages_notices', 'bp_messages_meta' ) as $bp_table ) {
		$bp_full_table = $bp_prefix . $bp_table;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $bp_full_table ) ) ) === $bp_full_table ) {
			$wpdb->query( "DELETE FROM {$bp_full_table}" );
		}
	}

	$table_name = $bp_prefix . 'bp_notifications';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$bp_prefix}bp_notifications" );
		$wpdb->query( "DELETE FROM {$bp_prefix}bp_notifications_meta" );
	}
	// phpcs:enable
}
