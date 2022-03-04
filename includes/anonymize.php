<?php

namespace Anonymizer\Anonymize;

use Faker\Generator;
use function Anonymizer\Utilities\get_customer_user_ids;
use function Anonymizer\Utilities\get_customers;
use function Anonymizer\Utilities\get_orders;
use function Anonymizer\Utilities\get_users;

add_action( 'plugins_loaded', __NAMESPACE__ . '\maybe_anonymize_data' );

/**
 * If this site isn't currently on production, and the data hasn't been anonymized yet, it will do that now.
 */
function maybe_anonymize_data() {
	// Check if this is production.
	if ( 'production' === wp_get_environment_type() ) {
		return;
	}

	// Check if data has already been anonymized.
	if ( get_option( 'anonymized_status' ) ) {
		return;
	}

	anonymize_data();
}

/**
 * Anonymizes user info by replacing it with fake data.
 *
 * @return void
 */
function anonymize_data() {
	global $wpdb;

	$faker  = \Faker\Factory::create();

	anonymize_users( $faker );

	anonymize_orders( $faker );

	anonymize_customers( $faker );

	update_option( 'anonymized_status', true, false );
}

/**
 * Updates all users (except admins) with randomized data.
 *
 * @param Generator $faker An instance of Faker
 */
function anonymize_users( Generator $faker ) {
	$customer_ids = get_customer_user_ids();
	$offset = 0;

	while ( true ) {
		$users = get_users( $offset );

		// The while loop ends when there are no more users.
		if ( empty ( $users ) ) {
			break;
		}

		foreach ( $users as $user ) {
			// Skip administrators.
			if ( user_can( $user['ID'], 'administrator' ) ) {
				continue;
			}

			// Default user meta to update.
			$meta_input = [
				'first_name'  => $faker->firstName(),
				'last_name'   => $faker->lastName(),
				'nickname'    => $faker->firstName(),
				'description' => $faker->sentence(),
			];

			// If this user is a WooCommerce customer, update those fields too.
			if ( in_array( $user['ID'], $customer_ids ) ) {
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
					'ID'                  => $user['ID'],
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
		}

		$offset += 1000;
	}
}

/**
 * Updates all WooCommerce orders with randomized data
 *
 * @param Generator $faker An instance of Faker
 */
function anonymize_orders( Generator $faker ) {
	$offset = 0;

	while ( true ) {
		$orders = get_orders( $offset );

		// The while loop ends when there are no more users.
		if ( empty ( $orders ) ) {
			break;
		}

		foreach ( $orders as $order ) {
			wp_update_post(
				[
					'ID'          => $order['ID'],
					'meta_input' => [
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
					],
				]
			);
		}

		$offset += 1000;
	}
}

/**
 * Updates all WooCommerce customers with randomized data
 *
 * @param Generator $faker An instance of Faker
 */
function anonymize_customers( Generator $faker ) {
	global $wpdb;

	$offset = 0;

	while ( true ) {
		$customers = get_customers( $offset );

		// The while loop ends when there are no more users.
		if ( empty ( $customers ) ) {
			break;
		}

		foreach ( $customers as $customer ) {
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
						$customer['customer_id'],
					]
				)
			);
		}

		$offset += 1000;
	}
}