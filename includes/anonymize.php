<?php

namespace SafetyNet\Anonymize;

use SafetyNet\Background_Anonymize_Customer;
use SafetyNet\Background_Anonymize_Order;
use SafetyNet\Background_Anonymize_User;
use SafetyNet\Dummy;
use function SafetyNet\Utilities\get_customers;
use function SafetyNet\Utilities\get_orders;
use function SafetyNet\Utilities\get_users;

/**
 * Anonymizes user info by replacing it with fake data.
 *
 * @return void
 */
function anonymize_data() {
	dispatch_anonymize_users();

	dispatch_anonymize_orders();

	dispatch_anonymize_customers();

	update_option( 'anonymized_status', true, false );
}

/**
 * Dispatches a background process for anonymizing users.
 */
function dispatch_anonymize_users() {
	$background_anonymize_user = new Background_Anonymize_User();
	$background_anonymize_user->push_to_queue(
		array(
			'offset' => 0,
		)
	);
	$background_anonymize_user->save()->dispatch();
}

/**
 * Dispatches a background process for updating all WooCommerce orders with randomized data
 */
function dispatch_anonymize_orders() {
	$background_anonymize_order = new Background_Anonymize_Order();
	$background_anonymize_order->push_to_queue(
		array(
			'offset' => 0,
		)
	);
	$background_anonymize_order->save()->dispatch();
}

/**
 * Dispatches a background process for updating all WooCommerce customers with randomized data
 */
function dispatch_anonymize_customers() {
	$background_anonymize_customer = new Background_Anonymize_Customer();
	$background_anonymize_customer->push_to_queue(
		array(
			'offset' => 0,
		)
	);
	$background_anonymize_customer->save()->dispatch();
}

/**
 * Anonymizes user info with fake data.
 *
 * @param int $offset The number to offset by.
 *
 * @return bool Whether the users were anonymized or not.
 */
function anonymize_users( int $offset = 0 ): bool {
	$users = get_users( $offset );

	// Return false if there are no more users to process.
	if ( empty( $users ) ) {
		return false;
	}

	foreach ( $users as $user ) {
		// Skip administrators.
		if ( user_can( $user['ID'], 'administrator' ) ) {
			continue;
		}

		$fake_user = Dummy::get_instance( $user['ID'] );

		// Default user meta to update.
		$meta_input = array(
			'first_name'          => $fake_user->first_name,
			'last_name'           => $fake_user->last_name,
			'nickname'            => $fake_user->first_name,
			'description'         => $fake_user->description,
			'billing_first_name'  => $fake_user->first_name,
			'shipping_first_name' => $fake_user->first_name,
			'billing_last_name'   => $fake_user->last_name,
			'shipping_last_name'  => $fake_user->last_name,
			'billing_address_1'   => $fake_user->street_address,
			'shipping_address_1'  => $fake_user->street_address,
			'billing_address_2'   => '',
			'shipping_address_2'  => '',
			'billing_city'        => $fake_user->city,
			'shipping_city'       => $fake_user->city,
			'billing_state'       => $fake_user->state,
			'shipping_state'      => $fake_user->state,
			'billing_postcode'    => $fake_user->postcode,
			'shipping_postcode'   => $fake_user->postcode,
			'billing_country'     => 'US',
			'shipping_country'    => 'US',
			'billing_email'       => $fake_user->email_address,
			'billing_phone'       => $fake_user->phone,
		);

		wp_insert_user(
			array(
				'ID'                  => $user['ID'],
				'user_email'          => $fake_user->email_address,
				'user_url'            => $fake_user->url,
				'user_activation_key' => '',
				'display_name'        => $fake_user->first_name,
				'user_login'          => $fake_user->username,
				'nice_name'           => $fake_user->username,
				'user_pass'           => wp_generate_password( 32, true, true ),
				'meta_input'          => $meta_input,
			)
		);
	}

	return true;
}

/**
 * Anonymizes WooCommerce orders with fake data.
 *
 * @param int $offset The number to offset by.
 *
 * @return bool Whether the orders were anonymized or not.
 */
function anonymize_orders( int $offset = 0 ): bool {
	$orders = get_orders( $offset );

	// Return false if there are no more orders to process.
	if ( empty( $orders ) ) {
		return false;
	}

	foreach ( $orders as $order ) {
		$fake_user = Dummy::get_instance( $order['ID'] );

		wp_update_post(
			array(
				'ID'         => $order['ID'],
				'meta_input' => array(
					'_customer_ip_address'    => $fake_user->ip_address,
					'_customer_user_agent'    => $fake_user->user_agent,
					'_billing_first_name'     => $fake_user->first_name,
					'_shipping_first_name'    => $fake_user->first_name,
					'_billing_last_name'      => $fake_user->last_name,
					'_shipping_last_name'     => $fake_user->last_name,
					'_billing_address_1'      => $fake_user->street_address,
					'_shipping_address_1'     => $fake_user->street_address,
					'_billing_address_2'      => '',
					'_shipping_address_2'     => '',
					'_billing_city'           => $fake_user->city,
					'_shipping_city'          => $fake_user->city,
					'_billing_state'          => $fake_user->state,
					'_shipping_state'         => $fake_user->state,
					'_billing_postcode'       => $fake_user->postcode,
					'_shipping_postcode'      => $fake_user->postcode,
					'_billing_country'        => 'US',
					'_shipping_country'       => 'US',
					'_billing_email'          => $fake_user->email_address,
					'_billing_phone'          => $fake_user->phone,
					'_billing_address_index'  => $fake_user->street_address,
					'_shipping_address_index' => $fake_user->street_address,
					'_payment_method'         => 'FakePaymentMethod',
					'_payment_method_title'   => 'FakePaymentMethod',
				),
			)
		);
	}

	return true;
}

/**
 * Anonymizes WooCommerce customers with fake data.
 *
 * @param int $offset The number to offset by.
 *
 * @return bool Whether the customers were anonymized or not.
 */
function anonymize_customers( int $offset = 0 ): bool {
	global $wpdb;
	$customers = get_customers( $offset );

	// The while loop ends when there are no more users.
	if ( empty( $customers ) ) {
		return false;
	}

	foreach ( $customers as $customer ) {
		$fake_user = Dummy::get_instance( $customer['customer_id'] );

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
					$customer['customer_id'],
				)
			)
		);
	}

	return true;
}