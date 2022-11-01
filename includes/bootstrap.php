<?php
/**
 * Bootstrap
 *
 * Logic related to the loading of Safety Net
 */
namespace SafetyNet\Bootstrap;

use SafetyNet\Background_Anonymize_Customer;
use SafetyNet\Background_Anonymize_Order;
use SafetyNet\Background_Anonymize_User;
use function SafetyNet\Utilities\is_production;

add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_pause_renewal_actions' );
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_scrub_options' );
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_deactivate_plugins' );
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_delete_data' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\instantiate_background_classes' );


/**
 * Background Process classes need to be instantiated on plugins_loaded hook.
 */
function instantiate_background_classes() {
	new Background_Anonymize_User();
	new Background_Anonymize_Order();
	new Background_Anonymize_Customer();
}

/**
 * Determines if we should set the 'Pause renewal actions' toggle when first loading the plugin.
 *
 */
function maybe_pause_renewal_actions() {
	// If the value isn't false, then the toggle has already been turned on or off.
	if ( false !== get_option( 'safety_net_pause_renewal_actions_toggle' ) ) {
		return;
	}

	// If we're not on staging, development, or a local environment, return.
	if ( is_production() ) {
		return;
	}

	update_option( 'safety_net_pause_renewal_actions_toggle', 'on' );
}

/**
 * Determines if options should be scrubbed.
 *
 * Options will be scrubbed if we're on staging, development, or local AND it hasn't already been anonymized.
 */
function maybe_scrub_options() {
	// If options have already been scrubbed, skip.
	if ( get_option( 'safety_net_options_scrubbed' ) ) {
		return;
	}

	// If we're not on staging, development, or a local environment, return.
	if ( is_production() ) {
		return;
	}

	do_action( 'safety_net_scrub_options' );
}

/**
 * Determines if deny-listed plugins should be deactivated.
 *
 * Plugins will be deactivated if we're on staging, development, or local AND it hasn't already been anonymized.
 */
function maybe_deactivate_plugins() {
	// If plugins have already been deactivated, skip.
	if ( get_option( 'safety_net_plugins_deactivated' ) ) {
		return;
	}

	// If we're not on staging, development, or a local environment, return.
	if ( is_production() ) {
		return;
	}

	do_action( 'safety_net_deactivate_plugins' );
}

/**
 * Determines if data should be anonymized.
 *
 * Data will be deleted if we're on staging, development, or local AND it hasn't already been anonymized.
 */
function maybe_delete_data() {
	// If data has already been deleted, skip.
	if ( get_option( 'safety_net_data_deleted' ) ) {
		return;
	}

	// If we're not on staging, development, or a local environment, return.
	if ( is_production() ) {
		return;
	}

	// Fire hooks to let plugin know to delete data.
	do_action( 'safety_net_delete_data' );
}
