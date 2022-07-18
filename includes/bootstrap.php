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

add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_scrub_options' );
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_deactivate_plugins' );
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_anonymize_data' );
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
 * Determines if options should be scrubbed.
 *
 * Options will be scrubbed if we're on staging, development, or local AND it hasn't already been anonymized.
 */
function maybe_scrub_options() {
	// If options have already been scrubbed, skip.
	if ( get_option( 'safety_net_options_scrubbed' ) ) {
		return;
	}

	// If we're not on staging, development, or a local environment, give warning and return.
	if ( ! in_array( wp_get_environment_type(), array( 'staging', 'development', 'local' ), true ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			esc_html__( 'Safety Net plugin should not be run on production! Remove plugin or set WP_ENVIRONMENT_TYPE correctly.', 'safety-net' ),
			'1.0.0-beta.3'
		);
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

	// If we're not on staging, development, or a local environment, give warning and return.
	if ( ! in_array( wp_get_environment_type(), array( 'staging', 'development', 'local' ), true ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			esc_html__( 'Safety Net plugin should not be run on production! Remove plugin or set WP_ENVIRONMENT_TYPE correctly.', 'safety-net' ),
			'1.0.0-beta.3'
		);
		return;
	}

	do_action( 'safety_net_deactivate_plugins' );
}

/**
 * Determines if data should be anonymized.
 *
 * Data will be anonymized if we're on staging, development, or local AND it hasn't already been anonymized.
 */
function maybe_anonymize_data() {
	// If data has already been anonymized, skip.
	if ( get_option( 'safety_net_anonymized' ) ) {
		return;
	}

	// If we're not on staging, development, or a local environment, give warning and return.
	if ( ! in_array( wp_get_environment_type(), array( 'staging', 'development', 'local' ), true ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			esc_html__( 'Safety Net plugin should not be run on production! Remove plugin or set WP_ENVIRONMENT_TYPE correctly.', 'safety-net' ),
			'1.0.0-beta.3'
		);
		return;
	}

	// Fire hooks to let plugin know to anonymize data.
	do_action( 'safety_net_anonymize_data' );
}