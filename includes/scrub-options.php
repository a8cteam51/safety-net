<?php

namespace SafetyNet\ScrubOptions;

use function SafetyNet\Integrations\get_integrations;
use function SafetyNet\Integrations\option_treatment;
use function SafetyNet\Integrations\options_to_clear;
use function SafetyNet\Integrations\run_phase;
use function SafetyNet\Utilities\should_change_network;

add_action( 'safety_net_scrub_options', __NAMESPACE__ . '\scrub_options' );

/*
* Clear options such as API keys so that plugins won't talk to 3rd parties
*/
function scrub_options() {
	global $wpdb;

	if ( should_change_network( 'safety_net_scrub_options', 'safety_net_network_admin_email_scrubbed', 'manage_network_options' ) ) {
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct access intentionally bypasses option hooks.
			$wpdb->sitemeta,
			array( 'meta_value' => 'safetynet@scrubbedthis.option' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			array(
				'site_id'  => get_current_network_id(),
				'meta_key' => 'admin_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		update_site_option( 'safety_net_network_admin_email_scrubbed', true );
	}

	safety_net_update_option_direct( 'admin_email', 'safetynet@scrubbedthis.option' );

	$options_to_clear = options_to_clear();
	$options_to_clear = apply_filters( 'safety_net_options_to_clear', $options_to_clear );

	// Check if it’s an Atomic site either via the Jetpack function or URL.
	$is_atomic_site = false;
	if ( function_exists( 'jetpack_is_atomic_site' ) && jetpack_is_atomic_site() ) {
		$is_atomic_site = true;
	} elseif ( str_ends_with( home_url(), 'wpcomstaging.com' ) ) {
		$is_atomic_site = true;
	}

	// Leave these options intact on Atomic, so that we don't disconnect Jetpack
	if ( $is_atomic_site ) {
		$unset_wpcom_options = array( 'jetpack_private_options', 'jetpack_secrets' );
		$options_to_clear    = array_diff( $options_to_clear, $unset_wpcom_options );
	}

	foreach ( $options_to_clear as $option ) {
		$treatment = option_treatment( $option );
		if ( 'delete' === $treatment['mode'] ) {
			delete_option_directly( $option . '_sn_backup' );
			delete_option_directly( $option );
			continue;
		}

		if ( 'delete_partial' === $treatment['mode'] ) {
			delete_option_directly( $option . '_sn_backup' );
			blank_secrets_directly( $option, $treatment['keys'] );
			continue;
		}

		$option_value = get_option( $option );
		if ( $option_value ) {

			update_option( $option . '_sn_backup', $option_value );

			if ( 'partial' === $treatment['mode'] && is_array( $option_value ) ) {
				safety_net_update_option_direct( $option, scrub_option_keys( $option_value, $treatment['keys'] ) );
			} elseif ( 'value' === $treatment['mode'] ) {
				safety_net_update_option_direct( $option, $treatment['value'] );
			} elseif ( 'default_pingback_flag' === $option ) {
				// Delete all _pingme postmeta to prevent pingbacks from being sent.
				$wpdb->delete(
					$wpdb->postmeta,
					array( 'meta_key' => '_pingme' )
				);

				safety_net_update_option_direct( $option, '' );
			} else {
				// Some plugins don't like it when options are deleted, so we will save their value as either an empty string or array, depending on which it already is.
				if ( is_array( get_option( $option ) ) ) {
					$empty_array = array();
					safety_net_update_option_direct( $option, $empty_array );
				} else {
					safety_net_update_option_direct( $option, '' );
				}
			}
		}
	}

	cancel_integration_actions();
	run_phase( 'scrub' );

	update_option( 'safety_net_options_scrubbed', true );

	// Clear object cache since the updates happen directly in the database.
	wp_cache_flush();
}

/**
 * Blanks the listed keys an option's array value has, and sets the keys given a value, or has a closure scrub the value.
 *
 * @param array          $value The option's value.
 * @param array|\Closure $keys  Keys to blank, and key => value pairs to set, or a closure that returns the scrubbed value.
 * @return array The scrubbed value.
 */
function scrub_option_keys( array $value, array|\Closure $keys ): array {
	if ( $keys instanceof \Closure ) {
		return $keys( $value );
	}

	foreach ( $keys as $key => $new_value ) {
		if ( ! is_int( $key ) ) {
			$value[ $key ] = $new_value;
		} elseif ( array_key_exists( $new_value, $value ) ) {
			$value[ $new_value ] = '';
		}
	}

	return $value;
}

/**
 * Cancels the pending scheduled actions whose hooks match the integrations' patterns.
 *
 * @return void
 */
function cancel_integration_actions() {
	global $wpdb;

	$patterns = array();
	foreach ( get_integrations() as $integration ) {
		$patterns = array_merge( $patterns, $integration->cancel_action_scheduler_hooks );
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct access bypasses Action Scheduler; the table name comes from $wpdb.
	$table_name = $wpdb->prefix . 'actionscheduler_actions';
	if ( ! $patterns || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) !== $table_name ) {
		return;
	}

	foreach ( $patterns as $pattern ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$table_name} SET status = 'canceled' WHERE status = 'pending' AND hook LIKE %s", $pattern ) );
	}
	// phpcs:enable
}

/**
 * Blanks the given keys at any depth of an option's array value without running option hooks.
 *
 * @param string   $option      Option name.
 * @param string[] $secret_keys Keys whose values are secrets.
 * @return void
 */
function blank_secrets_directly( string $option, array $secret_keys ): void {
	global $wpdb;

	$settings = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct access intentionally bypasses option hooks.
	if ( ! is_array( $settings ) ) {
		return;
	}

	$scrubbed = blank_secrets( $settings, $secret_keys );
	if ( $scrubbed !== $settings ) {
		safety_net_update_option_direct( $option, $scrubbed );
		forget_cached_option( $option, false );
	}
}

/**
 * Blanks the given keys at any depth of a plugin's settings.
 *
 * @param array    $settings    The stored settings.
 * @param string[] $secret_keys Keys whose values are secrets.
 * @return array The settings without their secrets.
 */
function blank_secrets( array $settings, array $secret_keys ): array {
	foreach ( $settings as $key => $value ) {
		if ( in_array( $key, $secret_keys, true ) ) {
			$settings[ $key ] = '';
		} elseif ( is_array( $value ) ) {
			$settings[ $key ] = blank_secrets( $value, $secret_keys );
		}
	}

	return $settings;
}

/**
 * Deletes an option without running its hooks.
 *
 * @param string $option Option name.
 * @return void
 */
function delete_option_directly( string $option ): void {
	global $wpdb;

	if ( $wpdb->delete( $wpdb->options, array( 'option_name' => $option ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct access intentionally bypasses option hooks.
		forget_cached_option( $option, true );
	}
}

/**
 * Drops an option changed directly in the database from the object cache, as delete_option() and update_option() do.
 *
 * @param string $option  Option name.
 * @param bool   $deleted Whether the option's row was deleted.
 * @return void
 */
function forget_cached_option( string $option, bool $deleted ): void {
	if ( wp_installing() ) {
		return;
	}

	$alloptions = wp_load_alloptions( true );
	if ( isset( $alloptions[ $option ] ) ) {
		unset( $alloptions[ $option ] );
		wp_cache_set( 'alloptions', $alloptions, 'options' );
	}
	wp_cache_delete( $option, 'options' );

	if ( $deleted ) {
		$notoptions            = wp_cache_get( 'notoptions', 'options' );
		$notoptions            = is_array( $notoptions ) ? $notoptions : array();
		$notoptions[ $option ] = true;
		wp_cache_set( 'notoptions', $notoptions, 'options' );
	}
}

/**
 * Updates options directly in the database to prevent notifications from being sent.
 *
 * @param string $option_name The name of the option to update.
 * @param mixed $option_value The value to set the option to.
 *
 * @return void
 */
function safety_net_update_option_direct( $option_name, $option_value ) {
	global $wpdb;

	if ( is_array( $option_value ) ) {
		$option_value = serialize( $option_value );
	}

	$wpdb->update(
		$wpdb->options,
		array( 'option_value' => $option_value ),
		array( 'option_name' => $option_name ),
	);
}
