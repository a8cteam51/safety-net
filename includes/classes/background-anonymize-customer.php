<?php

namespace SafetyNet;

use Faker\Factory;

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

		$faker = Factory::create();

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
				[
					$faker->userName(),
					$faker->firstName(),
					$faker->lastName(),
					$faker->safeEmail(),
					'US',
					$faker->postcode(),
					$faker->city(),
					$faker->stateAbbr(),
					$item['customer_id'],
				]
			)
		);

		// Returning false removes the item from the queue
		return false;
	}

}

