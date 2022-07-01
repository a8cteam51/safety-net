<?php
/**
 * Background Anonymize Customer Class
 *
 * @package SafetyNet
 */

namespace SafetyNet;

/**
 * Background Anonymize Customer class
 */
class Background_Anonymize_Customer extends \WP_Background_Process {

	/**
	 * @var string
	 */
	protected $action = 'anonymize_customer';

	/**
	 * Anonymizes each customer in the queue.
	 *
	 * @param array $item Customer to anonymize
	 *
	 * @return bool
	 */
	protected function task( $item ): bool {
		global $wpdb;

		$fake_user = Dummy::get_instance( $item['customer_id'] );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wc_customer_lookup
					SET
						username = %s,
						first_name = %s,
						last_name = %s,
						email = %s,
						country = %s,
						postcode = %s,
						city = %s,
						state = %s
					WHERE
						customer_id = %d",
				array(
					$fake_user->username,
					$fake_user->first_name,
					$fake_user->last_name,
					$fake_user->email_address,
					'US',
					$fake_user->postcode,
					$fake_user->city,
					$fake_user->state,
					$item['customer_id'],
				)
			)
		);

		// Returning false removes the item from the queue
		return false;
	}

}

