<?php

namespace SafetyNet\DisableWebhooks;

use function SafetyNet\Utilities\get_denylist_array;

add_action( 'safety_net_disable_webhooks', __NAMESPACE__ . '\disable_webhooks' );

/*
* Deactivate plugins from a denylist
*/
function disable_webhooks() {
	if ( get_option( 'safety_net_webhooks_disabled' ) ) {
		return;
	}

	// The webhooks settings are part of WooCommerce
	if ( class_exists( 'WC_Data_Store' ) ) {
		$data_store = \WC_Data_Store::load( "webhook" );
		$webhooks = $data_store->get_webhooks_ids( "" );
		foreach ( $webhooks as $webhook_id ) {
		  $webhook = \wc_get_webhook($webhook_id);
		  if ($webhook->get_status() !== "disabled") {
			$webhook->set_status("disabled");
			$webhook->save();
		  }
		}
	}
	update_option( 'safety_net_webhooks_disabled', true );
}