<?php

namespace SafetyNet\RestStatus;

use function SafetyNet\Utilities\get_environment_type;

add_action( 'rest_api_init', __NAMESPACE__ . '\register_status_route' );

/**
 * Registers the read-only status route used to confirm, over HTTP, that Safety Net has run.
 */
function register_status_route() {
	register_rest_route(
		'safety-net/v1',
		'/status',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => __NAMESPACE__ . '\get_status',
		)
	);
}

/**
 * Reports Safety Net's run state: the environment plus each scrub step's completion flag.
 *
 * This file only loads on non-production sites (safety-net.php bails before requiring it on production), so the
 * route's mere presence already signals Safety Net is active here. It is meant to be polled until the scrub
 * completes, so the response must never be cached: a stale snapshot would keep reporting a step as undone after
 * it had finished. DONOTCACHEPAGE covers page caches (Batcache, host edge); the headers cover proxies/CDNs.
 */
function get_status() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	$response = new \WP_REST_Response(
		array(
			'active'              => true,
			'environment'         => get_environment_type(),
			'options_scrubbed'    => (bool) get_option( 'safety_net_options_scrubbed' ),
			'plugins_deactivated' => (bool) get_option( 'safety_net_plugins_deactivated' ),
			'data_deleted'        => (bool) get_option( 'safety_net_data_deleted' ),
			'transients_deleted'  => (bool) get_option( 'safety_net_transients_deleted' ),
			'webhooks_disabled'   => (bool) get_option( 'safety_net_webhooks_disabled' ),
		)
	);

	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'Pragma', 'no-cache' );
	$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );

	return $response;
}
