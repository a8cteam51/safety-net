<?php
/**
 * Background Anonymize User Class
 *
 * @package SafetyNet
 */

namespace SafetyNet;

/**
 * Background Anonymize User class
 */
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
		$fake_user = Dummy::get_instance( $item['ID'] );

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
				'ID'                  => $item['ID'],
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

		// Returning false removes the item from the queue
		return false;
	}

}

