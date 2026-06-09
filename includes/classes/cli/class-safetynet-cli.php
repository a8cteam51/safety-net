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

	/**
	 * Generate mock data (blog posts, and WooCommerce products, customers, and orders) from JSON fixtures
	 *
	 * ## OPTIONS
	 *
	 * [--types=<types>]
	 * : Comma-separated list of data types to generate. Defaults to all applicable types. WooCommerce types are skipped when WooCommerce is inactive.
	 *
	 * ## EXAMPLES
	 *
	 *     wp safety-net generate-mock-data
	 *     wp safety-net generate-mock-data --types=posts,products
	 *
	 * @subcommand generate-mock-data
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function generate_mock_data( $args, $assoc_args ) {
		$types = array();
		if ( ! empty( $assoc_args['types'] ) ) {
			$types = array_map( 'trim', explode( ',', $assoc_args['types'] ) );
		}

		$counts = \SafetyNet\GenerateMockData\generate_mock_data( $types );

		if ( empty( array_filter( $counts ) ) ) {
			WP_CLI::warning( __( 'No mock data was generated. WooCommerce may be inactive, or no valid types were selected.' ) );
			return;
		}

		foreach ( $counts as $type => $count ) {
			WP_CLI::log( sprintf( '%d %s created.', (int) $count, $type ) );
		}

		WP_CLI::success( __( 'Mock data generation complete.' ) );
	}
}

$instance = new SafetyNet_CLI();

WP_CLI::add_command( 'safety-net', $instance );
