<?php
/* Plugin Name: ZZ Offline COD (fixture) */

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Gateway_COD' ) ) {
			return;
		}

		class ZZ_Offline_COD_Gateway extends WC_Gateway_COD {
			public function __construct() {
				parent::__construct();
				$this->id = 'zz_offline_cod';
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'ZZ_Offline_COD_Gateway';
				return $gateways;
			}
		);
	}
);
