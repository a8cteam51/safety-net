<?php

namespace SafetyNet;

use Faker\Factory;
use function SafetyNet\Utilities\get_customer_user_ids;

class Background_Anonymize_User extends \WP_Background_Process {

	/**
	 * @var string
	 */
	protected $action = 'anonymize_user';

	/**
	 * Anonymizes each user in the queue.
	 *
	 * @param array $item User to anonymize
	 *
	 * @return bool
	 */
	protected function task( $item ): bool {
		$customer_ids = get_customer_user_ids();
		$faker        = Factory::create();

		// Default user meta to update.
		$meta_input = [
			'first_name'  => $faker->firstName(),
			'last_name'   => $faker->lastName(),
			'nickname'    => $faker->firstName(),
			'description' => $faker->sentence(),
		];

		// If this user is a WooCommerce customer, update those fields too.
		if ( in_array( $item['ID'], $customer_ids ) ) {
			$meta_input = array_merge(
				$meta_input,
				[
					'billing_first_name'  => $faker->firstName(),
					'shipping_first_name' => $faker->firstName(),
					'billing_last_name'   => $faker->lastName(),
					'shipping_last_name'  => $faker->lastName(),
					'billing_address_1'   => $faker->streetAddress(),
					'shipping_address_1'  => $faker->streetAddress(),
					'billing_address_2'   => '',
					'shipping_address_2'  => '',
					'billing_city'        => $faker->city(),
					'shipping_city'       => $faker->city(),
					'billing_state'       => $faker->stateAbbr(),
					'shipping_state'      => $faker->stateAbbr(),
					'billing_postcode'    => $faker->postcode(),
					'shipping_postcode'   => $faker->postcode(),
					'billing_country'     => 'US',
					'shipping_country'    => 'US',
					'billing_email'       => $faker->unique()->safeEmail(),
					'billing_phone'       => $faker->phoneNumber(),
				]
			);
		}

		wp_insert_user(
			[
				'ID'                  => $item['ID'],
				'user_email'          => $faker->unique()->safeEmail(),
				'user_url'            => $faker->url(),
				'user_activation_key' => '',
				'display_name'        => $faker->firstName(),
				'user_login'          => $faker->unique()->userName(),
				'nice_name'           => mb_substr( $faker->unique()->userName(), 0, 50 ),
				'user_pass'           => wp_generate_password( 32, true, true ),
				'meta_input'          => $meta_input
			]
		);

		// Returning false removes the item from the queue
		return false;
	}

}

