<?php

use SafetyNet\Integrations\Integration;

use function SafetyNet\Delete\delete_users_and_orders;
use function SafetyNet\DeleteTransients\delete_transients;
use function SafetyNet\Integrations\get_integrations;

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
		if ( ! get_option( 'safety_net_plugins_deactivated' ) ) {
			WP_CLI::error( __( 'Plugins need to be deactivated first. Run "wp safety-net deactivate-plugins".', 'safety-net' ) );
		}

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
	 * @subcommand delete-transients
	 * @alias delete_transients
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
		if ( ! get_option( 'safety_net_options_scrubbed' ) ) {
			WP_CLI::error( __( 'Options need to be scrubbed first. Run "wp safety-net scrub-options".', 'safety-net' ) );
		}

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
	 * List the plugin integrations and what each one does
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 * wp safety-net integrations
	 * wp safety-net integrations --format=json
	 *
	 * @subcommand integrations
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function integrations( $args, $assoc_args ) {
		$integrations = array_values( array_map( fn( Integration $integration ) => $integration->to_array(), get_integrations() ) );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( wp_json_encode( $integrations ) );
			return;
		}

		if ( ! $integrations ) {
			WP_CLI::success( __( 'No integrations registered.', 'safety-net' ) );
			return;
		}

		$rows = array();
		foreach ( $integrations as $integration ) {
			$row = array(
				'slug'       => $integration['slug'],
				'label'      => $integration['label'],
				'plugins'    => implode( ', ', $integration['plugins'] ),
				'options'    => count( $integration['options'] ) + count( $integration['partial_options'] ) + count( $integration['option_values'] ) + count( $integration['delete_options'] ) + count( $integration['delete_option_prefixes'] ),
				'tables'     => count( $integration['tables'] ) + count( $integration['network_tables'] ),
				'post_types' => count( $integration['post_types'] ),
				'usermeta'   => count( $integration['usermeta'] ),
			);
			foreach ( Integration::PHASES as $phase ) {
				$row[ $phase ] = $integration[ $phase ] ? 'yes' : 'no';
			}
			$rows[] = $row;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}
}

$instance = new SafetyNet_CLI();

WP_CLI::add_command( 'safety-net', $instance );
