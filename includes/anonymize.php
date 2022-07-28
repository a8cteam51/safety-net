<?php

namespace SafetyNet\Anonymize;

use SafetyNet\Background_Anonymize_Customer;
use SafetyNet\Background_Anonymize_Order;
use SafetyNet\Background_Anonymize_User;
use SafetyNet\Dummy;

add_action( 'safety_net_anonymize_data', __NAMESPACE__ . '\anonymize_data' );

/**
 * Anonymizes user info by replacing it with fake data.
 *
 * @return void
 */
function anonymize_data() {
	global $wpdb;

	// Set option so this function doesn't run again.
	update_option( 'safety_net_anonymized', true );

	// Copy user table to a temporary table that will be anonymized later.
	$wpdb->query( "CREATE TABLE {$wpdb->users}_temp LIKE $wpdb->users" );
	$wpdb->query( "INSERT INTO {$wpdb->users}_temp SELECT * FROM $wpdb->users" );

	// Remove all users, except administrators.
	$wpdb->query( "DELETE wp_users FROM $wpdb->users wp_users INNER JOIN $wpdb->usermeta ON wp_users.ID = {$wpdb->usermeta}.user_id WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value NOT LIKE '%administrator%'" );

	// Copy the user meta table.
	$wpdb->query( "CREATE TABLE {$wpdb->usermeta}_temp LIKE $wpdb->usermeta" );
	$wpdb->query( "INSERT INTO {$wpdb->usermeta}_temp SELECT * FROM $wpdb->usermeta" );

	// Remove all user meta, except administrators'.
	$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE user_id NOT IN (SELECT ID FROM $wpdb->users)" );

	dispatch_anonymize_users();

	dispatch_anonymize_orders();

	dispatch_anonymize_customers();
}

/**
 * Dispatches a background process to anonymize users.
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
	global $wpdb;

	$users = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID 
				FROM {$wpdb->users}_temp 
					LIMIT 500 
				OFFSET %d",
			$offset
		),
		ARRAY_A
	);

	// Return false if there are no more users to process.
	if ( empty( $users ) ) {
		if ( 0 === $offset ) {
			// Database isn't ready yet. Wait and try again.
			sleep( 60 );
			anonymize_users( $offset );
		}

		return false;
	}

	foreach ( $users as $user ) {
		$fake_user = Dummy::get_instance( $user['ID'] );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->users}_temp 
					SET 
						user_pass = %s, 
						user_email = %s,
						user_url = %s, 
						user_nicename = %s, 
						user_activation_key = '', 
						display_name = %s 
					WHERE 
						ID = %d",
				array(
					wp_generate_password( 32, true, true ),
					$fake_user->email_address,
					$fake_user->url,
					$fake_user->username,
					$fake_user->username,
					$user['ID'],
				)
			)
		);

		$meta = array(
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
			'session_tokens'      => '',
		);

		// Set fake meta data.
		foreach ( $meta as $key => $value ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->usermeta}_temp 
						SET meta_value = %s 
						WHERE meta_key = %s 
						AND user_id = %d",
					array(
						$value,
						$key,
						$user['ID'],
					)
				)
			);
		}
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
	global $wpdb;

	$orders = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID 
				FROM $wpdb->posts 
				WHERE post_type = 'shop_order' 
				OR post_type = 'shop_subscription' 
				LIMIT 1000 
				OFFSET %d",
			$offset
		),
		ARRAY_A
	);

	// Return false if there are no more orders left to process.
	if ( empty( $orders ) ) {
		return false;
	}

	foreach ( $orders as $order ) {
		$fake_user = Dummy::get_instance( $order['ID'] );

		$meta = array(
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
		);

		// Set fake meta data.
		foreach ( $meta as $key => $value ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $wpdb->postmeta
						SET meta_value = %s 
						WHERE meta_key = %s 
						  AND post_id = %d",
					array(
						$value,
						$key,
						$order['ID'],
					)
				)
			);
		}
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

	$customers = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT customer_id 
				FROM {$wpdb->prefix}wc_customer_lookup 
					LIMIT 1000 
				OFFSET %d",
			$offset
		),
		ARRAY_A
	);

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
