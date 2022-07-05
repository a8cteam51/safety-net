<?php

use function SafetyNet\Anonymize\anonymize_orders;
use function SafetyNet\Anonymize\anonymize_users;
use function SafetyNet\Anonymize\anonymize_customers;
use function SafetyNet\Delete\delete_users;

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
		anonymize_users();

		anonymize_orders();

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
		delete_users();

		WP_CLI::success( __( 'Users and their data have been deleted' ) );
	}
}

$instance = new SafetyNet_CLI();

WP_CLI::add_command( 'safety-net', $instance );
