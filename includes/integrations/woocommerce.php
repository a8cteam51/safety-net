<?php
/**
 * WooCommerce: deletes its orders, customers, payment tokens, API keys, webhooks, sessions, downloads and logs, disables its webhooks and deactivates its marketing, shipping and payment extensions
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\WooCommerce;

use SafetyNet\Integrations\Integration;
use WC_Data_Store;
use WC_Webhook;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'woocommerce',
			label: 'WooCommerce',
			plugins: array(
				'facebook-for-woocommerce',
				'google-listings-and-ads',
				'in-stock-mailer-for-wc',
				'mailchimp-for-woocommerce',
				'pinterest-for-woocommerce',
				'woocommerce-amazon-fulfillment',
				'woocommerce-google-adwords-conversion',
				'woocommerce-payments',
				'woocommerce-services',
				'woocommerce-shipping/',
				'woocommerce-square',
				'woocommerce-zapier',
			),
			tables: array(
				'woocommerce_order_itemmeta',
				'woocommerce_order_items',
				'wc_orders',
				'wc_order_addresses',
				'wc_order_operational_data',
				'wc_orders_meta',
				'woocommerce_api_keys',
				'wc_webhooks',
				'woocommerce_payment_tokens',
				'woocommerce_payment_tokenmeta',
				'wc_customer_lookup',
				'wc_order_product_lookup',
				'woocommerce_log',
				'wc_order_stats',
				'woocommerce_sessions',
				'woocommerce_downloadable_product_permissions',
				'wc_download_log',
			),
			post_types: array( 'shop_order', 'shop_order_refund', 'shop_order_placehold' ),
			comment_types: array( 'order_note' ),
			scrub: disable_webhooks( ... ),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);

/**
 * Disables WooCommerce's webhooks through WooCommerce itself, which is usually loaded only when the scrub runs from the Tools page or WP-CLI.
 *
 * @return void
 */
function disable_webhooks() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$data_store = WC_Data_Store::load( 'webhook' );
	$webhooks   = $data_store->search_webhooks();

	if ( ! empty( $webhooks ) ) {
		foreach ( $webhooks as $webhook_id ) {
			$webhook = new WC_Webhook( $webhook_id );
			$webhook->set_status( 'disabled' );
			$webhook->save();
		}
	}
}
