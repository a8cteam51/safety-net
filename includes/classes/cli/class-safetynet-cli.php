<?php

use function SafetyNet\Delete\delete_users_and_orders;

/**
* Anonymizer command line utilities.
*/
class SafetyNet_CLI extends WP_CLI_Command {

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
		\SafetyNet\DeactivatePlugins\scrub_options();

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
}

$instance = new SafetyNet_CLI();

WP_CLI::add_command( 'safety-net', $instance );
