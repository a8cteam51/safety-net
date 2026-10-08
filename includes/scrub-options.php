<?php

namespace SafetyNet\ScrubOptions;

use WC_Data_Store;
use WC_Webhook;

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
			} elseif ( is_array( $option_value ) && ( 'woocommerce_ppcp-gateway_settings' === $option || 'woocommerce-ppcp-settings' === $option || 'woocommerce_stripe_settings' === $option ) ) {
				// we need to more selectively wipe parts of these options, because the respective plugins will fatal if the entire options are blank
				$keys_to_scrub = array( 'enabled', 'client_secret_production', 'client_id_production', 'client_secret', 'client_id', 'merchant_id', 'merchant_email', 'merchant_id_production', 'merchant_email_production', 'publishable_key', 'secret_key', 'webhook_secret' );
				$option_array  = $option_value;
				foreach ( $keys_to_scrub as $key ) {
					if ( array_key_exists( $key, $option_array ) ) {
						$option_array[ $key ] = '';
					}
				}
				safety_net_update_option_direct( $option, $option_array );
			} elseif ( 'jetpack_active_modules' === $option && is_array( $option_value ) ) {
				// Clear some Jetpack options to disable specific modules.
				$modules_to_disable = array( 'enhanced-distribution', 'publicize', 'subscriptions' );
				$modules_array      = array_filter(
					$option_value,
					function( $v ) use ( $modules_to_disable ) {
						return ! in_array( $v, $modules_to_disable, true );
					},
				);

				safety_net_update_option_direct( $option, $modules_array );
			} elseif ( 'wprus' === $option && is_array( $option_value ) ) {
				// Clear some WP Remote Users Sync options to disable only keys needed for remote connections and keep the remaining settings intact.
				$keys_to_scrub = array(
					'encryption' => array(
						'aes_key',
						'hmac_key',
					),
				);
				$option_array  = $option_value;
				foreach ( $keys_to_scrub as $index => $keys ) {
					if ( array_key_exists( $index, $option_array ) && is_array( $option_array[ $index ] ) ) {
						foreach ( $keys as $key ) {
							if ( array_key_exists( $key, $option_array[ $index ] ) ) {
								$option_array[ $index ][ $key ] = '';
							}
						}
					}
				}
				safety_net_update_option_direct( $option, $option_array );
			} elseif ( 'pmpro_gateway' === $option ) {
				safety_net_update_option_direct( $option, '' );
			} elseif ( 'pmpro_gateway_environment' === $option ) {
				safety_net_update_option_direct( $option, 'sandbox' );
			} elseif ( 'pmpro_last_known_url' === $option ) {
				safety_net_update_option_direct( $option, 'https://safetynetscrubbedthis.com' );
				if ( function_exists( 'pmpro_clear_crons' ) ) {
					pmpro_clear_crons();
				}
			} else if ( '_wp_convertkit_settings' === $option && is_array( $option_value ) ) {
				$option_array  = $option_value;

				$keys_to_scrub = array( 'access_token', 'refresh_token', 'token_expires', 'api_key', 'api_secret' );
				foreach ( $keys_to_scrub as $key ) {
					if ( array_key_exists( $key, $option_array ) ) {
						$option_array[ $key ] = '';
					}
				}

				safety_net_update_option_direct( $option, $option_array );
			} elseif ( 'apple_news_settings' === $option && is_array( $option_value ) ) {
				$keys_to_scrub = array( 'api_key', 'api_secret', 'api_channel', 'apple_news_admin_email' );

				$option_array = $option_value;
				foreach ( $keys_to_scrub as $key ) {
					if ( array_key_exists( $key, $option_array ) ) {
						$option_array[ $key ] = '';
					}
				}

				$option_array['api_autosync'] = 'no';
				$option_array['api_autosync_update']  = 'no';
				$option_array['api_autosync_trash'] = 'no';
				$option_array['api_autosync_delete']  = 'no';
				$option_array['api_autosync_unpublish'] = 'no';

				$option_array['apple_news_enable_debugging'] = 'no';

				safety_net_update_option_direct( $option, $option_array );
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

	// Disable all Woo Webhooks
	if ( class_exists( 'WooCommerce' ) ) {
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

	// Disable AutomateWoo workflows, clear the queue, and set scheduled actions to "canceled".
	$wpdb->query( "UPDATE $wpdb->posts SET post_status = 'aw-disabled' WHERE post_type = 'aw_workflow' AND post_status = 'publish'" );

	$table_name = $wpdb->prefix . 'automatewoo_queue';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}automatewoo_queue" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}automatewoo_queue_meta" );
	}

	$table_name = $wpdb->prefix . 'actionscheduler_actions';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "UPDATE {$wpdb->prefix}actionscheduler_actions SET status = 'canceled' WHERE status = 'pending' AND hook LIKE '%automatewoo%'" );
	}

	cancel_integration_actions();
	run_phase( 'scrub' );

	update_option( 'safety_net_options_scrubbed', true );

	// Clear object cache since the updates happen directly in the database.
	wp_cache_flush();
}

/**
 * Blanks the listed keys an option's array value has, and sets the keys given a value.
 *
 * @param array $value The option's value.
 * @param array $keys  Keys to blank, and key => value pairs to set.
 * @return array The scrubbed value.
 */
function scrub_option_keys( array $value, array $keys ): array {
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
