<?php

use function SafetyNet\Anonymize\anonymize_orders;
use function SafetyNet\Anonymize\copy_and_clear_user_tables;
use function SafetyNet\Anonymize\store_anonymized_user_data;
use function SafetyNet\Anonymize\anonymize_users;
use function SafetyNet\Anonymize\anonymize_customers;
use function SafetyNet\Delete\delete_users_and_orders;

/**
* Anonymizer command line utilities.
*/
class SafetyNet_CLI extends WP_CLI_Command {

	/**
	* Anonymize a user and their data
	*
	* ## EXAMPLES
	*
	* wp safety-net anonymize
	*
	*/
	public function anonymize( $args ) {

		$info = WP_CLI::colorize( '%pThis process will anonymize your current users, orders and customers with dummy data.%n ' );
		WP_CLI::log( $info );

		WP_CLI::warning( 'Please proceed with caution if you have a site with a large number of users/orders/customers' );

		WP_CLI::confirm( 'Are you sure you want to do this?' );

		WP_CLI::log( '- Copying users to temporary tables ...' );
		copy_and_clear_user_tables();

		WP_CLI::log( '- Anonymizing users ... ' );
		anonymize_users();

		WP_CLI::log( '- Storing the anonymized users to the default tables ... ' );
		store_anonymized_user_data();

		WP_CLI::log( '- Anonymizing orders ... ' );
		anonymize_orders();

		WP_CLI::log( '- Anonymizing customers ... ' );
		anonymize_customers();

		update_option( 'anonymized_status', true, false );

		WP_CLI::success( __( 'Users and their data have been anonymized' ) );
	}

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
