<?php
/**
 * Paid Memberships Pro: scrubs its gateway credentials, switches it to sandbox, stops its crons and deletes its orders, subscriptions and members' billing details
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\PMPro;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

const SCRUBBED_URL = 'https://safetynetscrubbedthis.com';

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'pmpro',
			label: 'Paid Memberships Pro',
			options: array(
				'pmpro_apipassword',
				'pmpro_apisignature',
				'pmpro_apiusername',
				'pmpro_braintree_encryptionkey',
				'pmpro_braintree_merchantid',
				'pmpro_braintree_privatekey',
				'pmpro_braintree_publickey',
				'pmpro_cybersource_merchantid',
				'pmpro_cybersource_securitykey',
				'pmpro_live_stripe_connect_publishablekey',
				'pmpro_live_stripe_connect_secretkey',
				'pmpro_live_stripe_connect_user_id',
				'pmpro_loginname',
				'pmpro_payflow_partner',
				'pmpro_payflow_pwd',
				'pmpro_payflow_user',
				'pmpro_payflow_vendor',
				'pmpro_paypal_cardinal_apiidentifier',
				'pmpro_paypal_cardinal_apikey',
				'pmpro_paypal_cardinal_orgunitid',
				'pmpro_recaptcha_privatekey',
				'pmpro_recaptcha_publickey',
				'pmpro_stripe_billingaddress',
				'pmpro_stripe_publishablekey',
				'pmpro_stripe_secretkey',
				'pmpro_transactionkey',
				'pmpro_twocheckout_accountnumber',
				'pmpro_twocheckout_apipassword',
				'pmpro_twocheckout_apiusername',
				'pmpromc_options',
			),
			option_values: array(
				'pmpro_gateway'             => '',
				'pmpro_gateway_environment' => 'sandbox',
				'pmpro_last_known_url'      => SCRUBBED_URL,
			),
			tables: array(
				'pmpro_membership_orders',
				'pmpro_membership_ordermeta',
				'pmpro_subscriptions',
				'pmpro_subscriptionmeta',
				'pmpro_memberships_users',
				'pmpro_discount_codes_uses',
			),
			scrub: clear_crons( ... ),
			delete: delete_billing_meta( ... ),
			hooks: disable_crons( ... ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);

/**
 * Unschedules PMPro's cron jobs once the scrub has replaced its last known URL, when PMPro is already loaded.
 *
 * @return void
 */
function clear_crons() {
	global $wpdb;

	if ( ! function_exists( 'pmpro_clear_crons' ) ) {
		return;
	}

	// The scrub writes options directly to the database, so get_option() still returns the old URL.
	$url = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'pmpro_last_known_url' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the value the scrub just wrote.
	if ( SCRUBBED_URL === $url ) {
		pmpro_clear_crons();
	}
}

/**
 * Deletes PMPro's billing details and Stripe customer IDs from the user meta of the users who are kept.
 *
 * @return void
 */
function delete_billing_meta() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Direct access bypasses PMPro, which may not be loaded; table names come from $wpdb.
	// The user meta table is shared by a whole network, so only a site that has PMPro's tables removes it.
	$table_name = $wpdb->prefix . 'pmpro_membership_orders';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) !== $table_name ) {
		return;
	}

	$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key = 'pmpro_stripe_customerid'" );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->usermeta WHERE meta_key LIKE %s", 'pmpro_b%' ) );
	// phpcs:enable
}

/**
 * Stops PMPro from registering its cron jobs.
 *
 * @return void
 */
function disable_crons() {
	add_filter(
		'pre_get_ready_cron_jobs',
		static function ( $cron_jobs ) {
			add_filter( 'pmpro_registered_crons', '__return_empty_array', PHP_INT_MAX );
			return $cron_jobs;
		},
		0
	);
}
