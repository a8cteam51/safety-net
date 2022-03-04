<?php

	use Faker\Factory;
	use function Anonymizer\Anonymize\anonymize_orders;
	use function Anonymizer\Anonymize\anonymize_users;
	use function Anonymizer\Anonymize\anonymize_customers;
	use function Anonymizer\Delete\delete_users;

	/**
	 * Anonymizer command line utilities.
	 */
	class Anonymizer_CLI extends WP_CLI_Command {

		/**
		 * Anonymize a user and their data
		 *
		 * ## EXAMPLES
		 *
		 * wp anonymizer anonymize
		 *
		 */
		public function anonymize( $args ) {
			$faker = Factory::create();

			anonymize_users( $faker );

			anonymize_orders( $faker );

			anonymize_customers( $faker );

			update_option( 'anonymized_status', true, false );

			WP_CLI::success( __( 'Users and their data have been anonymized' ) );
		}

		/**
		 * Delete all non-admin users and their data
		 *
		 * ## EXAMPLES
		 *
		 * wp anonymizer delete
		 *
		 */
		public function delete() {
			delete_users();

			WP_CLI::success( __( 'Users and their data have been deleted' ) );
		}
}

WP_CLI::add_command( 'anonymizer', 'Anonymizer_CLI' );