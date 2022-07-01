<?php
/**
 * Background Anonymize Order Class
 *
 * @package SafetyNet
 */

namespace SafetyNet;

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
		$fake_user = Dummy::get_instance( $item['ID'] );

		wp_update_post(
			array(
				'ID'         => $item['ID'],
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

		// Returning false removes the item from the queue
		return false;
	}

}

