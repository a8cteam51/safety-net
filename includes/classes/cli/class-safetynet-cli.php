<?php

use function SafetyNet\Delete\delete_users_and_orders;
use function SafetyNet\DeleteTransients\delete_transients;

/**
* Anonymizer command line utilities.
*/
class SafetyNet_CLI extends WP_CLI_Command {

	/**
	* Delete all non-admin users and their data
	*
	* ## EXAMPLES
	*
	* wp safety-net delete
	*
	*/
	public function delete() {
		delete_users_and_orders();

		WP_CLI::success( __( 'Users and their data have been deleted' ) );
	}

	/**
	 * Delete all transients
	 *
	 * ## EXAMPLES
	 *
	 * wp safety-net delete-transients
	 *
	 */
	public function delete_transients() {
		delete_transients();

		WP_CLI::success( __( 'Transients have been deleted' ) );
	}

	/**
	 * Clear options such as API keys so that plugins won't talk to 3rd parties
	 *
	 * ## EXAMPLES
	 *
	 * wp safety-net scrub-options
	 *
	 * @subcommand scrub-options
	 *
	 */
	public function scrub_options() {
		\SafetyNet\ScrubOptions\scrub_options();

		WP_CLI::success( __( 'All options have been scrubbed.' ) );
	}

	/**
	 * Deactivate problematic plugins from a denylist
	 *
	 * ## EXAMPLES
	 *
	 * wp safety-net deactivate-plugins
	 *
	 * @subcommand deactivate-plugins
	 *
	 */
	public function deactivate_plugins() {
		\SafetyNet\DeactivatePlugins\deactivate_plugins();

		WP_CLI::success( __( 'Problematic plugins have been deactivated.' ) );
	}

	/**
	 * Disable all WooCommerce webhooks
	 *
	 * ## EXAMPLES
	 *
	 * wp safety-net disable-webhooks
	 *
	 * @subcommand disable-webhooks
	 *
	 */
	public function disable_webhooks() {
		\SafetyNet\DisableWebhooks\disable_webhooks();

		WP_CLI::success( __( 'All WooCommerce webhooks have been disabled.' ) );
	}
}

$instance = new SafetyNet_CLI();

WP_CLI::add_command( 'safety-net', $instance );
