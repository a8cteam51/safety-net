<?php

namespace SafetyNet\Anonymize;

use SafetyNet\Background_Anonymize_Customer;
use SafetyNet\Background_Anonymize_Order;
use SafetyNet\Background_Anonymize_User;
use function SafetyNet\Utilities\get_customers;
use function SafetyNet\Utilities\get_orders;
use function SafetyNet\Utilities\get_users;

/**
 * Anonymizes user info by replacing it with fake data.
 *
 * @return void
 */
function anonymize_data() {
	anonymize_users();

	anonymize_orders();

	anonymize_customers();

	update_option( 'anonymized_status', true, false );
}

/**
 * Updates all users (except admins) with randomized data.
 */
function anonymize_users() {
	$background_anonymize_user = new Background_Anonymize_User();
	$offset                    = 0;

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

			$background_anonymize_user->push_to_queue( $user );
		}

		$offset += 1000;
	}

	$background_anonymize_user->save()->dispatch();
}

/**
 * Updates all WooCommerce orders with randomized data
 */
function anonymize_orders() {
	$background_anonymize_order = new Background_Anonymize_Order();
	$offset = 0;

	while ( true ) {
		$orders = get_orders( $offset );

		// The while loop ends when there are no more users.
		if ( empty ( $orders ) ) {
			break;
		}

		foreach ( $orders as $order ) {
			$background_anonymize_order->push_to_queue( $order );
		}

		$offset += 1000;
	}

	$background_anonymize_order->save()->dispatch();
}

/**
 * Updates all WooCommerce customers with randomized data
 */
function anonymize_customers() {
	$background_anonymize_customer = new Background_Anonymize_Customer();
	$offset                        = 0;

	while ( true ) {
		$customers = get_customers( $offset );

		// The while loop ends when there are no more users.
		if ( empty ( $customers ) ) {
			break;
		}

		foreach ( $customers as $customer ) {
			$background_anonymize_customer->push_to_queue( $customer );
		}

		$offset += 1000;
	}

	$background_anonymize_customer->save()->dispatch();
}
