<?php
/* Plugin Name: ZZ Checkout (fixture) */

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		class ZZ_Checkout_Gateway extends WC_Payment_Gateway {
			public function __construct() {
				$this->id           = 'acme_pay';
				$this->method_title = 'Acme Pay';
				$this->title        = 'Acme Pay';
				$this->enabled      = 'yes';
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'ZZ_Checkout_Gateway';
				return $gateways;
			}
		);
	}
);
