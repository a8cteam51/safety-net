<?php
/**
 * AutomateWoo: disables its workflows, empties its queue, cancels its pending scheduled actions and deactivates it
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\AutomateWoo;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'automatewoo',
			label: 'AutomateWoo',
			plugins: array( 'automatewoo' ),
			cancel_action_scheduler_hooks: array( '%automatewoo%' ),
			scrub: disable_workflows( ... ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);

/**
 * Disables the published workflows and empties the queue of workflow runs.
 *
 * @return void
 */
function disable_workflows() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Direct access bypasses AutomateWoo, which may not be loaded; table names come from $wpdb.
	$wpdb->query( "UPDATE $wpdb->posts SET post_status = 'aw-disabled' WHERE post_type = 'aw_workflow' AND post_status = 'publish'" );

	$table_name = $wpdb->prefix . 'automatewoo_queue';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}automatewoo_queue" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}automatewoo_queue_meta" );
	}
	// phpcs:enable
}
