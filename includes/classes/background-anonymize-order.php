<?php
/**
 * Background Anonymize Order Class
 *
 * @package SafetyNet
 */

namespace SafetyNet;

use Faker\Factory;

/**
 * Background Anonymize Order class.
 */
class Background_Anonymize_Order extends \WP_Background_Process {

	/**
	 * @var string
	 */
	protected $action = 'anonymize_order';

	/**
	 * Anonymizes each order in the queue.
	 *
	 * @param array $item WooCommerce order to anonymize
	 *
	 * @return bool
	 */
	protected function task( $item ): bool {
		$faker = Factory::create();

		wp_update_post(
			array(
				'ID'         => $item['ID'],
				'meta_input' => array(
					'_customer_ip_address'    => $faker->ipv4(),
					'_customer_user_agent'    => $faker->userAgent(),
					'_billing_first_name'     => $faker->firstName(),
					'_shipping_first_name'    => $faker->firstName(),
					'_billing_last_name'      => $faker->lastName(),
					'_shipping_last_name'     => $faker->lastName(),
					'_billing_address_1'      => $faker->streetAddress(),
					'_shipping_address_1'     => $faker->streetAddress(),
					'_billing_address_2'      => '',
					'_shipping_address_2'     => '',
					'_billing_city'           => $faker->city(),
					'_shipping_city'          => $faker->city(),
					'_billing_state'          => $faker->stateAbbr(),
					'_shipping_state'         => $faker->stateAbbr(),
					'_billing_postcode'       => $faker->postcode(),
					'_shipping_postcode'      => $faker->postcode(),
					'_billing_country'        => 'US',
					'_shipping_country'       => 'US',
					'_billing_email'          => $faker->unique()->safeEmail(),
					'_billing_phone'          => $faker->phoneNumber(),
					'_billing_address_index'  => $faker->address(),
					'_shipping_address_index' => $faker->address(),
					'_payment_method'         => 'FakePaymentMethod',
					'_payment_method_title'   => 'FakePaymentMethod',
				),
			)
		);

		// Returning false removes the item from the queue
		return false;
	}

}

