<?php
/**
 * Xero: scrubs its app credentials, deletes its OAuth tokens without a backup and its log files, cancels its pending invoice, payment and void actions and deactivates plugins with "-xero" in their name
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\Xero;

use SafetyNet\Integrations\Integration;

use function SafetyNet\Delete\delete_upload_files;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'xero',
			label: 'Xero',
			// Not "xero", which also matches Xero Addons for Elementor and the FalcoSearch product filter.
			plugins: array( '-xero' ),
			options: array(
				'wc_xero_client_id',
				'wc_xero_client_secret',
			),
			// Xero rotates the refresh token on use, so a restored copy would disconnect the live store.
			delete_options: array( 'xero_oauth_options' ),
			cancel_action_scheduler_hooks: array( 'woocommerce\_xero\_%' ),
			scrub: delete_logs( ... ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);

/**
 * Deletes WooCommerce Xero's log files in the scrub step, so they also go while data is kept.
 *
 * @return void
 */
function delete_logs() {
	// With its debug logging filter on, WooCommerce Xero before 1.9.15 wrote the tokens in plain text to its log.
	delete_upload_files( 'wc-logs/xero-*.log' );
}
