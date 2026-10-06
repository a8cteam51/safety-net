<?php
/* Plugin Name: ZZ Keep Gateway (fixture) */

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		class ZZ_Keep_Gateway extends WC_Payment_Gateway {
			public function __construct() {
				$this->id           = 'zz_keep';
				$this->method_title = 'ZZ Keep';
				$this->title        = 'ZZ Keep';
				$this->enabled      = 'yes';
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'ZZ_Keep_Gateway';
				return $gateways;
			}
		);
	}
);
